<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\ViewActions;

/**
 * Dumper for PostgreSQL views.
 */
class ViewDumper extends ExportDumper
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

        $this->write("\n-- View: {$this->schemaQuoted}.{$this->viewQuoted}\n");

        $this->writeDrop('VIEW', "{$this->schemaQuoted}.{$this->viewQuoted}", $options);

        if (!empty($options['if_not_exists'])) {
            $this->write("CREATE OR REPLACE VIEW {$this->schemaQuoted}.{$this->viewQuoted} AS\n" . rtrim($rs->fields['vwdefinition'], " \t\n\r\0\x0B;") . ";\n");
        } else {
            $this->write("CREATE VIEW {$this->schemaQuoted}.{$this->viewQuoted} AS\n" . rtrim($rs->fields['vwdefinition'], " \t\n\r\0\x0B;") . ";\n");
        }

        // Add comment if present and requested
        if ($this->shouldIncludeComments($options)) {
            if (!empty($rs->fields['relcomment'])) {
                $comment = $this->connection->escapeString($rs->fields['relcomment']);
                $this->write("COMMENT ON VIEW {$this->schemaQuoted}.{$this->viewQuoted} IS '$comment';\\n");
            }
        }

        $this->deferRules($view, $schema, $options);
        $this->deferTriggers($view, $schema, $options);

        $this->writeOwner(
            "{$this->schemaQuoted}.{$this->viewQuoted}",
            'VIEW',
            $rs->fields['relowner']
        );
        $this->writePrivileges($view, 'view', $rs->fields['relowner']);
    }

    protected function deferRules($view, $schema, $options)
    {
        $sql =
            "SELECT definition
            FROM pg_rules
            WHERE schemaname = '{$this->schemaEscaped}'
                AND tablename = '{$this->viewEscaped}'";
        $rs = $this->connection->selectSet($sql);
        if (!$rs || $rs->EOF) {
            return;
        }

        while (!$rs->EOF) {
            // Add to parent SchemaDumper's deferred collection
            if ($this->parentDumper instanceof SchemaDumper) {
                $this->parentDumper->addDeferredRule(
                    $schema,
                    $view,
                    $rs->fields['definition']
                );
            }
            $rs->moveNext();
        }
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
        if (!$rs) {
            return;
        }
        if ($rs->EOF) {
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
