<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\RuleActions;
use PhpPgAdmin\Database\Cursor\CursorReader;
use PhpPgAdmin\Database\Export\SqlFormatter;
use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\TriggerActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Dump\DependencyGraph\DependencyGraph;

/**
 * Dumper for PostgreSQL tables (structure and data).
 */
class TableDumper extends TableBaseDumper
{
    // Advanced features specific to TableDumper (not in other dumpers)
    private $tableOid;
    private $deferredIndexes = [];
    private $deferredDefaults = [];
    private $deferredGeneratedColumns = [];

    /**
     * @var DependencyGraph|null
     */
    private $dependencyGraph = null;

    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        $this->initializeQuotedIdentifiers($table, $schema);

        // Get table OID for dependency analysis
        $this->tableOid = $this->getTableOid($table, $schema);

        // Accept dependency graph if passed in options
        $this->dependencyGraph = $options['dependency_graph'] ?? null;

        $this->write("\n-- Table: \"{$schema}\".\"{$table}\"\n\n");

        if (empty($options['data_only'])) {
            // Reset deferred constraints/indexes/defaults for this table
            $this->deferredConstraints = [];
            $this->deferredIndexes = [];
            $this->deferredDefaults = [];
            $this->deferredGeneratedColumns = [];

            // Use existing logic from TableActions/Postgres driver but adapted
            // Use writer-style method instead of getting SQL back
            $this->dumpTableStructure($table, $options);

            $this->dumpAutovacuumSettings($table, $schema);
        }

        if (empty($options['structure_only'])) {
            $this->dumpData($table, $schema, $options, 'table');
        }

        if (empty($options['data_only'])) {
            // Apply deferred items AFTER data import for better performance
            $this->applyDeferredDefaults($options);
            $this->applyDeferredGeneratedColumns($options);
            $this->applyDeferredConstraints($options);
            $this->writeIndexes($table, $options);
            $this->deferTriggers($table, $schema, $options);
            $this->deferRules($table, $schema, $options);
        }

