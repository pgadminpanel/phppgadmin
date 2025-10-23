<?php

namespace PhpPgAdmin\Database\Dump\DependencyGraph;

use PhpPgAdmin\Database\Postgres;
use PhpPgAdmin\Database\Dump\ExportDumper;

/**
 * Analyzes PostgreSQL catalog to extract dependencies between database objects.
 * 
 * Queries pg_depend, pg_proc, pg_type, pg_class to build a comprehensive
 * dependency graph for functions, tables, and domains.
 */
class DependencyAnalyzer
{
    /**
     * @var Postgres Database connection
     */
    private $connection;

    /**
     * @var array Schemas in scope for dependency analysis
     */
    private $schemasInScope;

    /**
     * @var array Cache of type OID to typrelid mapping
     */
    private $typeCache = [];

    /**
     * Create analyzer instance.
     *
     * @param Postgres $connection Database connection
     * @param array $schemasInScope Array of schema names to include
     */
    public function __construct(Postgres $connection, array $schemasInScope)
    {
        $this->connection = $connection;
        $this->schemasInScope = $schemasInScope;
    }

    /**
     * Build complete dependency graph for objects in scope.
     * 
     * Note: Domains are excluded as they are dumped separately at the beginning.
     *
     * @return DependencyGraph Populated dependency graph
     */
    public function buildGraph()
    {
        $graph = new DependencyGraph();

        // Load all objects into graph as nodes
        $this->loadFunctions($graph);
        $this->loadTables($graph);
        $this->loadPartitionedTables($graph);
        $this->loadPartitions($graph);
        // Note: Domains are dumped separately before topological analysis
        $this->loadAggregates($graph);

        // Build type cache for efficient lookups
        $this->buildTypeCache();

        // Add dependency edges
        $this->addFunctionToFunctionDependencies($graph);
        $this->addFunctionToTableDependencies($graph);
        $this->addTableToFunctionDependencies($graph);
        $this->addTableToTableDependencies($graph);
        // Note: Domain dependencies are handled separately in SchemaDumper::dumpDomains()
        $this->addAggregateToFunctionDependencies($graph);

        return $graph;
    }

