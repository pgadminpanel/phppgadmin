<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\TriggerActions;
use PhpPgAdmin\Database\Actions\RuleActions;
use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Database\Cursor\CursorReader;
use PhpPgAdmin\Database\Export\SqlFormatter;

/**
 * Abstract base class for table-like dumpers (tables, partitions, partitioned tables).
 * Extracts common functionality shared across TableDumper, PartitionDumper,
 * PartitionedTableDumper, and SubPartitionedTableDumper.
 */
abstract class TableBaseDumper extends ExportDumper
{
    /**
     * Quoted table identifier
     * @var string
     */
    protected $tableQuoted;

    /**
     * Quoted schema identifier
     * @var string
     */
    protected $schemaQuoted;

    /**
     * Deferred constraints to be applied after structure/data
     * @var array
     */
    protected $deferredConstraints = [];

    /**
     * Initialize quoted identifiers for table and schema.
     * 
     * @param string $table Table name
     * @param string $schema Schema name
     */
    protected function initializeQuotedIdentifiers($table, $schema)
    {
        $this->tableQuoted = $this->connection->quoteIdentifier($table);
        $this->schemaQuoted = $this->connection->quoteIdentifier($schema);
    }

    /**
     * Dump autovacuum settings for the table.
     * 
     * @param string $table Table name
     * @param string $schema Schema name
     */
    protected function dumpAutovacuumSettings($table, $schema)
    {
        $adminActions = new AdminActions($this->connection);

        $oldSchema = $this->connection->_schema;
        $this->connection->_schema = $schema;

        $autovacs = $adminActions->getTableAutovacuum($table);

        $this->connection->_schema = $oldSchema;

        if (!$autovacs || $autovacs->EOF) {
            return;
        }

        while ($autovacs && !$autovacs->EOF) {
            $options = [];
            foreach ($autovacs->fields as $key => $value) {
                if (is_int($key)) {
                    continue;
                }
                if ($key === 'nspname' || $key === 'relname') {
                    continue;
                }
                if ($value === null || $value === '') {
                    continue;
                }
                $options[] = $key . '=' . $value;
            }

            if (!empty($options)) {
                $this->write("ALTER TABLE \"{$schema}\".\"{$table}\" SET (" . implode(', ', $options) . ");\n");
                $this->write("\n");
            }

            $autovacs->moveNext();
        }
    }

    /**
     * Defer foreign key constraint to parent SchemaDumper.
     * This prevents forward references where Table A references Table B before B is created.
     * 
     * @param string $constraintName Quoted constraint name
     * @param string $definition Constraint definition
     */
    protected function deferForeignKeyToParent($constraintName, $definition)
    {
        if ($this->parentDumper instanceof SchemaDumper) {
            $this->parentDumper->addDeferredForeignKey([
                'schemaQuoted' => $this->schemaQuoted,
                'tableQuoted' => $this->tableQuoted,
                'name' => $constraintName,
                'definition' => $definition,
            ]);
        }
    }

    /**
     * Defer rules for the table (to be applied after functions are created).
     * 
     * @param string $table Table name
     * @param string $schema Schema name
     * @param array $options Dump options
     */
    protected function deferRules($table, $schema, $options)
    {
        $ruleActions = new RuleActions($this->connection);
        $rules = $ruleActions->getRules($table);

        if (!is_object($rules) || $rules->EOF) {
            return;
        }

        while (!$rules->EOF) {
            $def = $rules->fields['definition'];
            $def = str_replace('CREATE RULE', 'CREATE OR REPLACE RULE', $def);

            if ($this->parentDumper instanceof SchemaDumper) {
                $this->parentDumper->addDeferredRule(
                    $schema,
                    $table,
                    $def
                );
            }

            $rules->moveNext();
        }
    }

