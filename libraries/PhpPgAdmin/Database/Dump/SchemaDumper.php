<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\ViewActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Dump\DependencyGraph\DependencyGraph;
use PhpPgAdmin\Database\Dump\DependencyGraph\DependencyAnalyzer;

/**
 * Orchestrator dumper for a PostgreSQL schema.
 */
class SchemaDumper extends ExportDumper
{
	private $selectedObjects = [];
	private $hasObjectSelection = false;

	private $schemaEscaped = '';
	private $schemaQuoted = '';

	// Deferred statement collections
	private $deferredTriggers = [];
	private $deferredRules = [];
	private $deferredSequenceOwnerships = [];
	private $deferredMaterializedViewRefreshes = [];
	private $deferredForeignKeys = [];
	private $deferredDomainConstraints = [];
	private $dumpedTables = [];
	private $dumpedFunctions = [];
	private $dumpedDomains = [];

	/**
	 * @var DependencyGraph|null Dependency graph for smart ordering
	 */
	private $dependencyGraph = null;

	public function dump($subject, array $params, array $options = [])
	{
		$this->setDumpOptions($options);

		$schema = $params['schema'] ?? $this->connection->_schema;
		if (!$schema) {
			return;
		}

		$this->schemaEscaped = $this->connection->escapeString($schema);
		$this->schemaQuoted = $this->connection->quoteIdentifier($schema);

		// Get list of selected objects (tables/views/sequences)
		$this->selectedObjects = $options['objects'] ?? [];
		$this->hasObjectSelection = isset($options['objects']);
		$this->selectedObjects = array_combine($this->selectedObjects, $this->selectedObjects);
		$includeSchemaObjects = $options['include_schema_objects'] ?? true;

		$schemaActions = new SchemaActions($this->connection);
		$rs = $schemaActions->getSchemaByName($schema);
		if (!$rs || $rs->EOF) {
			return;
		}

		// Save and set schema context for Actions that depend on it
		$oldSchema = $this->connection->_schema;
		$this->connection->_schema = $schema;

		// Write standard dump header for schema exports
		$this->writeHeader("Schema: $this->schemaQuoted");
		$this->writeConnectHeader();

		// Optional schema creation for super users
		if (!empty($options['add_create_schema'])) {
			$this->writeDrop('SCHEMA', $this->schemaQuoted, $options);
			$this->write("CREATE SCHEMA " . $this->getIfNotExists($options) . $this->schemaQuoted . ";\n");
		}

		// 0. Owner
		$this->writeOwner(
			$this->schemaQuoted,
			'SCHEMA',
			$rs->fields['ownername']
		);

		// 1. Domains and composite types (with unified dependency sorting)
		// These can have cross-dependencies so must be sorted together
		if ($includeSchemaObjects) {
			$this->dumpDomainsAndCompositeTypes($schema, $options);
		}

		// 2. Simple types (enums, base types) - no dependencies on user types
		if ($includeSchemaObjects) {
			$this->dumpSimpleTypes($schema, $options);
		}

		// 3. Sequences (without ownership - deferred)
		$this->dumpSequences($schema, $options);

		// 4. UNIFIED TOPOLOGICAL DUMP: Functions, Tables, Aggregates with dependency analysis
		$this->dumpObjectsTopologically($schema, $options);
		
		// 5  Operators (after functions, since operators reference functions)
		if ($includeSchemaObjects) {
			$this->dumpOperators($schema, $options);
		}
		// 6. Views (regular views with dependency sorting)
		$this->dumpViews($schema, $options);

		// 7. Materialized Views (WITH NO DATA, refresh deferred)
		if ($includeSchemaObjects) {
			$this->dumpMaterializedViews($schema, $options);
		}

		// 8. Apply deferred objects after all structure is created
		$this->applyDeferredForeignKeys($options);
		$this->applyDeferredMaterializedViewRefreshes($options);
		$this->applyDeferredViews($schema, $options);
		$this->applyDeferredRules($options);
		$this->applyDeferredTriggers($options);
		$this->applyDeferredSequenceOwnerships($options);
		$this->applyDeferredDomainConstraints();

		// 9. Privileges
		$this->writePrivileges(
			$schema,
			'schema',
			$rs->fields['ownername'],
			$rs->fields['nspacl']
		);

		// Restore original schema context
		$this->connection->_schema = $oldSchema;

		$this->writeConnectFooter();
		$this->writeFooter();
	}

