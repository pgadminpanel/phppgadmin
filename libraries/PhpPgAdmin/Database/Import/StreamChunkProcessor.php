<?php

namespace PhpPgAdmin\Database\Import;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Import\Exception\CopyException;
use Throwable;

/**
 * Handles processing of a single uploaded chunk for streaming import.
 */
class StreamChunkProcessor
{
    private array $request;

    /** @var array<string, mixed> */
    private array $session;

    private int $httpStatus = 200;

    /** @var array<string, mixed> */
    private array $serverInfo = [];

    private string $importSessionId = '';

    private int $baseOffset = 0;

    private int $remainderLen = 0;

    private int $skipCount = 0;

    private bool $eof = false;

    private string $raw = '';

    private string $decoded = '';

    private array $options = [];

    /** @var array<string, mixed> */
    private array $streamState = [];

    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    private string $remainder = '';

    private int $errors = 0;

    private bool $shouldStop = false;

    private int $itemsProcessed = 0;

    private bool $failed = false;

    /** @var array<string, mixed> */
    private array $failureResponse = [];

    private LogCollector $logCollector;

    private SessionSettingsApplier $settingsApplier;

    private $misc;

    private $pg;

    public function __construct(array $request, array &$session)
    {
        $this->request = $request;
        $this->session = &$session;
    }

    public static function fromGlobals(): self
    {
        return new self($_REQUEST, $_SESSION);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        ini_set('html_errors', '0');
        AppContainer::set('throw_on_sql_error', true);

        try {
            $this->misc = AppContainer::getMisc();
            $this->pg = AppContainer::getPostgres();

            if (!$this->initializeRequest()) {
                return $this->failureResponse;
            }

            if (!$this->initializeSessionState()) {
                return $this->failureResponse;
            }

            $this->logCollector = new LogCollector(true);
            $this->initializeOptions();

            if (!$this->readInput()) {
                return $this->failureResponse;
            }

            $this->decodeInput();

            $this->initializeSettingsApplier();

            $this->processChunk();
            $this->finalizeOnEof();

            return $this->buildResponse();
        } catch (Throwable $t) {
            $this->httpStatus = 500;
            return ['error' => 'process_chunk failed', 'detail' => $t->getMessage()];
        }
    }

    private function initializeRequest(): bool
    {
        $this->importSessionId = $this->request['import_session_id'] ?? '';
        if ($this->importSessionId === '') {
            return $this->fail(400, 'import_session_id parameter required');
        }

        $this->baseOffset = isset($this->request['offset']) ? (int) $this->request['offset'] : 0;
        $this->remainderLen = isset($this->request['remainder_len']) ? max(0, (int) $this->request['remainder_len']) : 0;
        $this->skipCount = isset($this->request['skip']) ? max(0, (int) $this->request['skip']) : 0;
        $this->eof = !empty($this->request['eof']);

        $this->serverInfo = $this->misc->getServerInfo();
        if (!$this->serverInfo) {
            return $this->fail(401, 'Not authenticated');
        }

        return true;
    }