        // Register this table as dumped (for sequence ownership validation)
        if ($this->parentDumper instanceof SchemaDumper) {
            $this->parentDumper->registerDumpedTable($schema, $table);
        }
    }

    /**
     * Write table definition prefix (columns, constraints, comments, privileges).
     * Returns true on success, false on failure or missing table.
     */
    protected function dumpTableStructure($table, $options)
    {
        $tableActions = new TableActions($this->connection);
        $t = $tableActions->getTable($table);
        if (!is_object($t) || $t->recordCount() != 1) {
            return false;
        }

        $atts = $tableActions->getTableAttributes($table);
        if (!is_object($atts)) {
            return false;
        }

        $constraintActions = new ConstraintActions($this->connection);
        $cons = $constraintActions->getConstraints($table);
        if (!is_object($cons)) {
            return false;
        }

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
                // Generated stored column - check if we should defer it
                if ($atts->fields['adsrc'] !== null) {
                    if ($this->shouldDeferExpression($atts->fields['adsrc'])) {
                        // Defer generated column - will be added later
                        $this->deferredGeneratedColumns[] = [
                            'column' => $atts->fields['attname'],
                            'type' => $atts->fields['type'],
                            'expression' => $atts->fields['adsrc'],
                        ];
                        // Don't write the GENERATED clause now
                    } else {
                        // Safe to write inline
                        $this->write(" GENERATED ALWAYS AS ({$atts->fields['adsrc']}) STORED");
                    }
                }
            } else {
                // Regular column - handle NOT NULL and DEFAULT
                if ($this->connection->phpBool($atts->fields['attnotnull'])) {
                    $this->write(" NOT NULL");
                }

                if ($atts->fields['adsrc'] !== null) {
                    // Check if default should be deferred
                    if ($this->shouldDeferExpression($atts->fields['adsrc'])) {
                        // Defer default - will be applied later
                        $this->deferredDefaults[] = [
                            'column' => $atts->fields['attname'],
                            'expression' => $atts->fields['adsrc'],
                        ];
                    } else {
                        // Safe to write inline
                        $this->write(" DEFAULT {$atts->fields['adsrc']}");
                    }
                }
            }

            if ($atts->fields['comment'] !== null) {
                $comment = $this->connection->escapeString($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN {$this->schemaQuoted}.{$this->tableQuoted}.{$this->connection->quoteIdentifier($atts->fields['attname'])} IS '{$comment}';\n";
            }

            $atts->moveNext();
        }

        // Store constraints for deferred application (except NOT NULL)
        while (!$cons->EOF) {
            if ($cons->fields['contype'] == 'n') {
                // Skip NOT NULL constraints as they are dumped with the column definition
                $cons->moveNext();
                continue;
            }

            $name = $this->connection->quoteIdentifier($cons->fields['conname']);
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
                        // Foreign key - should not happen as consrc is always populated
                        $src = $cons->fields['consrc'];
                        break;
                    case 'c':
                        // Check constraint - should not happen as consrc is always populated
                        $src = $cons->fields['consrc'];
                        break;
                    default:
                        $cons->moveNext();
                        continue 2;
                }
            }

            // Foreign keys are ALWAYS deferred to SchemaDumper level to avoid forward references
            if ($cons->fields['contype'] === 'f') {
                $this->deferForeignKeyToParent($name, $src);
                $cons->moveNext();
                continue;
            }

            // Store constraint for later application (this table only)
            $shouldDefer = false;

            // For check constraints, determine if we need to defer based on dependencies
            if ($cons->fields['contype'] === 'c' && $this->containsFunctionCall($src)) {
                $shouldDefer = $this->shouldDeferExpression($src);
            }

            $this->deferredConstraints[] = [
                'name' => $name,
                'definition' => $src,
                'type' => $cons->fields['contype'],
                'should_defer' => $shouldDefer,  // Mark if this needs NOT VALID
            ];

            $cons->moveNext();
        }

        $this->write("\n)");

        if ($this->connection->hasObjectID($table)) {
            $this->write(" WITH OIDS");
        } else {
            $this->write(" WITHOUT OIDS");
        }

        $this->write(";\n");

        // per-column ALTERs (statistics, storage)
        $atts->moveFirst();
        $first = true;
        while (!$atts->EOF) {
            $fieldQuoted = $this->connection->quoteIdentifier($atts->fields['attname']);

            // Set sequence ownership if applicable
            if (!empty($atts->fields['sequence_name'])) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $sequenceQuoted = $this->connection->quoteIdentifier($atts->fields['sequence_name']);
                $this->write("\nALTER SEQUENCE {$this->schemaQuoted}.{$sequenceQuoted} OWNED BY {$this->schemaQuoted}.{$this->tableQuoted}.{$fieldQuoted};\n");
            }

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
                    default:
                        return false;
                }
                $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STORAGE {$storage};\n");
            }

            $atts->moveNext();
        }

        // table comment
        if ($t->fields['relcomment'] !== null) {
            $comment = $this->connection->escapeString($t->fields['relcomment']);
            $this->write("\n-- Comment\n\n");
            $this->write("COMMENT ON TABLE {$this->schemaQuoted}.{$this->tableQuoted} IS '{$comment}';\n");
        }

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

    /**
     * Apply deferred constraints after data import.
     * Overrides parent to add validation logic for deferred check constraints.
     */
    protected function applyDeferredConstraints($options, $sectionLabel = 'Constraints (applied after data import)')
    {
        if (empty($this->deferredConstraints)) {
            return;
        }

        $this->write("\n-- {$sectionLabel}\n\n");

        $needsValidation = [];

        foreach ($this->deferredConstraints as $constraint) {
            $this->write("ALTER TABLE {$this->schemaQuoted}.{$this->tableQuoted} ");
            $this->write("ADD CONSTRAINT {$constraint['name']} {$constraint['definition']}");

            // For check constraints that were deferred due to function dependencies, use NOT VALID
            if ($constraint['type'] === 'c' && !empty($constraint['should_defer'])) {
                $this->write(" NOT VALID");
                $needsValidation[] = $constraint['name'];
            }

            $this->write(";\n");
        }

        // Validate deferred check constraints
        if (!empty($needsValidation)) {
            $this->write("\n-- Validate deferred check constraints\n\n");
            foreach ($needsValidation as $constraintName) {
                $this->write("ALTER TABLE {$this->schemaQuoted}.{$this->tableQuoted} ");
                $this->write("VALIDATE CONSTRAINT {$constraintName};\n");
            }
        }
    }

    /**
     * Get table OID for dependency analysis.
     * Handles both regular tables (relkind 'r') and partitioned tables (relkind 'p').
     *
     * @param string $table Table name
     * @param string $schema Schema name
     * @return string|null Table OID or null if not found
     */
    private function getTableOid($table, $schema)
    {
        $tableEsc = $this->connection->escapeString($table);
        $schemaEsc = $this->connection->escapeString($schema);

        $sql = "SELECT c.oid
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relname = '$tableEsc'
                AND n.nspname = '$schemaEsc'
                AND c.relkind IN ('r', 'p')";

        $result = $this->connection->selectSet($sql);

        if ($result && !$result->EOF) {
            return $result->fields['oid'];
        }

        return null;
    }

    /**
     * Check if an expression contains a function call.
     *
     * @param string $expr Expression to check
     * @return bool True if expression contains function call
     */
    private function containsFunctionCall($expr)
    {
        // Simple pattern to detect function calls
        return preg_match('/\w+\s*\(/i', $expr) === 1;
    }

    /**
     * Determine if an expression should be deferred based on dependency graph.
     *
     * @param string $expr Expression to check
     * @return bool True if expression should be deferred
     */
    private function shouldDeferExpression($expr)
    {
        // If no dependency graph provided, defer by default for safety
        if (!$this->dependencyGraph || !$this->tableOid) {
            return $this->containsFunctionCall($expr);
        }

        // If expression doesn't contain functions, don't defer
        if (!$this->containsFunctionCall($expr)) {
            return false;
        }

        // Extract function OIDs from expression
        $functionOids = $this->extractFunctionOids($expr);

        // If any function comes after this table in dump order, defer
        foreach ($functionOids as $funcOid) {
            if ($this->dependencyGraph->shouldDefer($this->tableOid, $funcOid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract function OIDs referenced in an expression.
     *
     * @param string $expr Expression to analyze
     * @return array Array of function OIDs
     */
    private function extractFunctionOids($expr)
    {
        $oids = [];

        // Pattern matches: function_name( or schema.function_name(
        if (preg_match_all('/(?:(\w+)\.)?(\w+)\s*\(/i', $expr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $schema = $match[1] ?: null;
                $funcName = $match[2];

                // Skip common built-ins
                if (self::isBuiltinFunction($funcName)) {
                    continue;
                }

                $funcOid = $this->resolveFunctionOid($funcName, $schema);
                if ($funcOid) {
                    $oids[] = $funcOid;
                }
            }
        }

        return $oids;
    }

    /**
     * Resolve function name to OID.
     *
     * @param string $funcName Function name
     * @param string|null $schema Optional schema name
     * @return string|null Function OID or null if not found
     */
    private function resolveFunctionOid($funcName, $schema = null)
    {
        $funcName = $this->connection->escapeString($funcName);

        if ($schema) {
            $schema = $this->connection->escapeString($schema);
            $sql = "SELECT p.oid
                    FROM pg_catalog.pg_proc p
                    JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                    WHERE p.proname = '$funcName'
                    AND n.nspname = '$schema'
                    AND p.prokind = 'f'
                    LIMIT 1";
        } else {
            // Look in current schema or search_path
            $sql = "SELECT p.oid
                    FROM pg_catalog.pg_proc p
                    WHERE p.proname = '$funcName'
                    AND p.prokind = 'f'
                    LIMIT 1";
        }

        $result = $this->connection->selectSet($sql);

        if ($result && !$result->EOF) {
            return $result->fields['oid'];
        }

        return null;
    }

    /**
     * Apply deferred DEFAULT expressions.
     */
    private function applyDeferredDefaults($options)
    {
        if (empty($this->deferredDefaults)) {
            return;
        }

        $this->write("\n-- Deferred Defaults\n\n");

        foreach ($this->deferredDefaults as $default) {
            $columnQuoted = $this->connection->quoteIdentifier($default['column']);
            $this->write("ALTER TABLE {$this->schemaQuoted}.{$this->tableQuoted} ");
            $this->write("ALTER COLUMN {$columnQuoted} SET DEFAULT {$default['expression']};\n");
        }
    }

    /**
     * Apply deferred generated columns.
     */
    private function applyDeferredGeneratedColumns($options)
    {
        if (empty($this->deferredGeneratedColumns)) {
            return;
        }

        $this->write("\n-- Deferred Generated Columns\n\n");

        foreach ($this->deferredGeneratedColumns as $genCol) {
            $columnQuoted = $this->connection->quoteIdentifier($genCol['column']);
            $this->write("ALTER TABLE {$this->schemaQuoted}.{$this->tableQuoted} ");
            $this->write("ADD COLUMN {$columnQuoted} {$genCol['type']} ");
            $this->write("GENERATED ALWAYS AS ({$genCol['expression']}) STORED;\n");
        }
    }

}
