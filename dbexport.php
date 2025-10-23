<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Gui\ExportOutputRenderer;
use PhpPgAdmin\Database\Dump\DumpFactory;
use PhpPgAdmin\Database\Export\DumpManager;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Database\Export\Compression\CompressionFactory;

/**
 * Does an export of a database, schema, table, or view
 * (via internal PHP dumper or pg_dump fallback).
 * Uses DumpFactory internally; pg_dump is used only if explicitly requested
 * via $_REQUEST['dumper'].
 * Streams output to screen or downloads file.
 *
 * $Id: dbexport.php,v 1.22 2007/03/25 03:15:09 xzilla Exp $
 */

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);

include_once('./libraries/bootstrap.php');

// Include application functions
AppContainer::setSkipHtmlFrame(true);

$pg = AppContainer::getPostgres();
$misc = AppContainer::getMisc();

$subjectObjectMap = [
	'server' => 'database',
	'database' => 'schema',
	'schema' => 'table',
];

// Parameter handling
// DumpRenderer uses output_format (sql/csv/tab/html/xml/json) and insert_format (copy/multi/single for SQL only)
$output_format = $_REQUEST['output_format'] ?? 'sql';
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

// Determine dumper selection
$dumper = $_REQUEST['dumper'] ?? 'internal';
$use_pg_dumpall = ($dumper === 'pg_dumpall');
$use_pgdump = ($dumper === 'pgdump');
$use_internal = !$use_pg_dumpall && !$use_pgdump;
$filename_base = generateDumpFilename($_REQUEST['subject'], $_REQUEST);
$filename_base .= ".sql";

// Disable HTML errors for clean output
ini_set('html_errors', '0');

// ============================================================================
// INTERNAL PHP DUMPER PATH
// ============================================================================
if ($use_internal) {
	$subject = $_REQUEST['subject'] ?? 'database';
	$params = [
		'database' => $_REQUEST['database'] ?? null,
		'schema' => $_REQUEST['schema'] ?? null,
		//'table' => $_REQUEST['table'] ?? null,
		//'view' => $_REQUEST['view'] ?? null,
	];
	// Determine the actual export format based on output_format and insert_format
	// output_format = sql/csv/tab/html/xml/json
	// insert_format = copy/multi/single (only for SQL format)
	$export_format = ($insert_format === 'copy') ? 'copy' : 'sql';

	$options = [
		'format' => $export_format,
		'drop_objects' => isset($_REQUEST['drop_objects']),
		'if_not_exists' => isset($_REQUEST['if_not_exists']),
		'include_comments' => isset($_REQUEST['include_comments']),
		'export_roles' => isset($_REQUEST['export_roles']),
		'export_tablespaces' => isset($_REQUEST['export_tablespaces']),
		'structure_only' => ($_REQUEST['what'] === 'structureonly'),
		'data_only' => ($_REQUEST['what'] === 'dataonly'),
		'export_all_objects' => isset($_REQUEST['export_all_objects']),
		'objects' => isset($_REQUEST['export_all_objects']) ? null : ($_REQUEST['objects'] ?? []),
		'include_schema_objects' => isset($_REQUEST['include_schema_objects']),
		'insert_format' => $insert_format,
		'truncate_tables' => isset($_REQUEST['truncate_tables']),
		'add_create_database' => isset($_REQUEST['add_create_database']),
		'add_create_schema' => isset($_REQUEST['add_create_schema']),
		'no_owner' => isset($_REQUEST['no_owner']),
		'no_privileges' => isset($_REQUEST['no_privileges']),
	];

	// Set response headers and open output stream
	if ($output_method === 'show') {
		ExportOutputRenderer::beginHtmlOutput();
		$output_stream = fopen('php://output', 'w');
		stream_filter_append($output_stream, 'pg.htmlencode.filter', STREAM_FILTER_WRITE);
	} else {
		// Use CompressionFactory for the requested compression (default to 'download')
		try {
			$strategy = CompressionFactory::create($output_compression);
			if (!$strategy) {
				die("Error: Unsupported output method: " . htmlspecialchars($output_compression));
			}
			$handle = $strategy->begin($filename_base);
			$output_stream = $handle['stream'];
		} catch (\Exception $e) {
			die("Error: " . htmlspecialchars($e->getMessage()));
		}
	}

	// Reset search_path to avoid issues with schema-scoped exports
	$pg->execute("SELECT pg_catalog.set_config('search_path', '', false)");

	// Execute dump via internal dumper
	// Note: Dumpers handle their own transaction management (beginDump/endDump)
	$dumper = DumpFactory::create($subject, $pg);
	if ($output_stream !== null) {
		$dumper->setOutputStream($output_stream);
	}

	// Dump database/schema/table/etc
	$dumper->dump($subject, $params, $options);

	// Handle output stream closing
	if ($output_method !== 'show' && isset($strategy) && isset($handle)) {
		$strategy->finish($handle);
	} elseif ($output_method === 'show') {
		// Close HTML output for show mode
		ExportOutputRenderer::endHtmlOutput();
	}

	exit;
}