	/**
	 * Dump simple types (enums, base types) that don't have constraint dependencies.
	 *
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpSimpleTypes($schema, $options)
	{
		$this->write("\n-- Types in schema $this->schemaQuoted\n");

		// When specific objects are selected, check if dependencies should be included
		if ($this->hasObjectSelection) {

			if (!($options['include_dependencies'] ?? false)) {
				// Skip types entirely when dependencies are disabled (pg_dump behavior)
				return;
			}

			// Get types used by selected tables
			$selectedTableList = '';
			$sep = '';
			foreach (array_keys($this->selectedObjects) as $name) {
				$selectedTableList .= $sep;
				$selectedTableList .= $this->connection->escapeLiteral($name);
				$sep = ',';
			}
			$types = $this->connection->selectSet(
				"SELECT DISTINCT t.oid, t.typname, t.typtype
					FROM pg_type t
					JOIN pg_namespace n ON n.oid = t.typnamespace
					JOIN pg_attribute a ON a.atttypid = t.oid
					JOIN pg_class c ON c.oid = a.attrelid
					WHERE n.nspname = '{$this->schemaEscaped}'
					AND c.relname IN ($selectedTableList)
					AND c.relkind IN ('r', 'v', 'm')
					AND t.typtype IN ('b','e')
					AND t.typelem = 0
					AND a.attnum > 0
					AND NOT a.attisdropped
					ORDER BY t.typname"
			);
		} else {
			// Dump all types when no specific objects selected
			$types = $this->connection->selectSet(
				"SELECT t.oid, t.typname, t.typtype
					FROM pg_type t
					JOIN pg_namespace n ON n.oid = t.typnamespace
					WHERE n.nspname = '{$this->schemaEscaped}'
					AND t.typtype IN ('b','e')  -- Base and enum types only
					AND t.typelem = 0  -- Exclude array types
					ORDER BY t.typname"
			);
		}

		if (!$types || $types->EOF) {
			return;
		}

		$typeDumper = $this->createSubDumper('type');

		while (!$types->EOF) {
			$typeDumper->dump('type', [
				'schema' => $schema,
				'type' => $types->fields['typname'],
			], $options);
			$types->moveNext();
		}
	}

	/**
	 * Dump all domains and composite types with unified dependency ordering.
	 * Handles cross-dependencies: domains can depend on composite types and vice versa.
	 *
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpDomainsAndCompositeTypes($schema, $options)
	{
		$this->write("\n-- Domains and Composite Types in schema $this->schemaQuoted\n");

		// Get all domains and standalone composite types
		// Exclude composite types that are implicitly created for tables/views
		$types = $this->connection->selectSet(
			"SELECT t.oid, t.typname, t.typtype
				FROM pg_type t
				JOIN pg_namespace n ON n.oid = t.typnamespace
				LEFT JOIN pg_class c ON c.oid = t.typrelid
				WHERE n.nspname = '{$this->schemaEscaped}'
				AND (
					t.typtype = 'd'  -- All domains
					OR (t.typtype = 'c' AND c.relkind = 'c')  -- Only standalone composite types
				)
				ORDER BY t.typtype, t.typname"
		);

		if (!$types || $types->EOF) {
			return;
		}

		// Build list of types and their dependencies
		$typeList = [];
		$typeDeps = [];

		while (!$types->EOF) {
			$oid = $types->fields['oid'];
			$name = $types->fields['typname'];
			$typtype = $types->fields['typtype'];
			$typeList[$oid] = ['name' => $name, 'type' => $typtype];
			$typeDeps[$oid] = [];
			$types->moveNext();
		}

		// Find domain dependencies (domain base type can be domain or composite)
		$domainDeps = $this->connection->selectSet(
			"SELECT t1.oid AS type_oid, t2.oid AS depends_on_oid
				FROM pg_type t1
				JOIN pg_namespace n ON n.oid = t1.typnamespace
				JOIN pg_type t2 ON t2.oid = t1.typbasetype
				WHERE n.nspname = '{$this->schemaEscaped}'
				AND t1.typtype = 'd'
				AND t2.typtype IN ('d', 'c')"
		);

		while ($domainDeps && !$domainDeps->EOF) {
			$typeOid = $domainDeps->fields['type_oid'];
			$depOid = $domainDeps->fields['depends_on_oid'];
			if (isset($typeDeps[$typeOid])) {
				$typeDeps[$typeOid][] = $depOid;
			}
			$domainDeps->moveNext();
		}

		// Find composite type dependencies (attributes can be domains or other composite types)
		$compositeDeps = $this->connection->selectSet(
			"SELECT DISTINCT t1.oid AS type_oid, t2.oid AS depends_on_oid
				FROM pg_type t1
				JOIN pg_namespace n ON n.oid = t1.typnamespace
				JOIN pg_class c ON c.oid = t1.typrelid
				JOIN pg_attribute a ON a.attrelid = c.oid
				JOIN pg_type t2 ON t2.oid = a.atttypid
				WHERE n.nspname = '{$this->schemaEscaped}'
				AND t1.typtype = 'c'
				AND t2.typtype IN ('d', 'c')
				AND a.attnum > 0
				AND NOT a.attisdropped"
		);

		while ($compositeDeps && !$compositeDeps->EOF) {
			$typeOid = $compositeDeps->fields['type_oid'];
			$depOid = $compositeDeps->fields['depends_on_oid'];
			if (isset($typeDeps[$typeOid])) {
				$typeDeps[$typeOid][] = $depOid;
			}
			$compositeDeps->moveNext();
		}

		// Topological sort
		$sorted = $this->sortTypesByDependency($typeList, $typeDeps);

		// Dump types in sorted order
		$domainDumper = $this->createSubDumper('domain');
		$typeDumper = $this->createSubDumper('type');

		foreach ($sorted as $typeInfo) {
			if ($typeInfo['type'] === 'd') {
				$domainDumper->dump('domain', [
					'schema' => $schema,
					'domain' => $typeInfo['name'],
				], $options);

				// Collect deferred constraints for later application.
				// They must be emitted after all functions are dumped so
				// that CHECK constraints referencing user functions work.
				$deferred = $domainDumper->getDeferredConstraints();
				if (!empty($deferred)) {
					$this->deferredDomainConstraints[] = [
						'schema' => $schema,
						'domain' => $typeInfo['name'],
						'constraints' => $deferred,
					];
				}
			} elseif ($typeInfo['type'] === 'c') {
				$typeDumper->dump('type', [
					'schema' => $schema,
					'type' => $typeInfo['name'],
				], $options);
			}
		}
	}

	/**
	 * Sort types (domains and composite types) by dependency.
	 *
	 * @param array $typeList Map of OID => ['name' => string, 'type' => 'd'|'c']
	 * @param array $typeDeps Map of OID => array of dependency OIDs
	 * @return array Sorted array of type info ['name' => string, 'type' => 'd'|'c']
	 */
	protected function sortTypesByDependency($typeList, $typeDeps)
	{
		// Build incoming edge count
		$incomingCount = [];
		foreach ($typeList as $oid => $info) {
			$incomingCount[$oid] = 0;
		}

		foreach ($typeDeps as $typeOid => $deps) {
			foreach ($deps as $depOid) {
				if (isset($incomingCount[$depOid])) {
					$incomingCount[$typeOid]++;
				}
			}
		}

		// Queue types with no dependencies
		$queue = [];
		foreach ($incomingCount as $oid => $count) {
			if ($count === 0) {
				$queue[] = $oid;
			}
		}

		// Process queue
		$sorted = [];
		$sortedOids = [];
		while (!empty($queue)) {
			$oid = array_shift($queue);
			$sorted[] = $typeList[$oid];
			$sortedOids[$oid] = true;

			// Find types that depend on current type
			foreach ($typeDeps as $depOid => $deps) {
				if (in_array($oid, $deps)) {
					$incomingCount[$depOid]--;
					if ($incomingCount[$depOid] === 0) {
						$queue[] = $depOid;
					}
				}
			}
		}

		// Add any remaining types (circular dependencies) in alphabetical order
		$remaining = [];
		foreach ($typeList as $oid => $info) {
			if (!isset($sortedOids[$oid])) {
				$remaining[] = $info;
			}
		}
		usort($remaining, function ($a, $b) {
			return strcmp($a['name'], $b['name']);
		});
		$sorted = array_merge($sorted, $remaining);

		return $sorted;
	}

