<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Import\Data\DataImportExecutor;
use PhpPgAdmin\Database\Import\LogCollector;

// Streaming data import endpoint for table-scoped CSV/TSV/XML imports

if (!isset($_REQUEST['server'])) {
	header('Content-Type: application/json');
	http_response_code(400);
	echo json_encode(['error' => 'server parameter required']);
	exit;
}

require_once __DIR__ . '/libraries/bootstrap.php';

function handle_process_chunk(): void
{
	header('Content-Type: application/json');

	ini_set('html_errors', '0');

	/**
	 * Detects whether input is JSON, XML, CSV or TSV.
	 * Works on partial/chunked input.
	 *
	 * @param string $chunk  First chunk or any chunk with structural hints
	 * @return string|null   "json" | "xml" | "csv" | "tsv" | null
	 */
	$detectFormat = function (string $chunk): ?string {
		$trim = ltrim($chunk);

		// --- JSON detection ---
		// JSON always starts with { or [
		if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
			return 'json';
		}

		// --- XML detection ---
		// XML starts with <tag or <?xml
		if (str_starts_with($trim, '<')) {
			return 'xml';
		}

		// --- CSV/TSV detection ---
		// Heuristics: look at first non-empty line
		$lines = preg_split('/\r\n|\n|\r/', $chunk);
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '')
				continue;

			// TSV: tab-separated
			if (strpos($line, "\t") !== false) {
				return 'tsv';
			}

			// CSV: comma-separated (but avoid JSON arrays)
			if (strpos($line, ',') !== false) {
				return 'csv';
			}

			break;
		}

		return null;
	};

	try {
		$misc = AppContainer::getMisc();
		$pg = AppContainer::getPostgres();

		$schema = $_REQUEST['schema'] ?? '';
		$subject = $_REQUEST['subject'] ?? $_REQUEST['scope'] ?? '';
		$table = $_REQUEST[$subject] ?? $_REQUEST['scope_ident'] ?? '';
		if ($schema === '' || $table === '') {
			http_response_code(400);
			echo json_encode(['error' => 'schema and table parameters required']);
			return;
		}

		$importSessionId = $_REQUEST['import_session_id'] ?? '';
		if ($importSessionId === '') {
			http_response_code(400);
			echo json_encode(['error' => 'import_session_id parameter required']);
			return;
		}

		$baseOffset = isset($_REQUEST['offset']) ? (int) $_REQUEST['offset'] : 0;
		$remainderLen = isset($_REQUEST['remainder_len']) ? max(0, (int) $_REQUEST['remainder_len']) : 0;
		$eof = !empty($_REQUEST['eof']);

		if (!isset($_SESSION['table_import'])) {
			$_SESSION['table_import'] = [];
		}
		if (!isset($_SESSION['table_import'][$importSessionId])) {
			$_SESSION['table_import'][$importSessionId] = [];
		}
		$state = &$_SESSION['table_import'][$importSessionId];

		// Validate session/auth
		if (!$misc->getServerInfo()) {
			http_response_code(401);
			echo json_encode(['error' => 'Not authenticated']);
			return;
		}

		$logCollector = new LogCollector(true);

		// Collect options
		$format = $_REQUEST['format'] ?? 'auto';
		$useHeader = !empty($_REQUEST['use_header']);
		$allowedNulls = array_values($_REQUEST['allowed_nulls'] ?? []);
		$truncate = !empty($_REQUEST['opt_truncate']);
		$byteaEncoding = $_REQUEST['bytea_encoding'] ?? 'hex';

		// Read request body (binary)
		$raw = file_get_contents('php://input');
		if ($raw === false) {
			http_response_code(400);
			echo json_encode(['error' => 'No input']);
			return;
		}

		// Optional checksum
		$clientHash = $_REQUEST['chunk_hash'] ?? null;
		if ($clientHash !== null) {
			$serverHash = hash('fnv1a64', $raw);
			if ($serverHash !== $clientHash) {
				http_response_code(400);
				echo json_encode([
					'error' => 'Checksum mismatch: chunk corrupted during transmission',
					'error_type' => 'checksum_mismatch',
					'expected' => $clientHash,
					'received' => $serverHash,
				]);
				return;
			}
		}

		// Detect gzip magic bytes 0x1F 0x8B
		$decoded = $raw;
		if (strlen($raw) >= 2) {
			$b0 = ord($raw[0]);
			$b1 = ord($raw[1]);
			if ($b0 === 0x1F && $b1 === 0x8B && function_exists('gzdecode')) {
				$tmp = @gzdecode($raw);
				if ($tmp !== false) {
					$decoded = $tmp;
				}
			}
		}

		// Reset per-import state on first chunk
		if ($baseOffset === 0) {
			$state['parser'] = [];
			$state['header_validated'] = false;
			$state['mapping'] = [];
			$state['meta'] = [];
			$state['serial_omitted'] = false;
			$state['truncated_tables'] = [];
			$state['format'] = $format;
			if ($format === 'auto') {
				$detected = $detectFormat($decoded);
				if ($detected !== null) {
					$state['format'] = $detected;
				}
			}
		}

		$executor = new DataImportExecutor($pg, $logCollector);
		$result = $executor->process($decoded, [
			'format' => $state['format'],
			'use_header' => $useHeader,
			'allowed_nulls' => $allowedNulls,
			'bytea_encoding' => $byteaEncoding,
			'truncate' => $truncate,
			'schema' => $schema,
			'table' => $table,
		], $state);

		$payloadLen = strlen($decoded);
		$newBytesRead = $payloadLen - $remainderLen;
		if ($newBytesRead < 0) {
			$newBytesRead = 0;
		}
		$absoluteOffset = $baseOffset + $newBytesRead;

		if ($eof) {
			$remainder = trim($result['remainder']);
			if ($remainder !== '') {
				$result['errors']++;
				$logCollector->addError('Unexpected end of file: trailing data not parsed. remainder_len=' . strlen($remainder) . ' bytes');
			}
		}

		echo json_encode([
			'offset' => $absoluteOffset,
			'remainder_len' => strlen($result['remainder']),
			'remainder' => $result['remainder'],
			'errors' => $result['errors'],
			'logEntries' => $logCollector->getLogs(),
		]);
	} catch (\Throwable $t) {
		http_response_code(500);
		echo json_encode(['error' => 'process_chunk failed', 'detail' => $t->getMessage()]);
	}
}

$action = $_REQUEST['action'] ?? 'process_chunk';

switch ($action) {
	case 'process_chunk':
		handle_process_chunk();
		//sleep(1); // Simulate processing delay for testing
		break;
	default:
		http_response_code(400);
		echo 'Unknown action';
}