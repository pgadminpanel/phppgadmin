<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Database\Actions\TriggerActions;
use PhpPgAdmin\Database\Actions\RuleActions;
use PhpPgAdmin\Database\Export\SqlFormatter;
use PhpPgAdmin\Database\Cursor\CursorReader;

/**
 * Dumper for PostgreSQL table partitions (child tables of partitioned tables).
 * Creates tables using PARTITION OF syntax with partition bounds.
 * PostgreSQL 10+ feature.
 */
class PartitionDumper extends TableBaseDumper
{

    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        // Partitions require PostgreSQL 10+
        if ($this->connection->major_version < 10) {
            return;
        }

        $this->initializeQuotedIdentifiers($table, $schema);

        $this->write("\n-- Partition: \"{$schema}\".\"{$table}\"\n\n");

        if (empty($options['data_only'])) {
            $this->deferredConstraints = [];

            $this->dumpPartitionStructure($table, $schema, $options);
            $this->dumpAutovacuumSettings($table, $schema);

            // Write local constraints (partition-specific constraints)
            $this->applyDeferredConstraints($options, 'Local Constraints');

            // Write indexes (partition-specific indexes)
            $this->writeIndexes($table, $options, true, 'Local Indexes');

            // Defer triggers and rules
            $this->deferTriggers($table, $schema, $options);
            $this->deferRules($table, $schema, $options);
        }

        // Dump data for this partition
        if (empty($options['structure_only'])) {
            $this->dumpData($table, $schema, $options, 'partition');
        }

        // Register this table as dumped
        if ($this->parentDumper instanceof SchemaDumper) {
            $this->parentDumper->registerDumpedTable($schema, $table);
        }
    }

    /**
     * Dump the structure of a partition using CREATE TABLE ... PARTITION OF syntax.
     */
    protected function dumpPartitionStructure($table, $schema, $options)
    {
        $tableActions = new TableActions($this->connection);
        $t = $tableActions->getTable($table);

        if (!is_object($t) || $t->recordCount() != 1) {
            return false;
        }

        // Verify this is actually a partition
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

        // CREATE TABLE partition PARTITION OF parent FOR VALUES ...
        $this->write("CREATE TABLE {$this->schemaQuoted}.{$this->tableQuoted} PARTITION OF {$parentSchemaQuoted}.{$parentTableQuoted}\n");
        $this->write("    {$partitionBound};\n");

        // Get partition-specific attributes for any local column modifications
        $atts = $tableActions->getTableAttributes($table);
        $col_comments_sql = '';

        if (is_object($atts)) {
            while (!$atts->EOF) {
                // Column comments
                if ($atts->fields['comment'] !== null) {
                    $comment = $this->connection->escapeString($atts->fields['comment']);
                    $col_comments_sql .= "COMMENT ON COLUMN {$this->schemaQuoted}.{$this->tableQuoted}.{$this->connection->quoteIdentifier($atts->fields['attname'])} IS '{$comment}';\n";
                }
                $atts->moveNext();
            }
        }

        // Get local constraints (not inherited from parent)
        $partitionActions = new PartitionActions($this->connection);
        $localConstraints = $partitionActions->getPartitionConstraints($table, false); // false = local only

        if (is_object($localConstraints)) {
            while (!$localConstraints->EOF) {
                if ($localConstraints->fields['contype'] == 'n') {
                    // Skip NOT NULL constraints
                    $localConstraints->moveNext();
                    continue;
                }

                $constraintName = $this->connection->quoteIdentifier($localConstraints->fields['conname']);
                $constraintDef = $localConstraints->fields['condef'];

                // Foreign keys are deferred to SchemaDumper
                if ($localConstraints->fields['contype'] === 'f') {
                    $this->deferForeignKeyToParent($constraintName, $constraintDef);
                    $localConstraints->moveNext();
                    continue;
                }

                // Store other local constraints
                $this->deferredConstraints[] = [
                    'name' => $constraintName,
                    'definition' => $constraintDef,
                    'type' => $localConstraints->fields['contype'],
                ];

                $localConstraints->moveNext();
            }
        }

        // table comment
        $this->writeTableComment($t->fields);

        // column comments
        if ($col_comments_sql != '') {
            $this->write($col_comments_sql);
        }

        // owner and privileges (partitions inherit from parent but can have overrides)
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