	/**
	 * Dump functions, tables, and aggregates in topologically sorted order.
	 *
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpObjectsTopologically($schema, $options)
	{
		$includeSchemaObjects = $options['include_schema_objects'] ?? true;

		// Build dependency graph
		$analyzer = new DependencyAnalyzer($this->connection, [$schema]);
		$this->dependencyGraph = $analyzer->buildGraph();

		// Check for circular dependencies
		if ($this->dependencyGraph->hasCircularDependencies()) {
			$this->writeCircularDependencyWarning();
		}

		// Get sorted nodes
		$sortedNodes = $this->dependencyGraph->getSortedNodes();

		// Dump each object in topologically sorted order
		foreach ($sortedNodes as $node) {
			// Check if object should be included based on selection
			if ($this->hasObjectSelection) {
				// Option to include dependencies (types, domains, functions) when selecting specific tables
				// Default: false (match pg_dump minimal output)
				// Set to true to include all dependent objects
				$includeDependencies = $options['include_dependencies'] ?? false;

				// Tables/Partitioned tables: only if explicitly selected
				// Partitions/Sub-partitioned tables: include if parent partitioned table is selected
				// Domains/Functions: include if used by selected tables (dependencies)
				if ($node->type === 'table' || $node->type === 'partitioned_table') {
					if (!isset($this->selectedObjects[$node->name])) {
						continue;
					}
				} elseif ($node->type === 'partition' || $node->type === 'sub_partitioned_table') {
					// Partitions are included if their parent partitioned table is selected
					// The dependency graph ensures proper ordering (parent before partition)
					$parentOid = $node->metadata['parent_oid'] ?? null;
					if ($parentOid) {
						$parentNode = $this->dependencyGraph->getNode($parentOid);
						if (!$parentNode || !isset($this->selectedObjects[$parentNode->name])) {
							continue;
						}
					} else {
						// If we can't determine parent, skip unless explicitly selected
						if (!isset($this->selectedObjects[$node->name])) {
							continue;
						}
					}
				} elseif ($node->type === 'function') {
					// Skip dependencies if user wants pg_dump-style minimal output
					if (!$includeDependencies) {
						continue;
					}

					// Check if this function is a dependency of any selected table
					if (!$this->isDependencyOfSelectedObjects($node)) {
						continue;
					}
				}
			}

			// Pass dependency graph to sub-dumpers for smart deferral
			$dumpOptions = $options;
			$dumpOptions['dependency_graph'] = $this->dependencyGraph;

			switch ($node->type) {
				case 'function':
					if ($includeSchemaObjects) {
						$this->dumpSingleFunction($node, $schema, $dumpOptions);
					}
					break;

				case 'table':
					$this->dumpSingleTable($node, $schema, $dumpOptions);
					break;

				case 'partitioned_table':
					$this->dumpSinglePartitionedTable($node, $schema, $dumpOptions);
					break;

				case 'partition':
					$this->dumpSinglePartition($node, $schema, $dumpOptions);
					break;

				case 'sub_partitioned_table':
					// Multi-level partitioning: a partition that is itself partitioned
					$this->dumpSingleSubPartitionedTable($node, $schema, $dumpOptions);
					break;

				case 'aggregate':
					if ($includeSchemaObjects) {
						$this->dumpSingleAggregate($node, $schema, $dumpOptions);
					}
					break;
			}
		}
	}

	/**
	 * Dump a single function by node.
	 *
	 * @param \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode $node Function node
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpSingleFunction($node, $schema, $options)
	{
		$dumper = $this->createSubDumper('function');
		$dumper->dump('function', [
			'function_oid' => $node->oid,
			'schema' => $schema,
		], $options);

		$this->dumpedFunctions[$node->oid] = true;
	}

	/**
	 * Dump a single table by node.
	 *
	 * @param \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode $node Table node
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpSingleTable($node, $schema, $options)
	{
		$dumper = $this->createSubDumper('table');
		$dumper->dump('table', [
			'table' => $node->name,
			'schema' => $schema,
		], $options);
	}

	/**
	 * Dump a single partitioned table (parent table with PARTITION BY) by node.
	 * PostgreSQL 10+ feature.
	 *
	 * @param \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode $node Partitioned table node
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpSinglePartitionedTable($node, $schema, $options)
	{
		$dumper = $this->createSubDumper('partitioned_table');
		$dumper->dump('partitioned_table', [
			'table' => $node->name,
			'schema' => $schema,
		], $options);
	}

	/**
	 * Dump a single partition (child table of a partitioned table) by node.
	 * PostgreSQL 10+ feature.
	 *
	 * @param \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode $node Partition node
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpSinglePartition($node, $schema, $options)
	{
		$dumper = $this->createSubDumper('partition');
		$dumper->dump('partition', [
			'table' => $node->name,
			'schema' => $schema,
		], $options);
	}

	/**
	 * Dump a single sub-partitioned table (partition that is itself partitioned) by node.
	 * PostgreSQL 10+ multi-level partitioning feature.
	 *
	 * @param \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode $node Sub-partitioned table node
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpSingleSubPartitionedTable($node, $schema, $options)
	{
		$dumper = $this->createSubDumper('sub_partitioned_table');
		$dumper->dump('sub_partitioned_table', [
			'table' => $node->name,
			'schema' => $schema,
		], $options);
	}

	/**
	 * Dump a single domain by node.
	 *
	 * @param \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode $node Domain node
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpSingleDomain($node, $schema, $options)
	{
		$dumper = $this->createSubDumper('domain');
		$dumper->dump('domain', [
			'domain' => $node->name,
			'schema' => $schema,
		], $options);

		$this->dumpedDomains[$node->oid] = true;
	}

	/**
	 * Dump a single aggregate by node.
	 *
	 * @param \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode $node Aggregate node
	 * @param string $schema Schema name
	 * @param array $options Dump options
	 */
	protected function dumpSingleAggregate($node, $schema, $options)
	{
		// Query for aggregate's argument types
		$sql = "SELECT pg_catalog.pg_get_function_arguments(p.oid) AS proargtypes
				FROM pg_catalog.pg_proc p
				WHERE p.oid = '{$node->oid}'";

		$result = $this->connection->selectSet($sql);

		if ($result && !$result->EOF) {
			$basetype = $result->fields['proargtypes'];

			$dumper = $this->createSubDumper('aggregate');
			$dumper->dump('aggregate', [
				'aggregate' => $node->name,
				'basetype' => $basetype,
				'schema' => $schema,
			], $options);
		}
	}

