<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\ViewActions;

/**
 * Dumper for PostgreSQL materialized views.
 */
class MaterializedViewDumper extends ExportDumper
{
    private $schemaQuoted = null;
    private $viewQuoted = null;

    private $schemaEscaped = null;
    private $viewEscaped = null;

    public function dump($subject, array $params, array $options = [])
    {
        $view = $params['view'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$view) {
            return;
        }

        $this->schemaQuoted = $this->connection->quoteIdentifier($schema);
        $this->viewQuoted = $this->connection->quoteIdentifier($view);
        $this->schemaEscaped = $this->connection->escapeString($schema);
        $this->viewEscaped = $this->connection->escapeString($view);

        $viewActions = new ViewActions($this->connection);
        // Schema is already set in connection->_schema
        $rs = $viewActions->getView($view);

        if (!$rs || $rs->EOF) {
            return;
        }

        $this->write("\n-- Materialized View: {$this->schemaQuoted}.{$this->viewQuoted}\n");

        $this->writeDrop('MATERIALIZED VIEW', "{$this->schemaQuoted}.{$this->viewQuoted}", $options);

        $viewDef = $rs->fields['vwdefinition'];

        if ($viewDef) {
            $this->write("CREATE MATERIALIZED VIEW " . $this->getIfNotExists($options) . "{$this->schemaQuoted}.{$this->viewQuoted} AS\n");
            // Remove trailing semicolon from view definition if present
            $viewDef = rtrim($viewDef, " \t\n\r\0\x0B;");
            $this->write($viewDef);
            $this->write("\nWITH NO DATA;\n\n");

            // Defer the REFRESH for after data is loaded
            if ($this->parentDumper instanceof SchemaDumper) {
                $this->parentDumper->addDeferredMaterializedViewRefresh(
                    $schema,
                    $view
                );
            }
        }

        // Add comment if present and requested
        if ($this->shouldIncludeComments($options)) {
            if (!empty($rs->fields['relcomment'])) {
                $comment = $this->connection->escapeString($rs->fields['relcomment']);
                $this->write("COMMENT ON MATERIALIZED VIEW {$this->schemaQuoted}.{$this->viewQuoted} IS '$comment';\n");
            }
        }

        $this->deferTriggers($view, $schema, $options);

        // Materialized views use table privileges
        $this->writeOwner(
            "{$this->schemaQuoted}.{$this->viewQuoted}",
            'MATERIALIZED VIEW',
            $rs->fields['relowner']
        );
        $this->writePrivileges($view, 'table', $rs->fields['relowner']);
    }

    protected function deferTriggers($view, $schema, $options)
    {
        // pg_get_triggerdef(oid) is available since 9.0
        $sql =
            "SELECT pg_get_triggerdef(oid) as definition
            FROM pg_trigger
            WHERE tgrelid = (
                SELECT oid FROM pg_class WHERE relname = '$this->viewEscaped'
                    AND relnamespace = (
                        SELECT oid FROM pg_namespace
                        WHERE nspname = '$this->schemaEscaped'
                    )
                )";
        $rs = $this->connection->selectSet($sql);

        if (!is_object($rs)) {
            return;
        }

        while (!$rs->EOF) {
            // Add to parent SchemaDumper's deferred collection
            if ($this->parentDumper instanceof SchemaDumper) {
                $this->parentDumper->addDeferredTrigger(
                    $schema,
                    $view,
                    $rs->fields['definition']
                );
            }
            $rs->moveNext();
        }
    }

}
