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
 * Dumper for PostgreSQL sub-partitioned tables (partitions that are themselves partitioned).
 * Multi-level partitioning feature in PostgreSQL 10+.
 * 
 * These tables:
 * - Use PARTITION OF syntax (they're partitions of a parent)
 * - Have PARTITION BY clause (they're partitioned themselves)
 * - Don't store data directly (data is in leaf partitions)
 */
class SubPartitionedTableDumper extends TableBaseDumper
{

    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        // Sub-partitioned tables require PostgreSQL 10+
        if ($this->connection->major_version < 10) {
            return;
        }

        $this->initializeQuotedIdentifiers($table, $schema);

        $this->write("\n-- Sub-Partitioned Table: \"{$schema}\".\"{$table}\"\n\n");

        if (empty($options['data_only'])) {
            $this->deferredConstraints = [];

            $this->dumpSubPartitionedTableStructure($table, $schema, $options);
            $this->dumpAutovacuumSettings($table, $schema);

            // Write local constraints
            $this->applyDeferredConstraints($options, 'Local Constraints');

            // Write indexes
            $this->writeIndexes($table, $options, true, 'Local Indexes');

            // Defer triggers and rules
            $this->deferTriggers($table, $schema, $options);
            $this->deferRules($table, $schema, $options);
        }

        // Note: No data dump for sub-partitioned tables - data is stored in leaf partitions

        // Register this table as dumped
        if ($this->parentDumper instanceof SchemaDumper) {
            $this->parentDumper->registerDumpedTable($schema, $table);
        }
    }

    /**
     * Dump the structure of a sub-partitioned table.
     * Uses PARTITION OF for parent relationship and PARTITION BY for its own partitions.
     */
    protected function dumpSubPartitionedTableStructure($table, $schema, $options)
    {
        $tableActions = new TableActions($this->connection);
        $t = $tableActions->getTable($table);

        if (!is_object($t) || $t->recordCount() != 1) {
            return false;
        }

        // Verify this is a partitioned table that is also a partition
        if (!isset($t->fields['relkind']) || $t->fields['relkind'] !== 'p') {
            return false;
        }

        if (!$this->connection->phpBool($t->fields['relispartition'] ?? 'f')) {
            return false;
        }

        $parentTable = $t->fields['parent_table'] ?? null;
        $parentSchema = $t->fields['parent_schema'] ?? $schema;
        $partitionBound = $t->fields['partition_bound'] ?? null;

        if (!$parentTable || !$partitionBound) {
            return false;
        }

        $parentTableQuoted = $this->connection->quoteIdentifier($parentTable);
        $parentSchemaQuoted = $this->connection->quoteIdentifier($parentSchema);

        // header / drop / create
        $this->write("-- Definition\n\n");
        $this->writeDrop('TABLE', "{$this->schemaQuoted}.{$this->tableQuoted}", $options);

        // CREATE TABLE sub_partition PARTITION OF parent FOR VALUES ... PARTITION BY ...
        $this->write("CREATE TABLE {$this->schemaQuoted}.{$this->tableQuoted} PARTITION OF {$parentSchemaQuoted}.{$parentTableQuoted}\n");
        $this->write("    {$partitionBound}");

        // Add PARTITION BY clause for this sub-partitioned table
        $this->writePartitionByClause($t->fields);

        $this->write(";\n");

        // Get local constraints
        $partitionActions = new PartitionActions($this->connection);
        $localConstraints = $partitionActions->getPartitionConstraints($table, false);

        if (is_object($localConstraints)) {
            while (!$localConstraints->EOF) {
                if ($localConstraints->fields['contype'] == 'n') {
                    $localConstraints->moveNext();
                    continue;
                }

                $constraintName = $this->connection->quoteIdentifier($localConstraints->fields['conname']);
                $constraintDef = $localConstraints->fields['condef'];

                if ($localConstraints->fields['contype'] === 'f') {
                    $this->deferForeignKeyToParent($constraintName, $constraintDef);
                    $localConstraints->moveNext();
                    continue;
                }

                $this->deferredConstraints[] = [
                    'name' => $constraintName,
                    'definition' => $constraintDef,
                    'type' => $localConstraints->fields['contype'],
                ];

                $localConstraints->moveNext();
            }
        }

        // Get column comments
        $atts = $tableActions->getTableAttributes($table);
        $col_comments_sql = '';

        if (is_object($atts)) {
            while (!$atts->EOF) {
                if ($atts->fields['comment'] !== null) {
                    $comment = $this->connection->escapeString($atts->fields['comment']);
                    $col_comments_sql .= "COMMENT ON COLUMN {$this->schemaQuoted}.{$this->tableQuoted}.{$this->connection->quoteIdentifier($atts->fields['attname'])} IS '{$comment}';\n";
                }
                $atts->moveNext();
            }
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