// ============================================================================
// EXTERNAL PG_DUMP/PG_DUMPALL PATH (fallback only)
// ============================================================================

// Handle database selection for server exports
$selected_databases = [];

// Clear cached dump executable detection to force re-check
unset($_SESSION['dump_executable_pg_dump']);
unset($_SESSION['dump_executable_pg_dumpall']);

// ============================================================================
// HANDLE PG_DUMPALL (full cluster mode)
// ============================================================================
if ($use_pg_dumpall) {
	// pg_dumpall: always full cluster, no options
	$pg_dumpall_path = DumpManager::getDumpExecutable(true);
	if (!$pg_dumpall_path) {
		echo "Error: Could not find pg_dumpall executable.\n";
		exit;
	}

	$server_info = $misc->getServerInfo();
	setupPgEnvironment($server_info);

	// Build command with connection parameters
	$pg_dumpall = $misc->escapeShellCmd($pg_dumpall_path);
	$cmd = buildDumpCommandWithConnectionParams($pg_dumpall, $server_info);

	// Add format options based on request
	$cmd = addPgDumpFormatOptions($cmd, $insert_format);

	// Execute based on output method
	try {
		if ($output_method === 'show') {
			ExportOutputRenderer::beginHtmlOutput();
			$output_stream = fopen('php://output', 'w');
			stream_filter_append($output_stream, 'pg.htmlencode.filter', STREAM_FILTER_WRITE);
		} else {
			$strategy = CompressionFactory::create($output_compression);
			if (!$strategy) {
				die("Error: Unsupported output method: " . htmlspecialchars($output_compression));
			}
			$handle = $strategy->begin($filename_base);
			$output_stream = $handle['stream'];
		}
		checkAndWarnVersionMismatch($pg_dumpall, 'pg_dumpall', $server_info, $output_stream);
		execute_dump_command_streaming($cmd, $output_stream);
		if ($output_method === 'show') {
			ExportOutputRenderer::endHtmlOutput();
		} else {
			$strategy->finish($handle);
		}
	} catch (\Exception $e) {
		die("Error: " . htmlspecialchars($e->getMessage()));
	}
	exit;
}