    private function initializeSessionState(): bool
    {
        if (!isset($this->session['stream_import'])) {
            $this->session['stream_import'] = [];
        }

        $resetSession =
            !isset($this->session['stream_import'][$this->importSessionId]) ||
            $this->baseOffset === 0;

        if ($resetSession) {
            $this->session['stream_import'][$this->importSessionId] = [
                'copy_active' => false,
                'copy_header' => '',
                'truncated_tables' => [],
                'deferred' => [],
                'home_database' => '',
                'home_schema' => '',
                'current_database' => '',
                'current_schema' => '',
                'active_database' => '',
                'pending_database' => '',
                'cached_settings' => [],
                'last_applied_db' => null,
                'encoding' => '',
                'standard_conforming_strings' => true,
            ];
        }

        $this->streamState = &$this->session['stream_import'][$this->importSessionId];

        if ($this->baseOffset === 0 && $this->remainderLen === 0) {
            $this->streamState['truncated_tables'] = [];
            $this->streamState['deferred'] = [];
            $this->streamState['ownership_queue'] = [];
            $this->streamState['rights_queue'] = [];
            $this->streamState['cached_settings'] = [];
            $this->streamState['last_applied_db'] = null;
            if ($this->streamState['home_database'] === '') {
                $this->streamState['home_database'] = $this->request['database'] ?? ($this->serverInfo['database'] ?? '');
            }
            if ($this->streamState['home_schema'] === '') {
                $this->streamState['home_schema'] = $this->request['schema'] ?? '';
            }
            if ($this->streamState['current_database'] === '') {
                $this->streamState['current_database'] = $this->streamState['home_database'];
            }
            if ($this->streamState['current_schema'] === '') {
                $this->streamState['current_schema'] = $this->streamState['home_schema'];
            }
            $this->streamState['active_database'] = $this->streamState['current_database'];
        }

        if ($this->baseOffset === 0 && $this->remainderLen === 0) {
            if ($this->streamState['home_database'] === '') {
                $this->streamState['home_database'] = $this->request['database'] ?? ($this->serverInfo['database'] ?? '');
            }
            if ($this->streamState['home_schema'] === '') {
                $this->streamState['home_schema'] = $this->request['schema'] ?? '';
            }
            if ($this->streamState['current_database'] === '') {
                $this->streamState['current_database'] = $this->streamState['home_database'];
            }
            if ($this->streamState['active_database'] === '') {
                $this->streamState['active_database'] = $this->streamState['home_database'];
            }
        }

        return true;
    }

    private function initializeOptions(): void
    {
        $this->options = [
            'stop_on_error' => !empty($this->request['opt_stop_on_error']),
            'roles' => !empty($this->request['opt_roles']),
            'tablespaces' => !empty($this->request['opt_tablespaces']),
            'databases' => !empty($this->request['opt_databases']),
            'schema_create' => !empty($this->request['opt_schema_create']),
            'data' => !empty($this->request['opt_data']),
            'truncate' => !empty($this->request['opt_truncate']),
            'ownership' => !empty($this->request['opt_ownership']),
            'rights' => !empty($this->request['opt_rights']),
            'defer_self' => !empty($this->request['opt_defer_self']),
            'allow_drops' => !empty($this->request['opt_allow_drops']),
            'ignore_connect' => !empty($this->request['opt_ignore_connect']),
            'verbose' => !empty($this->request['opt_verbose']),
            'streaming' => true,
        ];
    }

    private function readInput(): bool
    {
        $this->raw = file_get_contents('php://input');
        if ($this->raw === false) {
            return $this->fail(400, 'No input');
        }

        $clientHash = $this->request['chunk_hash'] ?? null;
        if ($clientHash !== null) {
            $serverHash = hash('fnv1a64', $this->raw);
            if ($serverHash !== $clientHash) {
                return $this->fail(400, 'Checksum mismatch: chunk corrupted during transmission', [
                    'expected' => $clientHash,
                    'received' => $serverHash,
                ]);
            }
        }

        return true;
    }

    private function decodeInput(): void
    {
        $this->decoded = $this->raw;

        if (strlen($this->raw) >= 2) {
            $b0 = ord($this->raw[0]);
            $b1 = ord($this->raw[1]);
            if ($b0 === 0x1F && $b1 === 0x8B && function_exists('gzdecode')) {
                $tmp = @gzdecode($this->raw);
                if ($tmp !== false) {
                    $this->decoded = $tmp;
                }
            }
        }
    }

    private function initializeSettingsApplier(): void
    {
        $this->settingsApplier = new SessionSettingsApplier(
            $this->logCollector,
            $this->pg->getMajorVersion()
        );
        if (!empty($this->streamState['cached_settings'])) {
            $this->settingsApplier->setCachedSettings($this->streamState['cached_settings']);
        }

        if ($this->baseOffset === 0 && $this->remainderLen === 0) {
            $this->logCollector->addInfo('Import started. Options: ' . json_encode([
                'truncate' => $this->options['truncate'],
                'data' => $this->options['data'],
                'stop_on_error' => $this->options['stop_on_error'],
            ]));
        }
    }

