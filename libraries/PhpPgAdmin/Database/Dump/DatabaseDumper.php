<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Connector;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\DatabaseActions;

/**
 * Orchestrator dumper for a PostgreSQL database.
 */
class DatabaseDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $this->setDumpOptions($options);

        $database = $params['database'] ?? $this->connection->conn->database;
        if (!$database) {
            return;
        }

        $databaseActions = new DatabaseActions($this->connection);
        $rs = $databaseActions->getDatabase($database);
        if (!$rs || $rs->EOF) {
            return;
        }

        $databaseQuoted = $this->connection->quoteIdentifier($database);

        $this->writeHeader("Database: {$databaseQuoted}");

        // Emit global preliminaries (ON_ERROR_STOP) unless suppressed
        if (empty($options['suppress_preliminaries'])) {
            $this->write("\\set ON_ERROR_STOP on\n\n");
        }

        // Database settings
        // Optionally add creation and/or connect markers when orchestrated by ServerDumper
        if (!empty($options['add_create_database'])) {
            $this->write("-- Database creation\n");
            if (!empty($options['clean'])) {
                $this->write("DROP DATABASE IF EXISTS {$databaseQuoted} CASCADE;\n");
            }
            $this->write("CREATE DATABASE {$databaseQuoted};\n");
        }

        $this->writeConnectHeader($database);

        // Save current database and reconnect to target database
        $originalDatabase = $this->connection->conn->database;
        $serverInfo = AppContainer::getMisc()->getServerInfo();

        if ($database != $originalDatabase) {
            $this->connection->conn->close();
            // Reconnect to the target database
            $this->connection->conn->connect(
                Connector::getHostPortString(
                    $serverInfo['host'] ?? null,
                    $serverInfo['port'] ?? null,
                    $serverInfo['sslmode'] ?? null
                ),
                $serverInfo['username'] ?? '',
                $serverInfo['password'] ?? '',
                $database,
            );
        }

        // Begin transaction for data consistency (only if not structure-only)
        if (empty($options['structure_only'])) {
            $this->connection->beginDump();
        }

        // Get list of selected schemas (if any)
        $hasSelection = isset($options['objects']);
        $selectedSchemas = $options['objects'] ?? [];
        $selectedSchemas = array_combine($selectedSchemas, $selectedSchemas);
        unset($options['objects']);
        $options['include_schema_objects'] = true;

        // Iterate through schemas
        $schemaActions = new SchemaActions($this->connection);
        $schemas = $schemaActions->getSchemas(false);

        $dumper = $this->createSubDumper('schema');
        while ($schemas && !$schemas->EOF) {
            $schemaName = $schemas->fields['nspname'];
            if (!$hasSelection || isset($selectedSchemas[$schemaName])) {
                $dumper->dump('schema', ['schema' => $schemaName], $options);
            }
            $schemas->moveNext();
        }

        $this->writeOwner(
            $databaseQuoted,
            'DATABASE',
            $rs->fields['owner']
        );
        $this->writePrivileges(
            $database,
            'database',
            $rs->fields['owner'],
            $rs->fields['datacl']
        );

        // End transaction for this database
        if (empty($options['structure_only'])) {
            $this->connection->endDump();
        }

        if ($database != $originalDatabase) {
            // Reconnect to original database
            $this->connection->conn->close();
            $this->connection->conn->connect(
                Connector::getHostPortString(
                    $serverInfo['host'] ?? null,
                    $serverInfo['port'] ?? null,
                    $serverInfo['sslmode'] ?? null
                ),
                $serverInfo['username'] ?? '',
                $serverInfo['password'] ?? '',
                $originalDatabase
            );
        }

        $this->writeConnectFooter();
        $this->writeFooter();
    }
}
