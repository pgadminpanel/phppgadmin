<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Gui\QueryExportRenderer;
use PhpPgAdmin\Gui\ExportOutputRenderer;
use PhpPgAdmin\Database\Cursor\CursorReader;
use PhpPgAdmin\Database\Export\FormatterFactory;
use PhpPgAdmin\Database\Export\Compression\CompressionFactory;

/**
 * Export query results to various formats (SQL, CSV, XML, HTML, JSON, etc.)
 * Uses unified OutputFormatter infrastructure for consistent format handling.
 *
 * $Id: dataexport.php,v 1.26 2007/07/12 19:26:22 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Generate a descriptive filename for the query export.
 */
function generateQueryExportFilename($table = null, $schema = null)
{
	$timestamp = date('Ymd_His');
	if ($schema !== null && $table !== null) {
		$prefix = $schema . '_' . $table;
	} else {
		$prefix = 'query_export';
	}
	return $prefix . '_' . $timestamp;
}

function processQueryExport()
{
	$pg = AppContainer::getPostgres();
	//$misc = AppContainer::getMisc();
	//$lang = AppContainer::getLang();

	// Prevent timeouts on large exports (non-safe mode only)
	if (!ini_get('safe_mode'))
		set_time_limit(0);

	ini_set('html_errors', '0');

	//AppContainer::setSkipHtmlFrame(true);

	// Get unified parameters
	$output_format = $_REQUEST['output_format'] ?? 'csv';
	$insert_format = $_REQUEST['insert_format'] ?? 'copy';

	// Parse composite `output` parameter: 'show' | 'download' | 'download-gzip' | 'download-bzip2' | 'download-zip'
	$rawOutput = $_REQUEST['output'] ?? 'download';
	if ($rawOutput === 'show') {
		$output_method = 'show';
		$output_compression = '';
	} else {
		$output_method = 'download';
		$compression_suffix = str_replace('download-', '', $rawOutput);
		$output_compression = $compression_suffix ?: 'plain';
	}

	// Get the query to export
	$query = $_REQUEST['query'] ?? ($_SESSION['sqlquery'] ?? '');
	if (empty($query)) {
		header('HTTP/1.0 400 Bad Request');
		echo "Error: No query provided for export.";
		exit;
	}

	// Validate format is supported for query exports
	try {
		$formatter = FormatterFactory::create($output_format);
	} catch (\Exception $e) {
		header('HTTP/1.0 400 Bad Request');
		echo "Error: Invalid export format: " . htmlspecialchars($output_format);
		exit;
	}

	$table = $_REQUEST['table'] ?? $_REQUEST['view'] ?? null;
	$schema = $pg->_schema;

	// Set up download headers and output handling
	$filename = generateQueryExportFilename($table, $schema);
	$mime_type = $formatter->getMimeType();
	$file_extension = $formatter->getFileExtension();

	if ($output_method === 'show') {
		// For browser display, use unified HTML wrapper
		ExportOutputRenderer::beginHtmlOutput(['mode' => $output_format]);
		$output_stream = fopen('php://output', 'w');
		stream_filter_append($output_stream, 'pg.htmlencode.filter', STREAM_FILTER_WRITE);
	} else {
		// For all other output methods (download with optional compression), use CompressionFactory
		try {
			$strategy = CompressionFactory::create($output_compression);
			if (!$strategy) {
				die("Error: Unsupported output method: " . htmlspecialchars($output_compression));
			}
			$handle = $strategy->begin("$filename.$file_extension");
			$output_stream = $handle['stream'];
		} catch (\Exception $e) {
			die("Error: " . htmlspecialchars($e->getMessage()));
		}
	}

	// Build metadata for formatter
	$metadata = [
		'insert_format' => $insert_format, // For SQL formatter
		'export_nulls' => $_REQUEST['export_nulls'] ?? '',
		'bytea_encoding' => $_REQUEST['bytea_encoding'] ?? 'hex',
		'column_names' => isset($_REQUEST['column_names']), // For CSV/TSV formatter
	];
	if ($table !== null) {
		$metadata['table'] = $pg->quoteIdentifier($schema) . '.' . $pg->quoteIdentifier($table);
	}

	// Stream output directly using the formatter
	// Pass stream to formatter for memory-efficient processing
	$formatter->setOutputStream($output_stream);

	try {
		// Create cursor reader with automatic chunk size calculation
		$reader = new CursorReader(
			$pg,
			$query,
			null, // Auto-calculate chunk size
			$table,
			$schema
		);

		// Open cursor (begins transaction)
		$reader->open();

		// Process rows and output using formatter
		$reader->processRows($formatter, $metadata);

		// Close cursor (commits transaction)
		$reader->close();

	} catch (\Exception $e) {
		error_log('Error dumping table data: ' . $e->getMessage());
		die("Error exporting data: " . htmlspecialchars($e->getMessage()));
	}


	// Close streams for download and compressed output
	if ($output_method !== 'show' && isset($strategy) && isset($handle)) {
		$strategy->finish($handle);
	} elseif ($output_method === 'show') {
		ExportOutputRenderer::endHtmlOutput();
	}
}

function showQueryExportForm()
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	// If not an export action, display the export form
	// Get query from request or session
	$query = $_REQUEST['query'] ?? ($_SESSION['sqlquery'] ?? '');

	if (empty($query)) {
		header('HTTP/1.0 400 Bad Request');
		echo "Error: No query provided for export.";
		exit;
	}
	// Display the query export form
	$misc->printHeader($lang['strexport']);
	$misc->printBody();
	$misc->printTrail($_REQUEST['subject'] ?? 'database');
	$misc->printTitle($lang['strexport']);

	if (isset($msg))
		$misc->printMsg($msg);

	// Render the export form using QueryExportRenderer
	$renderer = new QueryExportRenderer();
	$renderer->renderExportForm($query, $_REQUEST);

	$misc->printFooter();
}

// Main program

$action = $_REQUEST['action'] ?? '';

switch ($action) {
	case 'export':
		processQueryExport();
		break;
	default:
		showQueryExportForm();
		break;
}
