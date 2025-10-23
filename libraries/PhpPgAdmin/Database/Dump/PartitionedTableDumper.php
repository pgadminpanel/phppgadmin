<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Database\Actions\TriggerActions;
use PhpPgAdmin\Database\Actions\RuleActions;

/**
 * Dumper for PostgreSQL partitioned tables (parent tables with PARTITION BY).
 * Partitioned tables (relkind 'p') don't store data directly - data is in partitions.
 * PostgreSQL 10+ feature.
 */
class PartitionedTableDumper extends TableBaseDumper
{

    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        // Partitioned tables require PostgreSQL 10+
        if ($this->connection->major_version < 10) {
            return;
        }

        $this->initializeQuotedIdentifiers($table, $schema);

        $this->write("\n-- Partitioned Table: \"{$schema}\".\"{$table}\"\n\n");

        if (empty($options['data_only'])) {
            $this->deferredConstraints = [];

            $this->dumpPartitionedTableStructure($table, $schema, $options);
            $this->dumpAutovacuumSettings($table, $schema);

            // Write constraints after structure (except foreign keys which are deferred to SchemaDumper)
            $this->applyDeferredConstraints($options);

            // Write indexes
            $this->writeIndexes($table, $options);

            // Defer triggers and rules to parent SchemaDumper
            $this->deferTriggers($table, $schema, $options);
            $this->deferRules($table, $schema, $options);
        }

        // Note: No data dump for partitioned tables - data is stored in partitions
        // The PartitionDumper handles data for individual partitions

        // Register this table as dumped
        if ($this->parentDumper instanceof SchemaDumper) {
            $this->parentDumper->registerDumpedTable($schema, $table);
        }
    }

    /**
     * Dump the structure of a partitioned table including PARTITION BY clause.
     */
    protected function dumpPartitionedTableStructure($table, $schema, $options)
    {
        $tableActions = new TableActions($this->connection);
        $t = $tableActions->getTable($table);

        if (!is_object($t) || $t->recordCount() != 1) {
            return false;
        }

        // Check that this is actually a partitioned table (relkind 'p')
        // For PostgreSQL 10+, we also check partstrat exists
        if (!isset($t->fields['relkind']) || $t->fields['relkind'] !== 'p') {
            return false;
        }

        $atts = $tableActions->getTableAttributes($table);
        if (!is_object($atts)) {
            return false;
        }

        $constraintActions = new ConstraintActions($this->connection);
        $cons = $constraintActions->getConstraints($table);

        // header / drop / create begin
        $this->write("-- Definition\n\n");
        $this->writeDrop('TABLE', "{$this->schemaQuoted}.{$this->tableQuoted}", $options);
        $this->write("CREATE TABLE {$this->schemaQuoted}.{$this->tableQuoted} (\n");

        // columns
        $col_comments_sql = '';
        $first_attr = true;
        while (!$atts->EOF) {
            if ($first_attr) {
                $first_attr = false;
            } else {
                $this->write(",\n");
            }
            $name = $this->connection->quoteIdentifier($atts->fields['attname']);
            $this->write("    {$name} {$atts->fields['type']}");

            // Check for generated column first (PostgreSQL 12+)
            if (isset($atts->fields['attgenerated']) && $atts->fields['attgenerated'] === 's') {
                // Generated stored column - write inline for partitioned tables
                if ($atts->fields['adsrc'] !== null) {
                    $this->write(" GENERATED ALWAYS AS ({$atts->fields['adsrc']}) STORED");
                }
            } else {
                // Regular column - handle NOT NULL and DEFAULT
                if ($this->connection->phpBool($atts->fields['attnotnull'])) {
                    $this->write(" NOT NULL");
                }

                // Handle DEFAULT
                if ($atts->fields['adsrc'] !== null) {
                    $this->write(" DEFAULT {$atts->fields['adsrc']}");
                }
            }

            if ($atts->fields['comment'] !== null) {
                $comment = $this->connection->escapeString($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN {$this->schemaQuoted}.{$this->tableQuoted}.{$this->connection->quoteIdentifier($atts->fields['attname'])} IS '{$comment}';\n";
            }

            $atts->moveNext();
        }

        // Store constraints for deferred application (except NOT NULL which is inline)
        if (is_object($cons)) {
            while (!$cons->EOF) {
                if ($cons->fields['contype'] == 'n') {
                    // Skip NOT NULL constraints as they are dumped with the column definition
                    $cons->moveNext();
                    continue;
                }

                $constraintName = $this->connection->quoteIdentifier($cons->fields['conname']);
                $src = $cons->fields['consrc'];

                if (empty($src)) {
                    // Build constraint source from type and columns
                    $columns = trim($cons->fields['columns'], '{}');
                    switch ($cons->fields['contype']) {
                        case 'p':
                            $src = "PRIMARY KEY ($columns)";
                            break;
                        case 'u':
                            $src = "UNIQUE ($columns)";
                            break;
                        case 'f':
                        case 'c':
                            $src = $cons->fields['consrc'];
                            break;
                        default:
                            $cons->moveNext();
                            continue 2;
                    }
                }

                // Foreign keys are ALWAYS deferred to SchemaDumper level
                if ($cons->fields['contype'] === 'f') {
                    $this->deferForeignKeyToParent($constraintName, $src);
                    $cons->moveNext();
                    continue;
                }

                // Store other constraints for later application
                $this->deferredConstraints[] = [
                    'name' => $constraintName,
                    'definition' => $src,
                    'type' => $cons->fields['contype'],
                ];

                $cons->moveNext();
            }
        }

        $this->write("\n)");

        // Add PARTITION BY clause
        $this->writePartitionByClause($t->fields);

        $this->write(";\n");

        // per-column ALTERs (statistics, storage)
        $atts->moveFirst();
        $first = true;
        while (!$atts->EOF) {
            $fieldQuoted = $this->connection->quoteIdentifier($atts->fields['attname']);

            // Set statistics target
            $stat = $atts->fields['attstattarget'];
            if ($stat !== null && $stat !== '' && is_numeric($stat) && $stat >= 0) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STATISTICS {$stat};\n");
            }

            // Set storage parameter
            if ($atts->fields['attstorage'] != $atts->fields['typstorage']) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $storage = null;
                switch ($atts->fields['attstorage']) {
                    case 'p':
                        $storage = 'PLAIN';
                        break;
                    case 'e':
                        $storage = 'EXTERNAL';
                        break;
                    case 'm':
                        $storage = 'MAIN';
                        break;
                    case 'x':
                        $storage = 'EXTENDED';
                        break;
                }
                if ($storage) {
                    $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STORAGE {$storage};\n");
                }
            }

            $atts->moveNext();
        }

        // table comment
        $this->writeTableComment($t->fields);

        // column comments
        if ($col_comments_sql != '') {
            $this->write($col_comments_sql);
        }

        // owner and privileges
        $this->writeOwner(
            "{$this->schemaQuoted}.{$this->tableQuoted}",
            'TABLE',
            $t->fields['relowner']
        );
        $this->writePrivileges(
            $table,
            'table',
            $t->fields['relowner'],
            $t->fields['relacl']
        );

        $this->write("\n");

        return true;
    }
}