// If not pg_dumpall, handle pg_dump logic
// Smart: if pg_dump selected AND all databases selected AND roles/tablespaces
// AND structure+data then use pg_dumpall instead for efficiency
if ($use_pgdump) {
	$subject = $_REQUEST['subject'] ?? 'database';
	$filtering_enabled = !isset($_REQUEST['export_all_objects']);
	$selected_objects = $_REQUEST['objects'] ?? [];
	$what = $_REQUEST['what'] ?? 'structureanddata';
	$export_roles = isset($_REQUEST['export_roles']);
	$export_tablespaces = isset($_REQUEST['export_tablespaces']);

	// When exporting at server scope with pg_dump, user must pick databases only if filtering is enabled
	if ($subject === 'server' && $filtering_enabled && empty($selected_objects)) {
		if ($output_method === 'show') {
			ExportOutputRenderer::beginHtmlOutput();
			echo htmlspecialchars("-- No databases selected for export.\n");
			ExportOutputRenderer::endHtmlOutput();
		} else {
			echo "-- No databases selected for export.\n";
		}
		exit;
	}

	// Only server exports support multi-database selection with pg_dump
	if ($subject !== 'server') {
		goto pg_dumpall_export;
	}

	$selected_dbs = $filtering_enabled ? $selected_objects : [];

	// Check if all non-template databases are selected
	$databaseActions = new DatabaseActions($pg);
	$all_dbs = $databaseActions->getDatabases(null, true);
	$all_dbs->moveFirst();
	$all_db_list = [];
	while ($all_dbs && !$all_dbs->EOF) {
		$dname = $all_dbs->fields['datname'];
		if (strpos($dname, 'template') !== 0) {
			$all_db_list[] = $dname;
		}
		$all_dbs->moveNext();
	}
	if (empty($selected_dbs) && !$filtering_enabled) {
		$selected_dbs = $all_db_list;
	}

	sort($selected_dbs);
	sort($all_db_list);
	$allDbsSelected = ($selected_dbs === $all_db_list && !empty($selected_dbs));

	// Smart logic: use pg_dumpall if all DBs + roles + tablespaces + structure+data
	if ($allDbsSelected && $export_roles && $export_tablespaces && $what === 'structureanddata') {
		// Switch to pg_dumpall for efficiency
		$pg_dumpall_path = DumpManager::getDumpExecutable(true);
		if ($pg_dumpall_path) {
			$use_pgdump = false;
			$use_pg_dumpall = true;
			// Re-run the pg_dumpall block
			goto pg_dumpall_export;
		}
	}

	// Otherwise, continue with pg_dump for filtered databases
	$selected_databases = $selected_dbs;
}

// If databases were selected in a server export, use pg_dump for each
// instead of pg_dumpall (which cannot be filtered)
if ($use_pgdump && (($_REQUEST['subject'] ?? 'database') === 'server') && !empty($selected_databases)) {
	$exe_path_pgdump = DumpManager::getDumpExecutable(false);
	if (!$exe_path_pgdump) {
		echo "Error: Could not find pg_dump executable.\n";
		exit;
	}

	$exe = $misc->escapeShellCmd($exe_path_pgdump);
	$server_info = $misc->getServerInfo();
	setupPgEnvironment($server_info);

	// Build base command with connection parameters
	$base_cmd = buildDumpCommandWithConnectionParams($exe, $server_info);

	// Build per-database pg_dump commands
	$db_commands = [];
	$db_names = [];
	foreach ($selected_databases as $db_name) {
		$pg->fieldClean($db_name);
		$db_cmd = $base_cmd . ' ' . $misc->escapeShellArg($db_name);
		$db_cmd = addPgDumpFormatOptions($db_cmd, $insert_format);
		$db_commands[] = $db_cmd;
		$db_names[] = $db_name;
	}
	$cmd = $db_commands;

	// Set headers and execute
	try {
		if ($output_method === 'show') {
			ExportOutputRenderer::beginHtmlOutput();
			$output_stream = fopen('php://output', 'w');
			stream_filter_append($output_stream, 'pg.htmlencode.filter', STREAM_FILTER_WRITE);
		} else {
			$strategy = CompressionFactory::create($output_compression);
			if (!$strategy) {
				die("Error: Unsupported output method: " . htmlspecialchars($output_compression));
			}
			$handle = $strategy->begin($filename_base);
			$output_stream = $handle['stream'];
		}

		// Check version and warn if needed
		checkAndWarnVersionMismatch($exe, 'pg_dump', $server_info, $output_stream);

		foreach ($cmd as $idx => $db_cmd) {
			$dbName = $db_names[$idx] ?? null;
			if ($dbName !== null) {
				$header = "--\n-- Dumping database: \"" . addslashes($dbName) . "\"\n--\n\\connect \"" . addslashes($dbName) . "\"\n\\encoding UTF8\nSET client_encoding = 'UTF8';\nSET session_replication_role = 'replica';\n\n";
				fwrite($output_stream, $header);
			}
			execute_dump_command_streaming($db_cmd, $output_stream);
			fwrite($output_stream, "SET session_replication_role = 'origin';\n\n");
			fflush($output_stream);
		}
		if ($output_method === 'show') {
			ExportOutputRenderer::endHtmlOutput();
		} else {
			$strategy->finish($handle);
		}
	} catch (\Exception $e) {
		die("Error: " . htmlspecialchars($e->getMessage()));
	}
	exit;
}