    private function processChunk(): void
    {
        $inCopy = !empty($this->streamState['copy_active']);
        $copyHeader = $this->streamState['copy_header'] ?? '';

        $copyTermPattern = "/\r?\n\\\.\r?\n/";
        $copyHeaderPattern = '/^\s*(COPY\b[^\n]*FROM\s+stdin\b[^\n]*;\s*\r?\n)/i';

        if ($inCopy) {
            // If terminator present in this chunk, finish COPY and parse rest normally
            if (preg_match($copyTermPattern, $this->decoded, $m, PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                // data lines up to newline before terminator
                $before = substr($this->decoded, 0, $pos);
                // Build a complete COPY for this chunk
                $dataSend = $before;
                if ($dataSend !== '' && substr($dataSend, -1) !== "\n") {
                    $dataSend .= "\n";
                }
                $this->streamCopyData($copyHeader, $dataSend);
                // Clear COPY state and continue with remainder after terminator
                $this->streamState['copy_active'] = false;
                $this->streamState['copy_header'] = '';
                $after = substr($this->decoded, $pos + strlen($m[0][0]));
                $split = SqlParser::parseFromString($after, '', false, !empty($this->streamState['standard_conforming_strings']));
                $this->items = $split['items'];
                $this->remainder = $split['remainder'];
                $this->streamState['standard_conforming_strings'] = !empty($split['standard_conforming_strings']);
            } else {
                // No terminator yet: send only complete lines in this chunk
                $lastNl = strrpos($this->decoded, "\n");
                if ($lastNl === false) {
                    // Keep all as remainder for next chunk
                    $this->remainder = $this->decoded;
                } else {
                    $dataSend = substr($this->decoded, 0, $lastNl + 1);
                    $tail = substr($this->decoded, $lastNl + 1);
                    $this->streamCopyData($copyHeader, $dataSend);
                    $this->remainder = $tail;
                }
            }
        } else {
            // Check if this chunk starts a COPY ... FROM stdin block without terminator present
            if (preg_match($copyHeaderPattern, $this->decoded, $hm, PREG_OFFSET_CAPTURE)) {
                $header = $hm[1][0];
                $headerEnd = $hm[1][1] + strlen($hm[1][0]);
                $rest = substr($this->decoded, $headerEnd);
                if (preg_match($copyTermPattern, $rest, $m, PREG_OFFSET_CAPTURE)) {
                    // Header and terminator both present in same chunk; let parser handle it fully
                    $split = SqlParser::parseFromString($this->decoded, '', false, !empty($this->streamState['standard_conforming_strings']));
                    $this->items = $split['items'];
                    $this->remainder = $split['remainder'];
                    $this->streamState['standard_conforming_strings'] = !empty($split['standard_conforming_strings']);
                } else {
                    // Activate COPY streaming and send complete lines from rest
                    $this->streamState['copy_active'] = true;
                    $this->streamState['copy_header'] = $header;
                    $lastNl = strrpos($rest, "\n");
                    if ($lastNl === false) {
                        // no full line yet
                        $this->remainder = $rest;
                    } else {
                        $dataSend = substr($rest, 0, $lastNl + 1);
                        $tail = substr($rest, $lastNl + 1);
                        $this->streamCopyData($header, $dataSend);
                        $this->remainder = $tail;
                    }
                }
            } else {
                // Normal path: Parse statements using SqlParser with COPY and comment/meta handling
                $split = SqlParser::parseFromString($this->decoded, '', false, !empty($this->streamState['standard_conforming_strings']));
                $this->items = $split['items'];
                $this->remainder = $split['remainder'];
                $this->streamState['standard_conforming_strings'] = !empty($split['standard_conforming_strings']);
                // Note: If remainder starts with COPY, streaming will be activated AFTER statements in $items are executed
                // (see code after the foreach loop)
            }
        }

        $this->processItems();
        $this->activateCopyFromRemainder($copyHeaderPattern, $copyTermPattern, $inCopy);

        if (!empty($this->items)) {
            $this->logCollector->addInfo('Chunk processed: offset=' . $this->getAbsoluteOffset() . ' items=' . $this->itemsProcessed . ' remainder=' . strlen($this->remainder));
            if (strlen($this->remainder) > 10000) {
                $copyStatus = !empty($this->streamState['copy_active']) ? ' (COPY streaming active)' : '';
                $this->logCollector->addWarning('Large remainder detected: ' . strlen($this->remainder) . ' bytes' . $copyStatus . '. Preview: ' . substr($this->remainder, 0, 200));
            }
        }
    }

    private function processItems(): void
    {
        if (empty($this->items)) {
            return;
        }

        $itemsToProcess = $this->items;
        if ($this->skipCount > 0) {
            $itemsToProcess = array_slice($itemsToProcess, $this->skipCount);
        }

        $errorsBefore = $this->errors;

        foreach ($itemsToProcess as $item) {
            if (!isset($item['type'], $item['content'])) {
                continue;
            }

            if ($item['type'] === 'meta') {
                // Process meta-command immediately
                $this->processMetaCommand($item['content']);
                $this->itemsProcessed++;
                continue;
            }

            if ($item['type'] !== 'statement') {
                continue;
            }

            // COPY blocks must be executed using the COPY protocol (pg_put_line/pg_end_copy).
            // If we see a complete COPY ... FROM stdin block, handle it here instead of pg->execute().
            if ($this->processCopyStatement($item['content'])) {
                $this->itemsProcessed++;
                continue;
            }

            // Check if there's a pending database switch from meta-command
            if (!empty($this->streamState['pending_database']) && $this->streamState['active_database'] !== $this->streamState['pending_database']) {
                if (!$this->switchDatabase($this->streamState['pending_database'])) {
                    $this->shouldStop = true;
                    $this->logCollector->addFatal('Import stopped: failed to switch database to "' . $this->streamState['pending_database'] . '"');
                    break;
                }
                // Clear after switch
                $this->streamState['pending_database'] = '';
            }

            // Collect settings from this statement before executing
            // Returns false if statement should be skipped (unknown/unsupported SET command)
            $shouldExecute = $this->settingsApplier->collectFromStatement($item['content'], $this->streamState);

            if ($shouldExecute) {
                try {
                    $this->runStatements([$item['content']]);
                    $this->itemsProcessed++;
                } catch (Throwable $e) {
                    $this->logCollector->addError('Statement execution failed: ' . $e->getMessage());
                    if ($this->options['stop_on_error']) {
                        break;
                    }
                }
            } else {
                $this->itemsProcessed++;
            }
        }

        // Check if errors occurred and stop_on_error is enabled
        if ($this->options['stop_on_error'] && $this->errors > $errorsBefore) {
            $this->shouldStop = true;
            $errorCount = $this->errors - $errorsBefore;
            $this->logCollector->addError('Import stopped: ' . $errorCount . ' SQL error(s) occurred in this chunk (stop_on_error enabled)');
        }
    }

    private function processMetaCommand(string $metaLine): void
    {
        if (empty($this->options['ignore_connect'])) {
            // Handle \connect (unless ignored by option)
            $connDb = $this->parseConnectMeta($metaLine);
            if ($connDb !== null) {
                $this->streamState['current_database'] = $connDb;
                $this->streamState['pending_database'] = $connDb;
                $this->logCollector->addInfo('Meta-command: \\connect ' . $connDb);
            }
        } else {
            $connDb = $this->parseConnectMeta($metaLine);
            if ($connDb !== null) {
                $this->logCollector->addInfo('Meta-command: \\connect ' . $connDb . ' (ignored)');
            }
        }

        // Handle \encoding
        $enc = $this->parseEncodingMeta($metaLine);
        if ($enc !== null) {
            $this->streamState['encoding'] = $enc;
            $setEncoding = "SET client_encoding = '" . str_replace("'", "''", $enc) . "';";
            $this->settingsApplier->collectFromStatement($setEncoding, $this->streamState);
            $this->logCollector->addInfo('Meta-command: \\encoding ' . $enc);
        }
    }

    private function processCopyStatement(string $content): bool
    {
        $copyHeaderPattern = '/^\s*(COPY\b[^\n]*FROM\s+stdin\b[^\n]*;\s*\r?\n)/i';
        $copyTermPattern = "/\r?\n\\\.\r?\n/";

        if (preg_match($copyHeaderPattern, $content, $hm, PREG_OFFSET_CAPTURE) && preg_match($copyTermPattern, $content, $tm, PREG_OFFSET_CAPTURE)) {
            $header = $hm[1][0];
            $headerEnd = $hm[1][1] + strlen($hm[1][0]);
            $termPos = $tm[0][1];
            $dataSend = substr($content, $headerEnd, $termPos - $headerEnd);
            if ($dataSend !== '' && substr($dataSend, -1) !== "\n") {
                $dataSend .= "\n";
            }
            $this->streamCopyData($header, $dataSend);
            return true;
        }

        return false;
    }

    private function activateCopyFromRemainder(string $copyHeaderPattern, string $copyTermPattern, bool $inCopy): void
    {
        if ($this->shouldStop || $this->remainder === '' || $inCopy) {
            return;
        }

        if (!preg_match($copyHeaderPattern, $this->remainder, $copyMatch, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $header = $copyMatch[1][0];
        $headerEnd = $copyMatch[1][1] + strlen($copyMatch[1][0]);
        $rest = substr($this->remainder, $headerEnd);

        // Check if terminator is present in remainder
        if (preg_match($copyTermPattern, $rest, $m, PREG_OFFSET_CAPTURE)) {
            // Complete COPY block is present in remainder: execute it now and parse what's after it.
            $dataSend = substr($rest, 0, $m[0][1]);
            if ($dataSend !== '' && substr($dataSend, -1) !== "\n") {
                $dataSend .= "\n";
            }
            $this->streamCopyData($header, $dataSend);

            // Continue parsing/executing any SQL after the terminator.
            if (!$this->shouldStop) {
                $after = substr($rest, $m[0][1] + strlen($m[0][0]));
                $split = SqlParser::parseFromString($after, '', false, !empty($this->streamState['standard_conforming_strings']));
                $this->streamState['standard_conforming_strings'] = !empty($split['standard_conforming_strings']);
                if (!empty($split['items'])) {
                    $this->items = $split['items'];
                    $this->processItems();
                }
                $this->remainder = $split['remainder'];
            }
        } else {
            // Activate COPY streaming and send complete lines
            $this->streamState['copy_active'] = true;
            $this->streamState['copy_header'] = $header;
            $lastNl = strrpos($rest, "\n");
            if ($lastNl === false) {
                // no full line yet
                $this->remainder = $rest;
            } else {
                $dataSend = substr($rest, 0, $lastNl + 1);
                $tail = substr($rest, $lastNl + 1);
                $this->streamCopyData($header, $dataSend);
                $this->remainder = $tail;
            }
        }
    }

    private function finalizeOnEof(): void
    {
        // If client indicates EOF: either drop ignorable tail or surface a clear error.
        if ($this->eof && !empty($this->remainder) && empty($this->streamState['copy_active'])) {
            if ($this->isIgnorableTail($this->remainder)) {
                $this->remainder = '';
            } else {
                $this->errors++;
                $this->logCollector->addError('Unexpected end of file: trailing SQL could not be parsed/executed. remainder_len=' . strlen($this->remainder) . ' preview=' . substr($this->remainder, 0, 200));
            }
        }

        // On EOF, flush any remaining deferred statements for the last database.
        if ($this->eof && empty($this->streamState['copy_active'])) {
            $this->executeDeferred();
            $this->executeQueuedStatements();
        }
    }

    private function runStatements(array $stmts): void
    {
        if (empty($stmts)) {
            return;
        }

        $scope = $this->request['scope'] ?? 'database';
        $scopeIdent = $this->request['scope_ident'] ?? '';
        $execState = [
            'scope' => $scope,
            'scope_ident' => $scopeIdent,
            'ownership_queue' => &$this->streamState['ownership_queue'],
            'rights_queue' => &$this->streamState['rights_queue'],
            'deferred' => &$this->streamState['deferred'],
            'truncated_tables' => &$this->streamState['truncated_tables'],
        ];

        AppContainer::set('quiet_sql_error_handling', true);
        $roleActions = new RoleActions($this->pg);
        $isSuper = $roleActions->isSuperUser();
        ImportExecutor::executeStatementsBatch(
            $stmts,
            $this->options,
            $execState,
            $this->pg,
            $scope,
            $isSuper,
            function () {
                return true;
            },
            $this->logCollector,
            $this->errors
        );
        AppContainer::set('quiet_sql_error_handling', false);
    }

    private function executeDeferred(): void
    {
        if (empty($this->streamState['deferred'])) {
            return;
        }

        $stmts = $this->streamState['deferred'];
        $this->streamState['deferred'] = [];

        $scope = $this->request['scope'] ?? 'database';
        $scopeIdent = $this->request['scope_ident'] ?? '';
        $optsToPass = $this->options;
        // Force execution of self-affecting statements now.
        $optsToPass['defer_self'] = false;

        $execState = [
            'scope' => $scope,
            'scope_ident' => $scopeIdent,
            'ownership_queue' => &$this->streamState['ownership_queue'],
            'rights_queue' => &$this->streamState['rights_queue'],
            'deferred' => [],
            'truncated_tables' => &$this->streamState['truncated_tables'],
        ];

        AppContainer::set('quiet_sql_error_handling', true);
        $roleActions = new RoleActions($this->pg);
        $isSuper = $roleActions->isSuperUser();
        ImportExecutor::executeStatementsBatch(
            $stmts,
            $optsToPass,
            $execState,
            $this->pg,
            $scope,
            $isSuper,
            function () {
                return true;
            },
            $this->logCollector,
            $this->errors
        );
        $this->logCollector->addInfo('Deferred statements executed: ' . count($stmts));
        AppContainer::set('quiet_sql_error_handling', false);
    }

    /**
     * Execute queued ownership and rights statements (called on EOF)
     */
    private function executeQueuedStatements(): void
    {
        $ownershipQueue = $this->streamState['ownership_queue'] ?? [];
        $rightsQueue = $this->streamState['rights_queue'] ?? [];

        if (empty($ownershipQueue) && empty($rightsQueue)) {
            return;
        }

        AppContainer::set('quiet_sql_error_handling', true);

        // Execute ownership statements first
        if (!empty($ownershipQueue)) {
            $this->logCollector->addInfo('Executing ' . count($ownershipQueue) . ' queued ownership statements');
            foreach ($ownershipQueue as $stmt) {
                try {
                    $status = $this->pg->execute($stmt);
                    if ($status < 0) {
                        $this->errors++;
                        $this->logCollector->addError('Ownership statement failed: ' . $stmt);
                    }
                } catch (Throwable $e) {
                    $this->errors++;
                    $this->logCollector->addError('Ownership statement failed: ' . $e->getMessage());
                    if (!empty($this->options['stop_on_error'])) {
                        break;
                    }
                }
            }
            $this->streamState['ownership_queue'] = [];
        }

        // Execute rights statements
        if (!empty($rightsQueue)) {
            $this->logCollector->addInfo('Executing ' . count($rightsQueue) . ' queued rights statements');
            foreach ($rightsQueue as $stmt) {
                try {
                    $status = $this->pg->execute($stmt);
                    if ($status < 0) {
                        $this->errors++;
                        $this->logCollector->addError('Rights statement failed: ' . $stmt);
                    }
                } catch (Throwable $e) {
                    $this->errors++;
                    $this->logCollector->addError('Rights statement failed: ' . $e->getMessage());
                    if (!empty($this->options['stop_on_error'])) {
                        break;
                    }
                }
            }
            $this->streamState['rights_queue'] = [];
        }

        AppContainer::set('quiet_sql_error_handling', false);
    }

    private function streamCopyData(string $header, string $dataSend): void
    {
        try {
            $this->createCopyHandler()->stream($header, $dataSend);
        } catch (CopyException $e) {
            $this->errors++;
            $this->logCollector->addError($e->getMessage());
            if ($this->options['stop_on_error']) {
                $this->shouldStop = true;
                $this->logCollector->addError('Import will stop due to COPY error (stop_on_error enabled)');
            }
        }
    }

    private function createCopyHandler(): CopyStreamHandler
    {
        $scope = $this->request['scope'] ?? 'database';
        $scopeIdent = $this->request['scope_ident'] ?? '';
        $schemaParam = $this->request['schema'] ?? '';
        return new CopyStreamHandler(
            $this->logCollector,
            $this->pg,
            $this->streamState,
            $this->options,
            $scope,
            $scopeIdent,
            is_string($schemaParam) ? $schemaParam : ''
        );
    }

    private function switchDatabase(string $dbName): bool
    {
        if ($this->streamState['active_database'] === $dbName) {
            // Already on this database
            return true;
        }

        // Execute deferred self-affecting statements before leaving
        // current database
        $this->executeDeferred();

        try {
            $pgTarget = $this->misc->getDatabaseAccessor($dbName);
            if ($pgTarget !== null) {
                $this->pg = $pgTarget;
                AppContainer::setPostgres($this->pg);
                $this->streamState['active_database'] = $dbName;

                // Re-apply cached settings to new connection
                if (!empty($this->streamState['cached_settings'])) {
                    $this->errors += $this->settingsApplier->applySettings($this->pg);
                    $this->logCollector->addInfo('Re-applied ' . count($this->streamState['cached_settings']) . ' cached settings to database: ' . $dbName);
                }
                $this->streamState['last_applied_db'] = $dbName;
                return true;
            }
        } catch (Throwable $e) {
            $this->errors++;
            $this->logCollector->addError('Failed to switch to database "' . $dbName . '": ' . $e->getMessage());
        }

        return false;
    }

    private function parseConnectMeta(string $line): ?string
    {
        $trim = trim($line);
        if (!preg_match('/^\\\\c(?:onnect)?\s+(.+)$/i', $trim, $m)) {
            return null;
        }
        $arg = trim($m[1]);
        if ($arg === '') {
            return null;
        }
        if (($arg[0] === '"' && substr($arg, -1) === '"') || ($arg[0] === "'" && substr($arg, -1) === "'")) {
            $arg = substr($arg, 1, -1);
            $arg = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $arg);
        } else {
            $arg = strtok($arg, " \t");
        }
        return $arg;
    }

    private function parseEncodingMeta(string $line): ?string
    {
        $trim = trim($line);
        if (preg_match('/^\\encoding\s+([A-Za-z0-9_-]+)/i', $trim, $m)) {
            return $m[1];
        }
        return null;
    }

    private function isIgnorableTail(string $tail): bool
    {
        $len = strlen($tail);
        $i = 0;
        while ($i < $len) {
            $ch = $tail[$i];

            // whitespace
            if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n" || $ch === "\f") {
                $i++;
                continue;
            }

            // line comment
            if ($ch === '-' && ($i + 1) < $len && $tail[$i + 1] === '-') {
                $nl = strpos($tail, "\n", $i + 2);
                if ($nl === false) {
                    return true;
                }
                $i = $nl + 1;
                continue;
            }

            // block comment
            if ($ch === '/' && ($i + 1) < $len && $tail[$i + 1] === '*') {
                $end = strpos($tail, '*/', $i + 2);
                if ($end === false) {
                    return false;
                }
                $i = $end + 2;
                continue;
            }

            // Anything else is meaningful SQL
            return false;
        }

        return true;
    }

    private function getAbsoluteOffset(): int
    {
        // Compute offset advance based on source bytes read, not executed 
        // statements. New bytes read = payload length minus client-prepended 
        // remainder.
        $payloadLen = strlen($this->decoded);
        $newBytesRead = $payloadLen - $this->remainderLen;
        if ($newBytesRead < 0) {
            $newBytesRead = 0;
        }
        return $this->baseOffset + $newBytesRead;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponse(): array
    {
        // Return the *actual* remainder string.
        // The remainder is not guaranteed to be a literal suffix of the 
        // uploaded payload (SqlParser may normalize/trim while streaming 
        // INSERT ... VALUES), so returning only remainder_len and
        // reconstructing client-side is not reliable.
        return [
            'offset' => $this->getAbsoluteOffset(),
            'remainder_len' => strlen($this->remainder),
            'remainder' => $this->remainder,
            'errors' => $this->errors,
            'stop' => $this->shouldStop,
            'logEntries' => $this->logCollector->getLogsWithSummary(),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function fail(int $status, string $message, array $extra = []): bool
    {
        $this->httpStatus = $status;
        $this->failed = true;
        $this->failureResponse = array_merge(['error' => $message], $extra);
        return false;
    }
}