    /**
     * Check if an index is inherited from a parent partitioned table.
     * 
     * @param string $indexOid Index OID
     * @return bool True if inherited, false otherwise
     */
    protected function isInheritedIndex($indexOid)
    {
        $sql = "SELECT EXISTS(
                    SELECT 1 FROM pg_inherits WHERE inhrelid = $indexOid
                ) AS inherited";
        $result = $this->connection->selectField($sql, 'inherited');
        return $result === 't';
    }

    /**
     * Write table comment.
     * 
     * @param array $tableInfo Table information from getTable()
     */
    protected function writeTableComment($tableInfo)
    {
        if ($tableInfo['relcomment'] !== null) {
            $comment = $this->connection->escapeString($tableInfo['relcomment']);
            $this->write("\n-- Comment\n\n");
            $this->write("COMMENT ON TABLE {$this->schemaQuoted}.{$this->tableQuoted} IS '{$comment}';\n");
        }
    }

    /**
     * Write column comments.
     * 
     * @param object $atts ADORecordSet of table attributes
     * @return string SQL for column comments
     */
    protected function writeColumnComments($atts)
    {
        $col_comments_sql = '';

        if (!is_object($atts)) {
            return $col_comments_sql;
        }

        $atts->moveFirst();
        while (!$atts->EOF) {
            if ($atts->fields['comment'] !== null) {
                $comment = $this->connection->escapeString($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN {$this->schemaQuoted}.{$this->tableQuoted}.{$this->connection->quoteIdentifier($atts->fields['attname'])} IS '{$comment}';\n";
            }
            $atts->moveNext();
        }

        return $col_comments_sql;
    }

    /**
     * Dump data for table/partition.
     * 
     * @param string $table Table name
     * @param string $schema Schema name
     * @param array $options Dump options
     * @param string $objectType Type of object for comments ('table' or 'partition')
     */
    protected function dumpData($table, $schema, $options, $objectType = 'table')
    {
        $this->write("\n-- Data for {$objectType} \"{$schema}\".\"{$table}\"\n");

        try {
            $sql = "SELECT * FROM {$this->schemaQuoted}.{$this->tableQuoted}";

            $reader = new CursorReader(
                $this->connection,
                $sql,
                null, // Auto-calculate chunk size
                $table,
                $schema,
                'r' // relation kind
            );

            $reader->open();

            $sqlFormatter = new SqlFormatter();
            $sqlFormatter->setOutputStream($this->outputStream);
            $metadata = [
                'table' => "{$this->schemaQuoted}.{$this->tableQuoted}",
                'batch_size' => $options['batch_size'] ?? 1000,
                'insert_format' => $options['insert_format'] ?? 'copy',
            ];
            $reader->processRows($sqlFormatter, $metadata);

            $reader->close();

        } catch (\Exception $e) {
            error_log('Error dumping ' . $objectType . ' data: ' . $e->getMessage());
            $this->write("-- Error dumping data: " . $e->getMessage() . "\n");
        }
    }

    /**
     * Defer triggers for the table (to be applied after functions are created).
     * 
     * @param string $table Table name
     * @param string $schema Schema name
     * @param array $options Dump options
     * @param bool $checkInherited If true, skip inherited triggers (for partitions)
     */
    protected function deferTriggers($table, $schema, $options, $checkInherited = false)
    {
        $triggerActions = new TriggerActions($this->connection);
        $triggers = $triggerActions->getTriggers($table);

        if (!is_object($triggers) || $triggers->EOF) {
            return;
        }

        while (!$triggers->EOF) {
            // Skip inherited triggers if checking inheritance
            if ($checkInherited && isset($triggers->fields['tgislocal']) && !$this->connection->phpBool($triggers->fields['tgislocal'])) {
                $triggers->moveNext();
                continue;
            }

            $def = $triggers->fields['tgdef'];
            if (!empty($options['if_not_exists'])) {
                if ($this->connection->major_version >= 14) {
                    $def = str_replace('CREATE CONSTRAINT TRIGGER', 'CREATE OR REPLACE CONSTRAINT TRIGGER', $def);
                    $def = str_replace('CREATE TRIGGER', 'CREATE OR REPLACE TRIGGER', $def);
                }
            }

            if ($this->parentDumper && method_exists($this->parentDumper, 'addDeferredTrigger')) {
                $this->parentDumper->addDeferredTrigger($schema, $table, $def);
            }

            $triggers->moveNext();
        }
    }

    /**
     * Write the PARTITION BY clause for a partitioned table.
     * 
     * @param array $tableInfo Table information containing partstrat and partition_keys
     * @param bool $withNewline If true, add newline before PARTITION BY
     */
    protected function writePartitionByClause($tableInfo, $withNewline = false)
    {
        if (!isset($tableInfo['partstrat']) || !$tableInfo['partstrat']) {
            return;
        }

        $strategy = PartitionActions::PARTITION_STRATEGY_MAP[$tableInfo['partstrat']] ?? null;
        if (!$strategy) {
            return;
        }

        // Parse partition keys from array
        $partitionKeys = $tableInfo['partition_keys'];
        if (is_string($partitionKeys)) {
            // Parse PostgreSQL array format: {col1,col2}
            $partitionKeys = trim($partitionKeys, '{}');
            $keys = array_map('trim', explode(',', $partitionKeys));
        } elseif (is_array($partitionKeys)) {
            $keys = $partitionKeys;
        } else {
            return;
        }

        // Quote each key
        $quotedKeys = [];
        foreach ($keys as $key) {
            $quotedKeys[] = $this->connection->quoteIdentifier($key);
        }

        if ($withNewline) {
            $this->write("\n    PARTITION BY {$strategy} (" . implode(', ', $quotedKeys) . ")");
        } else {
            $this->write(" PARTITION BY {$strategy} (" . implode(', ', $quotedKeys) . ")");
        }
    }

    /**
     * Write indexes for the table.
     * 
     * @param string $table Table name
     * @param array $options Dump options
     * @param bool $checkInheritance If true, skip inherited indexes (for partitions)
     * @param string $sectionLabel Label for the indexes section comment
     */
    protected function writeIndexes($table, $options, $checkInheritance = false, $sectionLabel = 'Indexes')
    {
        $indexActions = new IndexActions($this->connection);
        $indexes = $indexActions->getIndexes($table);

        if (!is_object($indexes) || $indexes->EOF) {
            return;
        }

        $hasIndexes = false;

        while (!$indexes->EOF) {
            if ($this->connection->phpBool($indexes->fields['indisprimary'])) {
                // Skip primary key index (created with constraint)
                $indexes->moveNext();
                continue;
            }

            // Check if this is an inherited index (for partitions in PG11+)
            if ($checkInheritance && $this->connection->major_version >= 11) {
                $indexOid = $indexes->fields['indexrelid'] ?? null;
                if ($indexOid && $this->isInheritedIndex($indexOid)) {
                    $indexes->moveNext();
                    continue;
                }
            }

            if (!$hasIndexes) {
                $this->write("\n-- {$sectionLabel}\n\n");
                $hasIndexes = true;
            }

            $def = $indexes->fields['inddef'];

            if (!empty($options['if_not_exists'])) {
                if ($this->connection->major_version >= 9.5) {
                    $def = str_replace('CREATE UNIQUE INDEX', 'CREATE UNIQUE INDEX IF NOT EXISTS', $def);
                    $def = str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $def);
                }
            }
            $this->write("$def;\n");
            $indexes->moveNext();
        }
    }

    /**
     * Apply deferred constraints after data import.
     * 
     * @param array $options Dump options
     * @param string $sectionLabel Label for the constraints section comment
     */
    protected function applyDeferredConstraints($options, $sectionLabel = 'Constraints')
    {
        if (empty($this->deferredConstraints)) {
            return;
        }

        $this->write("\n-- {$sectionLabel}\n\n");

        foreach ($this->deferredConstraints as $constraint) {
            $this->write("ALTER TABLE {$this->schemaQuoted}.{$this->tableQuoted} ");
            $this->write("ADD CONSTRAINT {$constraint['name']} {$constraint['definition']};\n");
        }
    }
}