pg_dumpall_export:

// Fallback: handle single database or table/view export with pg_dump
if ($use_pgdump) {
	$exe_path = DumpManager::getDumpExecutable(false);
	if (!$exe_path) {
		echo "Error: Could not find pg_dump executable.\n";
		exit;
	}

	$exe = $misc->escapeShellCmd($exe_path);

	// Obtain the pg_dump version number
	$version = [];
	$version_output = shell_exec("$exe --version 2>&1");
	if (!$version_output) {
		echo "Error: Could not execute " . htmlspecialchars($exe_path) . "\n";
		echo "The executable exists but could not be run. Please check permissions.\n";
		exit;
	}

	preg_match("/(\d+(?:\.\d+)?)(?:\.\d+)?.*$/", trim($version_output), $version);

	if (empty($version)) {
		echo "Error: Could not determine pg_dump version.\n";
		echo "Output: " . htmlspecialchars($version_output) . "\n";
		exit;
	}

	// Get server connection info
	$server_info = $misc->getServerInfo();

	// Setup environment and build command
	setupPgEnvironment($server_info);
	$base_cmd = buildDumpCommandWithConnectionParams($exe, $server_info);

	// Single command mode for single database/table/schema
	$cmd = $base_cmd;

	// Schema/table handling
	$f_schema = '';
	$f_object = '';
	$filtering_enabled = !isset($_REQUEST['export_all_objects']);

	if (isset($_REQUEST['schema'])) {
		$f_schema = $_REQUEST['schema'];
		$pg->fieldClean($f_schema);
	}

	// Check for a specified table/view
	switch ($_REQUEST['subject']) {
		case 'database':
			// Database export (optionally filtered to selected schemas)
			$selected_schemas = isset($_REQUEST['objects']) ? array_values((array) $_REQUEST['objects']) : [];
			if ($filtering_enabled) {
				if (empty($selected_schemas)) {
					if ($output_method === 'show') {
						ExportOutputRenderer::beginHtmlOutput();
						echo htmlspecialchars("-- No schemas selected for export.\n");
						ExportOutputRenderer::endHtmlOutput();
					} else {
						echo "-- No schemas selected for export.\n";
					}
					exit;
				}
				foreach ($selected_schemas as $schemaName) {
					$pg->fieldClean($schemaName);
					$cmd .= " -n " . $misc->escapeShellArg("\"{$schemaName}\"");
				}
			}
			break;
		case 'schema':
			// Schema export (optionally filtered to selected tables/views/sequences)
			$selected_tables = isset($_REQUEST['objects']) ? array_values((array) $_REQUEST['objects']) : [];
			if ($filtering_enabled) {
				if (empty($selected_tables)) {
					if ($output_method === 'show') {
						ExportOutputRenderer::beginHtmlOutput();
						echo htmlspecialchars("-- No objects selected for export.\n");
						ExportOutputRenderer::endHtmlOutput();
					} else {
						echo "-- No objects selected for export.\n";
					}
					exit;
				}
				foreach ($selected_tables as $tableName) {
					$pg->fieldClean($tableName);
					$cmd .= " -t " . $misc->escapeShellArg("\"{$f_schema}\".\"{$tableName}\"");
				}
			} else {
				$cmd .= " -n " . $misc->escapeShellArg("\"{$f_schema}\"");
			}
			break;
		case 'table':
		case 'view':
			// Table or view export (currently unused)
			$f_object = $_REQUEST[$_REQUEST['subject']];
			$pg->fieldClean($f_object);
			$cmd .= " -t " . $misc->escapeShellArg("\"{$f_schema}\".\"{$f_object}\"");
			break;
	}

	// Add format options based on request
	$cmd = addPgDumpFormatOptions($cmd, $insert_format);

	// Set database for single database export
	if (isset($_REQUEST['database'])) {
		putenv('PGDATABASE=' . $_REQUEST['database']);
	}

	// Set headers for gzipped/download/show and execute
	try {
		if ($output_method === 'show') {
			ExportOutputRenderer::beginHtmlOutput();
			$output_stream = fopen('php://output', 'w');
			stream_filter_append($output_stream, 'pg.htmlencode.filter', STREAM_FILTER_WRITE);
		} else {
			$strategy = CompressionFactory::create($output_compression);
			if (!$strategy) {
				die("Error: Unsupported output method: " . htmlspecialchars($output_compression));
			}
			$handle = $strategy->begin($filename_base);
			$output_stream = $handle['stream'];
		}
		// Check version and warn if needed
		checkAndWarnVersionMismatch($exe, 'pg_dump', $server_info, $output_stream);

		$targetDb = $_REQUEST['database'] ?? null;
		if ($targetDb) {
			$header = "--\n-- Dumping database: \"" . addslashes($targetDb) . "\"\n--\n\\connect \"" . addslashes($targetDb) . "\"\n\\encoding UTF8\nSET client_encoding = 'UTF8';\nSET session_replication_role = 'replica';\n\n";
			fwrite($output_stream, $header);
		}
		execute_dump_command_streaming($cmd, $output_stream);
		fwrite($output_stream, "SET session_replication_role = 'origin';\n\n");
		fflush($output_stream);
		if ($output_method === 'show') {
			ExportOutputRenderer::endHtmlOutput();
		} else {
			$strategy->finish($handle);
		}
	} catch (\Exception $e) {
		die("Error: " . htmlspecialchars($e->getMessage()));
	}
	exit;
}

