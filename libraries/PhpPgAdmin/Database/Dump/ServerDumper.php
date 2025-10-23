<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\DatabaseActions;

/**
 * Orchestrator dumper for a PostgreSQL server (cluster).
 */
class ServerDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $this->setDumpOptions($options);

        $this->writeHeader("Server Cluster");

        // Ensure psql halts on error for restores
        $this->write("\\set ON_ERROR_STOP on\n\n");
        $this->write("SET client_encoding = 'UTF8';\n\n");

        // 1. Roles (if enabled)
        if (!isset($options['export_roles']) || $options['export_roles']) {
            $roleDumper = $this->createSubDumper('role');
            $roleDumper->dump('role', [], $options);
        }

        // 2. Tablespaces (if enabled)
        if (!isset($options['export_tablespaces']) || $options['export_tablespaces']) {
            $tablespaceDumper = $this->createSubDumper('tablespace');
            $tablespaceDumper->dump('tablespace', [], $options);
        }

        // 3. Databases
        $databaseActions = new DatabaseActions($this->connection);
        $databases = $databaseActions->getDatabases();

        // Get list of selected databases (if any)
        $hasSelection = isset($options['objects']);
        $selectedDatabases = $options['objects'] ?? [];
        $selectedDatabases = array_combine($selectedDatabases, $selectedDatabases);

        unset($options['objects']);
        $options['suppress_preliminaries'] = true;

        $dbDumper = $this->createSubDumper('database');

        // Dump databases
        while ($databases && !$databases->EOF) {
            $dbName = $databases->fields['datname'];
            if (!$hasSelection || isset($selectedDatabases[$dbName])) {
                $dbDumper->dump('database', ['database' => $dbName], $options);
            }
            $databases->moveNext();
        }

        $this->writeFooter();
    }
}
