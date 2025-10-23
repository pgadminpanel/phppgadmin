<?php

namespace PhpPgAdmin\Database\Import;

use Exception;
use PhpPgAdmin\Database\Import\LogCollector;
use PhpPgAdmin\Database\Import\StatementExecutor;
use PhpPgAdmin\Database\Import\Exception\StatementExecutionException;

class ImportExecutor
{
    public static function executeStatementsBatch($statements, $opts, &$state, $pg, $scope, $isSuper, $allowCategory, &$logs, &$errors)
    {
        $currentUser = null;
        if (isset($pg->conn->_connectionID)) {
            $currentUser = pg_parameter_status($pg->conn->_connectionID, 'user');
        }

        /*
        // If importing into a specific schema, ensure search_path is set so
        // unqualified object references resolve correctly.
        if (($scope ?? '') === 'schema' && !empty($state['scope_ident'])) {
            $schema = $state['scope_ident'];
            $schema = str_replace('"', '""', $schema);
            try {
                $pg->execute('SET search_path TO "' . $schema . '"');
            } catch (Exception $e) {
                // ignore errors; execution will fail later if schema missing
            }
        }
        */

        $streamingMode = !empty($opts['streaming']);
        $existingCollector = $logs instanceof LogCollector ? $logs : null;
        $logCollector = $existingCollector ?: new LogCollector($streamingMode);

        $executor = new StatementExecutor(
            $logCollector,
            $state,
            $pg,
            $isSuper,
            $currentUser ?? '',
            $opts,
            $allowCategory
        );

        $errorsBefore = $logCollector->getErrorCount();

        foreach ($statements as $stmt) {
            $stmtTrim = trim($stmt);
            if ($stmtTrim === '') {
                continue;
            }

            try {
                $executor->execute($stmtTrim);
            } catch (StatementExecutionException $ex) {
                $errors++;
                // If stop_on_error is enabled, throw exception to stop processing immediately
                if (!empty($opts['stop_on_error'])) {
                    throw new Exception('Statement failed: ' . $ex->getMessage(), 0, $ex);
                }
                // Otherwise continue processing remaining statements
            }
        }

        // Merge collected logs and errors
        if ($existingCollector === null) {
            if (!is_array($logs)) {
                $logs = [];
            }
            $logs = array_merge($logs, $logCollector->getLogsWithSummary());
        }

        $errors += max(0, $logCollector->getErrorCount() - $errorsBefore);
    }

}