// Helper function to stream pg_dump output directly to a gzipped stream
// This pipes pg_dump → PHP → gzip filter → browser with no buffering
// Based on colleague's guidance for true streaming without memory overhead
function execute_dump_command_streaming($command, $output_stream)
{
	$descriptors = [
		0 => ['pipe', 'r'],  // stdin (not used)
		1 => ['pipe', 'w'],  // stdout (pg_dump output)
		2 => ['pipe', 'w'],  // stderr (errors, ignored)
	];
	$process = proc_open($command, $descriptors, $pipes);
	if (!is_resource($process)) {
		fwrite(STDERR, "ERROR: Could not execute pg_dump command\n");
		return;
	}

	fclose($pipes[0]); // Close stdin

	// Stream pg_dump output directly to the gzipped stream
	// Read in 32KB chunks: small enough to not overwhelm memory, large enough for efficiency
	while (!feof($pipes[1])) {
		$chunk = fread($pipes[1], 32768);
		if ($chunk === false || $chunk === '') {
			break;
		}
		// Write directly to gzipped stream (zlib.deflate filter compresses on-the-fly)
		fwrite($output_stream, $chunk);
	}

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);
}

/**
 * Set up PostgreSQL environment variables for connection.
 * Called by both pg_dump and pg_dumpall.
 */
function setupPgEnvironment($server_info)
{
	putenv('PGPASSWORD=' . $server_info['password']);
	putenv('PGUSER=' . $server_info['username']);
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		putenv('PGHOST=' . $server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		putenv('PGPORT=' . $server_info['port']);
	}
}

/**
 * Build connection parameters for pg_dump/pg_dumpall command.
 * Returns the base command with host, port, and user flags.
 */
function buildDumpCommandWithConnectionParams($exe_path, $server_info)
{
	$cmd = $exe_path;
	if ($server_info['host'] !== null && $server_info['host'] !== '') {
		$cmd .= ' -h ' . AppContainer::getMisc()->escapeShellArg($server_info['host']);
	}
	if ($server_info['port'] !== null && $server_info['port'] !== '') {
		$cmd .= ' -p ' . intval($server_info['port']);
	}
	if ($server_info['username'] !== null && $server_info['username'] !== '') {
		$cmd .= ' -U ' . AppContainer::getMisc()->escapeShellArg($server_info['username']);
	}
	return $cmd;
}