	/**
	 * Check if a node (domain/function) is a dependency of any selected table.
	 *
	 * @param \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode $node Node to check
	 * @return bool True if node is needed by a selected table
	 */
	protected function isDependencyOfSelectedObjects($node)
	{
		if (!$this->dependencyGraph) {
			return false;
		}

		// Check if any selected table/partitioned table depends on this node
		// Edge direction: table depends on domain/function
		// So we check if any selected table has this node as a dependency
		foreach (array_keys($this->selectedObjects) as $selectedName) {
			// Check regular tables
			$selectedNode = $this->findNodeByName($selectedName, 'table');
			if ($selectedNode && $this->dependencyGraph->hasDependency($selectedNode->oid, $node->oid)) {
				return true;
			}
			// Check partitioned tables
			$selectedNode = $this->findNodeByName($selectedName, 'partitioned_table');
			if ($selectedNode && $this->dependencyGraph->hasDependency($selectedNode->oid, $node->oid)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find a node by name and type.
	 *
	 * @param string $name Object name
	 * @param string $type Object type
	 * @return \PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode|null
	 */
	protected function findNodeByName($name, $type)
	{
		if (!$this->dependencyGraph) {
			return null;
		}

		foreach ($this->dependencyGraph->getAllNodes() as $node) {
			if ($node->name === $name && $node->type === $type) {
				return $node;
			}
		}

		return null;
		// For dependency purposes we're tracking by OID, which is unique
		// When dumping, we need to pass the aggregate name and let AggregateDumper handle the details

		/*$dumper = $this->createSubDumper('aggregate');
		$dumper->dump('aggregate', [
			'aggregate' => $node->name,
			'basetype' => null, // Let AggregateDumper query by name
			'schema' => $schema,
		], $options);*/
	}

	/**
	 * Write warning about circular dependencies.
	 */
	protected function writeCircularDependencyWarning()
	{
		$this->write("\n");
		$this->write("--\n");
		$this->write("-- WARNING: Circular dependency detected\n");
		$this->write("--\n");
		$this->write("-- The following objects have circular dependencies that cannot be automatically resolved:\n");

		$circularNodes = $this->dependencyGraph->getCircularNodes();
		foreach ($circularNodes as $node) {
			$this->write("--   • " . ucfirst($node->type) . ": " . $node->getQualifiedName() . "\n");
		}

		$edges = $this->dependencyGraph->getCircularEdges();
		if (!empty($edges)) {
			$this->write("--\n");
			$this->write("-- Dependencies:\n");
			foreach ($edges as $edge) {
				$this->write("--   " . $edge['from'] . " (" . $edge['from_type'] . ") → " .
					$edge['to'] . " (" . $edge['to_type'] . ")\n");
			}
		}

		$this->write("--\n");
		$this->write("-- RESOLUTION OPTIONS:\n");
		$this->write("--\n");
		$this->write("-- Option 1: Temporarily disable function body validation\n");
		$this->write("--   1. Edit one function definition below\n");
		$this->write("--   2. Remove the problematic reference temporarily\n");
		$this->write("--   3. Import this dump\n");
		$this->write("--   4. Run: ALTER FUNCTION ... (with correct body)\n");
		$this->write("--\n");
		$this->write("-- Option 2: Use placeholder functions\n");
		$this->write("--   1. Create stub versions of functions first\n");
		$this->write("--   2. Import this dump (functions will replace stubs)\n");
		$this->write("--\n");
		$this->write("-- The following objects are dumped in alphabetical order:\n");
		$this->write("--\n\n");
	}


	protected function sortViewsTopologically(array $views, array $deps)
	{
		// Build graph
		$graph = [];
		$incoming = [];

		foreach ($views as $oid => $name) {
			$graph[$oid] = [];
			$incoming[$oid] = 0;
		}

		foreach ($deps as $d) {
			$from = $d['view_oid'];
			$to = $d['depends_on_oid'];

			if (isset($graph[$from]) && isset($graph[$to])) {
				$graph[$from][] = $to;
				$incoming[$to]++;
			}
		}

		// Nodes without incoming edges
		$queue = [];
		foreach ($incoming as $oid => $count) {
			if ($count === 0) {
				$queue[] = $oid;
			}
		}

		$sorted = [];

		while (!empty($queue)) {
			$oid = array_shift($queue);
			$sorted[] = $oid;

			foreach ($graph[$oid] as $dep) {
				$incoming[$dep]--;
				if ($incoming[$dep] === 0) {
					$queue[] = $dep;
				}
			}
		}

		// Check for circular dependencies
		if (count($sorted) < count($views)) {
			$this->write("\n-- Warning: Circular view dependencies detected, manual intervention may be required\n");
			$this->write("-- The following views could not be topologically sorted:\n");

			// Add remaining views in alphabetical order
			$remaining = [];
			foreach ($views as $oid => $name) {
				if (!in_array($oid, $sorted)) {
					$remaining[$oid] = $name;
					$this->write("--   - {$name}\n");
				}
			}

			// Sort remaining by name and add to sorted list
			asort($remaining);
			foreach ($remaining as $oid => $name) {
				$sorted[] = $oid;
			}

			$this->write("\n");
		}

		return $sorted;
	}

	protected function dumpRelkindObjects($schema, $options, $relkind, $typeName)
	{
		$sql = "SELECT c.relname
				FROM pg_catalog.pg_class c
				JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
				WHERE c.relkind = '{$relkind}'
				AND n.nspname = '{$this->schemaEscaped}'
				ORDER BY c.relname";

		$result = $this->connection->selectSet($sql);
		$dumper = $this->createSubDumper($typeName);

		while ($result && !$result->EOF) {
			$name = $result->fields['relname'];

			if (!$this->hasObjectSelection || isset($this->selectedObjects[$name])) {
				$dumper->dump($typeName, [
					$typeName => $name,
					'schema' => $schema,
				], $options);
			}

			$result->moveNext();
		}
	}

	protected function dumpSequences($schema, $options)
	{
		$this->write("\n-- Sequences in schema $this->schemaQuoted\n");
		//$this->dumpRelkindObjects($schema, $options, 'S', 'sequence');
		
		$sql = "SELECT c.relname
				FROM pg_catalog.pg_class c
				JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
				WHERE c.relkind = 'S'
				AND n.nspname = '{$this->schemaEscaped}'
				ORDER BY c.relname";

		$result = $this->connection->selectSet($sql);
		$dumper = $this->createSubDumper('sequence');

		while ($result && !$result->EOF) {
			$name = $result->fields['relname'];

			if (!$this->hasObjectSelection || isset($this->selectedObjects[$name])) {
				$dumper->dump('sequence', [
					'sequence' => $name,
					'schema' => $schema,
				], $options);
			}

			$result->moveNext();
		}
	}

	protected function dumpViews($schema, $options)
	{
		$this->write("\n-- Views in schema $this->schemaQuoted\n");

		// Get all views
		$sql = "SELECT c.oid, c.relname
				FROM pg_catalog.pg_class c
				JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
				WHERE c.relkind = 'v'
				AND n.nspname = '{$this->schemaEscaped}'
				ORDER BY c.relname";

		$views = $this->connection->selectSet($sql);

		if (!$views || $views->EOF) {
			return;
		}

		// Build view list
		$viewList = [];
		while (!$views->EOF) {
			$viewList[$views->fields['oid']] = $views->fields['relname'];
			$views->moveNext();
		}

		// Get dependencies between views
		$deps = $this->connection->selectSet(
			"SELECT DISTINCT d.objid AS view_oid, d.refobjid AS depends_on_oid
				FROM pg_depend d
				JOIN pg_class c1 ON c1.oid = d.objid
				JOIN pg_class c2 ON c2.oid = d.refobjid
				WHERE d.classid = 'pg_class'::regclass
				AND d.refclassid = 'pg_class'::regclass
				AND c1.relkind = 'v'
				AND c2.relkind = 'v'
				AND d.deptype IN ('n','i')"
		);

		$depList = [];
		while ($deps && !$deps->EOF) {
			$depList[] = $deps->fields;
			$deps->moveNext();
		}

		// Topologically sort views
		$sortedOids = $this->sortViewsTopologically($viewList, $depList);

		// Dump views in sorted order
		$dumper = $this->createSubDumper('view');

		foreach ($sortedOids as $oid) {
			$viewName = $viewList[$oid];

			if (!$this->hasObjectSelection || isset($this->selectedObjects[$viewName])) {
				$dumper->dump('view', [
					'view' => $viewName,
					'schema' => $schema,
				], $options);
			}
		}
	}

	protected function dumpMaterializedViews($schema, $options)
	{
		$this->write("\n-- Materialized Views in schema $this->schemaQuoted\n");

		$viewActions = new ViewActions($this->connection);
		$result = $viewActions->getViews(false, true);

		if (!$result || $result->EOF) {
			return;
		}

		$dumper = $this->createSubDumper('materialized_view');

		while (!$result->EOF) {
			$viewName = $result->fields['relname'];

			if (!$this->hasObjectSelection || isset($this->selectedObjects[$viewName])) {
				$dumper->dump('materialized_view', [
					'view' => $viewName,
					'schema' => $schema,
				], $options);
			}

			$result->moveNext();
		}
	}

	protected function applyDeferredForeignKeys($options)
	{
		if (empty($this->deferredForeignKeys)) {
			return;
		}

		$this->write("\n");
		$this->write("--\n");
		$this->write("-- Foreign Key Constraints (applied after all tables are created)\n");
		$this->write("--\n\n");

		foreach ($this->deferredForeignKeys as $fk) {
			$schemaQuoted = $fk['schemaQuoted'];
			$tableQuoted = $fk['tableQuoted'];
			$constraintName = $fk['name'];
			$definition = $fk['definition'];

			$this->write("ALTER TABLE {$schemaQuoted}.{$tableQuoted} ");
			$this->write("ADD CONSTRAINT {$constraintName} {$definition};\n");
		}
	}

	/**
	 * Add a foreign key constraint to be applied after all tables are created.
	 */
	public function addDeferredForeignKey($fkData)
	{
		$this->deferredForeignKeys[] = $fkData;
	}

	protected function applyDeferredMaterializedViewRefreshes($options)
	{
		if (empty($this->deferredMaterializedViewRefreshes)) {
			return;
		}

		$this->write("\n");
		$this->write("--\n");
		$this->write("-- Refresh Materialized Views\n");
		$this->write("--\n\n");

		foreach ($this->deferredMaterializedViewRefreshes as $mv) {
			$schemaQuoted = $this->connection->quoteIdentifier($mv['schema']);
			$viewQuoted = $this->connection->quoteIdentifier($mv['view']);

			$this->write("-- Refreshing materialized view {$mv['schema']}.{$mv['view']}\n");
			$this->write("REFRESH MATERIALIZED VIEW $schemaQuoted.$viewQuoted;\n\n");
		}
	}

	protected function applyDeferredViews($schema, $options)
	{
		// Views are created during dumpViews() with topological sorting
		// This is a placeholder for future view-specific deferred operations
	}

	protected function applyDeferredRules($options)
	{
		if (empty($this->deferredRules)) {
			return;
		}

		$this->write("\n");
		$this->write("--\n");
		$this->write("-- Deferred Rules\n");
		$this->write("--\n\n");

		foreach ($this->deferredRules as $rule) {
			$this->write("-- Rule on {$rule['schema']}.{$rule['relation']}\n");
			$this->write($rule['definition']);
			$this->write(";\n\n");
		}
	}

	protected function applyDeferredTriggers($options)
	{
		if (empty($this->deferredTriggers)) {
			return;
		}

		$this->write("\n");
		$this->write("--\n");
		$this->write("-- Deferred Triggers\n");
		$this->write("--\n\n");

		foreach ($this->deferredTriggers as $trigger) {
			$this->write("-- Trigger on {$trigger['schema']}.{$trigger['relation']}\n");
			$this->write($trigger['definition']);
			$this->write(";\n\n");
		}
	}

	protected function applyDeferredSequenceOwnerships($options)
	{
		if (empty($this->deferredSequenceOwnerships)) {
			return;
		}

		$this->write("\n");
		$this->write("--\n");
		$this->write("-- Sequence Ownerships\n");
		$this->write("--\n\n");

		foreach ($this->deferredSequenceOwnerships as $ownership) {
			$tableKey = "{$ownership['schema']}.{$ownership['table']}";

			if (!$this->isTableDumped($ownership['schema'], $ownership['table'])) {
				$this->write("-- Skipping ownership: table $tableKey not found\n");
				continue;
			}

			$schemaQuoted = $this->connection->quoteIdentifier($ownership['schema']);
			$sequenceQuoted = $this->connection->quoteIdentifier($ownership['sequence']);
			$tableQuoted = $this->connection->quoteIdentifier($ownership['table']);
			$columnQuoted = $this->connection->quoteIdentifier($ownership['column']);

			$this->write("ALTER SEQUENCE $schemaQuoted.$sequenceQuoted OWNED BY $schemaQuoted.$tableQuoted.$columnQuoted;\n");
		}
	}

	/**
	 * Apply deferred domain constraints collected during domain dumping.
	 *
	 * These are emitted after all functions are created, because a domain
	 * CHECK constraint may reference a user-defined function.
	 */
	protected function applyDeferredDomainConstraints()
	{
		if (empty($this->deferredDomainConstraints)) {
			return;
		}

		$this->write("\n");
		$this->write("--\n");
		$this->write("-- Deferred Domain Constraints (applied after all functions are created)\n");
		$this->write("--\n\n");

		foreach ($this->deferredDomainConstraints as $entry) {
			$schemaQuoted = $this->connection->quoteIdentifier($entry['schema']);
			$domainQuoted = $this->connection->quoteIdentifier($entry['domain']);

			foreach ($entry['constraints'] as $constraint) {
				$connameQuoted = $this->connection->escapeIdentifier($constraint['name']);
				$this->write("ALTER DOMAIN $schemaQuoted.$domainQuoted ");
				$this->write("ADD CONSTRAINT $connameQuoted {$constraint['definition']} NOT VALID;\n");
				$this->write("ALTER DOMAIN $schemaQuoted.$domainQuoted ");
				$this->write("VALIDATE CONSTRAINT $connameQuoted;\n");
			}
		}
	}

	/**
	 * Dump all aggregates in a schema.
	 * Called after functions are dumped, since aggregates depend on SFUNC/FINALFUNC.
	 */
	protected function dumpAggregates($schema, $options)
	{
		// Skip aggregates when specific objects are selected
		// Aggregates are schema objects, not table/view data objects
		if ($this->hasObjectSelection) {
			return;
		}

		$this->write("\n-- Aggregates in schema $this->schemaQuoted\n");

		$sql = "SELECT p.proname,
					pg_catalog.pg_get_function_arguments(p.oid) AS proargtypes
				FROM pg_catalog.pg_proc p
				LEFT JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
				WHERE n.nspname = '{$this->schemaEscaped}'
				AND p.prokind = 'a'
				ORDER BY p.proname";

		$aggregates = $this->connection->selectSet($sql);
		$aggDumper = $this->createSubDumper('aggregate');

		while ($aggregates && !$aggregates->EOF) {
			$aggDumper->dump('aggregate', [
				'aggregate' => $aggregates->fields['proname'],
				'basetype' => $aggregates->fields['proargtypes'],
				'schema' => $schema
			], $options);
			$aggregates->moveNext();
		}

	}

	protected function dumpOperators($schema, $options)
	{
		$this->write("\n-- Operators in schema $this->schemaQuoted\n");

		$sql = "SELECT o.oid
				FROM pg_catalog.pg_operator o
				LEFT JOIN pg_catalog.pg_namespace n ON n.oid = o.oprnamespace
				WHERE n.nspname = '{$this->schemaEscaped}'
				ORDER BY o.oid";

		$operators = $this->connection->selectSet($sql);
		$opDumper = $this->createSubDumper('operator');

		while ($operators && !$operators->EOF) {
			$opDumper->dump('operator', [
				'operator_oid' => $operators->fields['oid'],
				'schema' => $schema
			], $options);
			$operators->moveNext();
		}
	}

	/**
	 * Add a trigger to be applied after functions are created
	 */
	public function addDeferredTrigger($schema, $relation, $triggerDefinition)
	{
		$this->deferredTriggers[] = [
			'schema' => $schema,
			'relation' => $relation,
			'definition' => $triggerDefinition,
		];
	}

	/**
	 * Add a rule to be applied after functions are created
	 */
	public function addDeferredRule($schema, $relation, $ruleDefinition)
	{
		$this->deferredRules[] = [
			'schema' => $schema,
			'relation' => $relation,
			'definition' => $ruleDefinition,
		];
	}

	/**
	 * Add a sequence ownership statement to be applied at the end
	 */
	public function addDeferredSequenceOwnership($schema, $sequence, $table, $column)
	{
		$this->deferredSequenceOwnerships[] = [
			'schema' => $schema,
			'sequence' => $sequence,
			'table' => $table,
			'column' => $column,
		];
	}

	/**
	 * Add a materialized view refresh statement
	 */
	public function addDeferredMaterializedViewRefresh($schema, $viewName)
	{
		$this->deferredMaterializedViewRefreshes[] = [
			'schema' => $schema,
			'view' => $viewName,
		];
	}

	/**
	 * Track that a table was dumped (for ownership validation)
	 */
	public function registerDumpedTable($schema, $table)
	{
		$this->dumpedTables["$schema.$table"] = true;
	}

	/**
	 * Check if a table was dumped
	 */
	public function isTableDumped($schema, $table)
	{
		return isset($this->dumpedTables["$schema.$table"]);
	}
}