    /**
     * Load all functions in scope as graph nodes.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadFunctions(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Use prokind for PostgreSQL 11+, proisagg for older versions
        if ($this->connection->major_version >= 11) {
            $funcFilter = "p.prokind = 'f'";
        } else {
            $funcFilter = "NOT p.proisagg";
        }

        $sql = "SELECT p.oid, p.proname, n.nspname
                FROM pg_catalog.pg_proc p
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)
                AND $funcFilter
                ORDER BY p.proname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'function',
                $result->fields['proname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Load all regular tables in scope as graph nodes.
     * Excludes partitioned tables and partitions (handled separately).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadTables(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Regular tables only (relkind 'r'), exclude child partitions
        $sql = "SELECT c.oid, c.relname, n.nspname
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname IN ($schemaList)
                AND c.relkind = 'r'
                AND NOT EXISTS (
                    SELECT 1 FROM pg_inherits i
                    JOIN pg_class p ON p.oid = i.inhparent
                    WHERE i.inhrelid = c.oid AND p.relkind = 'p'
                )
                ORDER BY c.relname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'table',
                $result->fields['relname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Load all partitioned tables (top-level parent tables) in scope as graph nodes.
     * Excludes sub-partitioned tables (partitions that are themselves partitioned).
     * PostgreSQL 10+ feature.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadPartitionedTables(DependencyGraph $graph)
    {
        // Partitioned tables require PostgreSQL 10+
        if ($this->connection->major_version < 10) {
            return;
        }

        $schemaList = $this->escapeSchemaList();

        // Only load top-level partitioned tables (those that are NOT partitions of another table)
        $sql = "SELECT c.oid, c.relname, n.nspname
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname IN ($schemaList)
                AND c.relkind = 'p'
                AND NOT EXISTS (
                    SELECT 1 FROM pg_inherits i
                    JOIN pg_class p ON p.oid = i.inhparent
                    WHERE i.inhrelid = c.oid AND p.relkind = 'p'
                )
                ORDER BY c.relname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'partitioned_table',
                $result->fields['relname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Load all partitions (child tables of partitioned tables) in scope as graph nodes.
     * Adds dependency edges from partitions to their parent partitioned tables.
     * PostgreSQL 10+ feature.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadPartitions(DependencyGraph $graph)
    {
        // Partitions require PostgreSQL 10+
        if ($this->connection->major_version < 10) {
            return;
        }

        $schemaList = $this->escapeSchemaList();

        // Get partitions with their parent OID
        // Partitions can have relkind 'r' (regular table) or 'p' (sub-partitioned table for multi-level partitioning)
        $sql = "SELECT c.oid, c.relname, n.nspname, c.relkind, i.inhparent AS parent_oid
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_inherits i ON i.inhrelid = c.oid
                JOIN pg_class p ON p.oid = i.inhparent AND p.relkind = 'p'
                WHERE n.nspname IN ($schemaList)
                AND c.relkind IN ('r', 'p')
                ORDER BY c.relname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            // Determine if this is a leaf partition (r) or a sub-partitioned table (p)
            $isSubPartitioned = $result->fields['relkind'] === 'p';

            $node = new ObjectNode(
                $result->fields['oid'],
                $isSubPartitioned ? 'sub_partitioned_table' : 'partition',
                $result->fields['relname'],
                $result->fields['nspname'],
                ['parent_oid' => $result->fields['parent_oid']] // Store parent OID in metadata
            );
            $graph->addNode($node);

            // Add dependency edge: partition depends on parent partitioned table
            // Edge direction: partition → parent (so parent is dumped first in topological sort)
            $graph->addEdge($result->fields['oid'], $result->fields['parent_oid']);

            $result->moveNext();
        }
    }

    /**
     * Load all domains in scope as graph nodes.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadDomains(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        $sql = "SELECT t.oid, t.typname, n.nspname
                FROM pg_catalog.pg_type t
                JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
                WHERE n.nspname IN ($schemaList)
                AND t.typtype = 'd'
                ORDER BY t.typname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'domain',
                $result->fields['typname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Load all aggregates in scope as graph nodes.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function loadAggregates(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Use prokind for PostgreSQL 11+, proisagg for older versions
        if ($this->connection->major_version >= 11) {
            $aggFilter = "p.prokind = 'a'";
        } else {
            $aggFilter = "p.proisagg";
        }

        $sql = "SELECT p.oid, p.proname, n.nspname
                FROM pg_catalog.pg_proc p
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)
                AND $aggFilter
                ORDER BY p.proname";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $node = new ObjectNode(
                $result->fields['oid'],
                'aggregate',
                $result->fields['proname'],
                $result->fields['nspname']
            );
            $graph->addNode($node);
            $result->moveNext();
        }
    }

    /**
     * Build cache mapping type OIDs to their backing table OIDs.
     * Handles array types by resolving through typelem.
     */
    private function buildTypeCache()
    {
        $sql = "SELECT t.oid, t.typrelid, t.typelem, t.typtype
                FROM pg_catalog.pg_type t
                WHERE t.typrelid != 0 OR t.typelem != 0";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $this->typeCache[$result->fields['oid']] = [
                'typrelid' => $result->fields['typrelid'],
                'typelem' => $result->fields['typelem'],
                'typtype' => $result->fields['typtype'],
            ];
            $result->moveNext();
        }
    }

    /**
     * Resolve a type OID to its backing table OID (if any).
     * Recursively resolves array types.
     *
     * @param string $typeOid Type OID to resolve
     * @return string|null Table OID or null if not a composite type
     */
    private function resolveTypeToTable($typeOid)
    {
        if (!isset($this->typeCache[$typeOid])) {
            return null;
        }

        $typeInfo = $this->typeCache[$typeOid];

        // If it's an array type, resolve the element type
        if ($typeInfo['typelem'] != 0) {
            return $this->resolveTypeToTable($typeInfo['typelem']);
        }

        // If it has a backing table, return it
        if ($typeInfo['typrelid'] != 0) {
            return $typeInfo['typrelid'];
        }

        return null;
    }

    /**
     * Add function → function dependencies from pg_depend.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addFunctionToFunctionDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        if ($this->connection->major_version >= 11) {
            $funcFilter = "p.prokind = 'f'";
        } else {
            $funcFilter = "NOT p.proisagg";
        }

        // PostgreSQL does not record function-body dependencies in pg_depend,
        // so we parse pg_proc.prosrc to find called functions.
        $sql = "SELECT p.oid AS func_oid, p.prosrc
                FROM pg_catalog.pg_proc p
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)
                AND $funcFilter";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $funcOid = $result->fields['func_oid'];
            $body = $result->fields['prosrc'];

            // Reuse the expression parser: it extracts function calls from the
            // body and adds edges "sourceOid -> funcOid" (source depends on func).
            $this->extractFunctionDependencies($graph, $funcOid, $body);

            $result->moveNext();
        }
    }

    /**
     * Add function → table dependencies (composite type usage).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addFunctionToTableDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Use version-appropriate filter
        if ($this->connection->major_version >= 11) {
            $funcFilter = "p.prokind = 'f'";
        } else {
            $funcFilter = "NOT p.proisagg";
        }

        // Get functions with their return types and argument types
        $sql = "SELECT 
                    p.oid AS func_oid,
                    p.prorettype,
                    p.proargtypes,
                    p.proallargtypes
                FROM pg_catalog.pg_proc p
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)
                AND $funcFilter";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $funcOid = $result->fields['func_oid'];
            $typeOids = [];

            // Add return type
            $typeOids[] = $result->fields['prorettype'];

            // Add argument types from proargtypes (IN arguments)
            if ($result->fields['proargtypes']) {
                $argtypes = explode(' ', trim($result->fields['proargtypes']));
                $typeOids = array_merge($typeOids, $argtypes);
            }

            // Add all argument types from proallargtypes (includes OUT, INOUT)
            if ($result->fields['proallargtypes']) {
                $allArgtypes = trim($result->fields['proallargtypes'], '{}');
                if ($allArgtypes) {
                    $alltypes = explode(',', $allArgtypes);
                    $typeOids = array_merge($typeOids, $alltypes);
                }
            }

            // Resolve each type to see if it's backed by a table
            foreach ($typeOids as $typeOid) {
                $typeOid = trim($typeOid);
                if (empty($typeOid) || $typeOid === '0') {
                    continue;
                }

                $tableOid = $this->resolveTypeToTable($typeOid);
                if ($tableOid) {
                    // Function depends on this table's composite type
                    // Edge direction: function → table (function depends on table, so table comes first)
                    $tableNode = $graph->getNode($tableOid);
                    if ($tableNode && ($tableNode->type === 'table' || $tableNode->type === 'partitioned_table')) {
                        $graph->addEdge($funcOid, $tableOid);
                    }
                }
            }

            $result->moveNext();
        }
    }

    /**
     * Add table → function dependencies (from defaults and check constraints).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addTableToFunctionDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Get default expressions and generated column expressions
        $sql = "SELECT 
                    a.attrelid AS table_oid,
                    pg_catalog.pg_get_expr(ad.adbin, ad.adrelid, true) AS expr
                FROM pg_catalog.pg_attribute a
                JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                LEFT JOIN pg_catalog.pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
                WHERE n.nspname IN ($schemaList)
                AND c.relkind = 'r'
                AND a.attnum > 0
                AND NOT a.attisdropped
                AND ad.adbin IS NOT NULL";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $tableOid = $result->fields['table_oid'];
            $expr = $result->fields['expr'];

            $this->extractFunctionDependencies($graph, $tableOid, $expr);
            $result->moveNext();
        }

        // Get check constraints
        $sql = "SELECT 
                    con.conrelid AS table_oid,
                    pg_catalog.pg_get_constraintdef(con.oid, true) AS expr
                FROM pg_catalog.pg_constraint con
                JOIN pg_catalog.pg_class c ON c.oid = con.conrelid
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname IN ($schemaList)
                AND c.relkind = 'r'
                AND con.contype = 'c'";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $tableOid = $result->fields['table_oid'];
            $expr = $result->fields['expr'];

            $this->extractFunctionDependencies($graph, $tableOid, $expr);
            $result->moveNext();
        }
    }

    /**
     * Add table → table dependencies from foreign keys.
     *
     * Foreign-key constraints are still deferred during table creation, but the
     * referenced table must be dumped before the referencing table so imports
     * can be replayed in dependency order.
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addTableToTableDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        $sql = "SELECT DISTINCT
                    c.conrelid AS table_oid,
                    c.confrelid AS depends_on_oid
                FROM pg_catalog.pg_constraint c
                JOIN pg_catalog.pg_class src ON src.oid = c.conrelid
                JOIN pg_catalog.pg_namespace nsrc ON nsrc.oid = src.relnamespace
                JOIN pg_catalog.pg_class dst ON dst.oid = c.confrelid
                JOIN pg_catalog.pg_namespace ndst ON ndst.oid = dst.relnamespace
                WHERE c.contype = 'f'
                AND src.relkind IN ('r', 'p')
                AND dst.relkind IN ('r', 'p')
                AND nsrc.nspname IN ($schemaList)
                AND ndst.nspname IN ($schemaList)
                AND c.conrelid <> c.confrelid";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $sourceOid = $result->fields['table_oid'];
            $targetOid = $result->fields['depends_on_oid'];

            $sourceNode = $graph->getNode($sourceOid);
            $targetNode = $graph->getNode($targetOid);

            if ($sourceNode && $targetNode) {
                // Edge direction: referencing table depends on referenced table.
                $graph->addEdge($sourceOid, $targetOid);
            }

            $result->moveNext();
        }
    }

    /**     
     * Add dependencies for aggregates on their supporting functions.
     * Aggregates depend on:
     * - aggtransfn (SFUNC)
     * - aggfinalfn (FINALFUNC)
     * - aggcombinefn (COMBINEFUNC)
     * - aggserialfn (SERIALFUNC)
     * - aggdeserialfn (DESERIALFUNC)
     * - aggmtransfn (MSFUNC)
     * - aggminvtransfn (MINVFUNC)
     * - aggmfinalfn (MFINALFUNC)
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addAggregateToFunctionDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Query pg_aggregate to get all function dependencies
        $sql = "SELECT agg.aggfnoid,
                       agg.aggtransfn, agg.aggfinalfn, agg.aggcombinefn,
                       agg.aggserialfn, agg.aggdeserialfn,
                       agg.aggmtransfn, agg.aggminvtransfn, agg.aggmfinalfn,
                       n.nspname
                FROM pg_catalog.pg_aggregate agg
                JOIN pg_catalog.pg_proc p ON p.oid = agg.aggfnoid
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname IN ($schemaList)";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $aggOid = $result->fields['aggfnoid'];

            // All the function fields that an aggregate can depend on
            $functionFields = [
                'aggtransfn',
                'aggfinalfn',
                'aggcombinefn',
                'aggserialfn',
                'aggdeserialfn',
                'aggmtransfn',
                'aggminvtransfn',
                'aggmfinalfn'
            ];

            foreach ($functionFields as $field) {
                $funcOid = $result->fields[$field];
                if ($funcOid && $funcOid !== '0' && $funcOid !== '-') {
                    // Aggregate depends on function, so function must come first
                    $graph->addEdge($aggOid, $funcOid);
                }
            }

            $result->moveNext();
        }
    }

    /**     * Add table → domain dependencies (tables using domains in columns).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addTableToDomainDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        // Find all table columns that use domain types
        // Include regular tables ('r') and partitioned tables ('p')
        $sql = "SELECT DISTINCT 
                    c.oid AS table_oid,
                    t.oid AS domain_oid
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace nc ON nc.oid = c.relnamespace
                JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
                JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
                JOIN pg_catalog.pg_namespace nt ON nt.oid = t.typnamespace
                WHERE nc.nspname IN ($schemaList)
                AND c.relkind IN ('r', 'p')
                AND t.typtype = 'd'
                AND a.attnum > 0
                AND NOT a.attisdropped";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $tableOid = $result->fields['table_oid'];
            $domainOid = $result->fields['domain_oid'];

            // Edge: table depends on domain (domain must be created before table)
            $graph->addEdge($tableOid, $domainOid);

            $result->moveNext();
        }
    }

    /**
     * Add domain → function dependencies (from check constraints).
     *
     * @param DependencyGraph $graph Graph to populate
     */
    private function addDomainToFunctionDependencies(DependencyGraph $graph)
    {
        $schemaList = $this->escapeSchemaList();

        $sql = "SELECT 
                    t.oid AS domain_oid,
                    pg_catalog.pg_get_constraintdef(con.oid, true) AS expr
                FROM pg_catalog.pg_type t
                JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
                JOIN pg_catalog.pg_constraint con ON con.contypid = t.oid
                WHERE n.nspname IN ($schemaList)
                AND t.typtype = 'd'
                AND con.contype = 'c'";

        $result = $this->connection->selectSet($sql);

        while ($result && !$result->EOF) {
            $domainOid = $result->fields['domain_oid'];
            $expr = $result->fields['expr'];

            $this->extractFunctionDependencies($graph, $domainOid, $expr);
            $result->moveNext();
        }
    }

    /**
     * Extract function dependencies from an expression.
     * Looks for function call patterns and resolves them to OIDs.
     *
     * @param DependencyGraph $graph Graph to populate
     * @param string $sourceOid OID of source object (table/domain)
     * @param string $expr Expression to analyze
     */
    private function extractFunctionDependencies(DependencyGraph $graph, $sourceOid, $expr)
    {
        // Pattern matches: function_name( or schema.function_name(
        // Captures schema-qualified and unqualified function calls
        if (preg_match_all('/(?:(\w+)\.)?(\w+)\s*\(/i', $expr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $schema = $match[1] ?: null;
                $funcName = $match[2];

                // Skip common built-in functions
                if (ExportDumper::isBuiltinFunction($funcName)) {
                    continue;
                }

                $funcOid = $this->resolveFunctionName($funcName, $schema);
                if ($funcOid) {
			     // Edge direction: source → function (source depends on function, function must come first)
			     // e.g., for table default: table → function
			     $graph->addEdge($sourceOid, $funcOid);
                }
            }
        }
    }

    /**
     * Resolve function name (optionally schema-qualified) to OID.
     *
     * @param string $funcName Function name
     * @param string|null $schema Optional schema name
     * @return string|null Function OID or null if not found
     */
    private function resolveFunctionName($funcName, $schema = null)
    {
        $schemaList = $this->escapeSchemaList();
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
            $sql = "SELECT p.oid
                    FROM pg_catalog.pg_proc p
                    JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                    WHERE p.proname = '$funcName'
                    AND n.nspname IN ($schemaList)
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
     * Escape and format schema list for SQL IN clause.
     *
     * @return string Comma-separated escaped schema names
     */
	private function escapeSchemaList()
	{
	    $escaped = '';
	    $sep = "";
	    foreach ($this->schemasInScope as $schema) {
	        $escaped .= $sep . "'";
	        $escaped .= $this->connection->escapeString($schema);
	        $escaped .= "'";
	        $sep = ", ";
	    }
	    return $escaped;
	}
}