/**
 * Add format and structure options to pg_dump command.
 * Appends flags for data-only, structure-only, INSERT format, and DROP IF EXISTS.
 */
function addPgDumpFormatOptions($cmd, $insert_format)
{
	switch ($_REQUEST['what']) {
		case 'dataonly':
			$cmd .= ' -a';
			if ($insert_format !== 'copy') {
				$cmd .= ' --inserts';
				if ($insert_format === 'multi') {
					$cmd .= ' --rows-per-insert=1000';
				}
			}
			break;
		case 'structureonly':
			$cmd .= ' -s';
			if (isset($_REQUEST['drop_objects'])) {
				$cmd .= ' -c';
			}
			break;
		default:
		case 'structureanddata':
			if ($insert_format !== 'copy') {
				$cmd .= ' --inserts';
				if ($insert_format === 'multi') {
					$cmd .= ' --rows-per-insert=1000';
				}
			}
			if (isset($_REQUEST['drop_objects'])) {
				$cmd .= ' -c';
			}
			break;
	}
	if (!isset($_REQUEST['include_comments'])) {
		$cmd .= ' --no-comments';
	}
	if (isset($_REQUEST['no_owner'])) {
		$cmd .= ' --no-owner';
	}
	if (isset($_REQUEST['no_privileges'])) {
		$cmd .= ' --no-privileges';
	}
	return $cmd;
}

/**
 * Check pg_dump/pg_dumpall version and warn if older than server version.
 * Outputs SQL comment warning if version mismatch detected.
 * Warning appears in the actual dump (both in browser and downloaded file).
 */
function checkAndWarnVersionMismatch($exe_path, $exe_name, $server_info, $output_stream)
{
	$version_output = shell_exec($exe_path . ' --version 2>&1');
	if (!$version_output) {
		return null;
	}

	$version = [];
	preg_match("/(\d+(?:\.\d+)?)(?:\.\d+)?.*$/", trim($version_output), $version);

	if (empty($version)) {
		return null;
	}

	$dump_version = floatval($version[1]);
	$server_version = floatval($server_info['pgVersion'] ?? 0);

	if ($server_version > 0 && $dump_version < $server_version) {
		$output_stream->write("-- WARNING: $exe_name version ($dump_version) is older than PostgreSQL server version ($server_version)\n");
		$output_stream->write("-- Some advanced features may be limited or the dump may be incomplete. Consider using the internal dumper.\n\n");
	}

	return $version[1] ?? '';
}

/**
 * Generate a descriptive filename for the dump.
 * Shared logic used by both dataexport.php and dbexport.php.
 */
function generateDumpFilename($subject, $request)
{
	$timestamp = date('Ymd_His');
	$filename_base = 'dump_' . $timestamp;
	$filename_parts = [];

	// Add database name if available
	if (isset($request['database']) && $subject !== 'server') {
		$filename_parts[] = $request['database'];
	}

	// Add schema name if available
	if (isset($request['schema'])) {
		$filename_parts[] = $request['schema'];
	}

	// Add table or view name if available
	if (isset($request['table'])) {
		$filename_parts[] = $request['table'];
	} elseif (isset($request['view'])) {
		$filename_parts[] = $request['view'];
	}

	// Add export type shorthand if available
	if (isset($request['what'])) {
		$what_map = [
			'dataonly' => 'data',
			'structureonly' => 'struct',
			'structureanddata' => 'full'
		];
		$what_short = $what_map[$request['what']] ?? 'export';
		$filename_parts[] = $what_short;
	}

	// Build final filename
	if (!empty($filename_parts)) {
		$filename_base .= '_' . implode('_', array_map(
			function ($v) {
				return preg_replace('/[^a-zA-Z0-9_-]/', '', $v);
			},
			$filename_parts
		));
	}

	return $filename_base;
}
