<?php

namespace PhpPgAdmin\Tests\Integration;

/**
 * Integration tests for DependencyAnalyzer against a live PostgreSQL.
 *
 * Each test loads a fixture into a fresh schema and verifies the
 * topological order produced by DependencyGraph.
 */
final class DependencyAnalyzerTest extends IntegrationTestCase
{
    public function testFunctionDependsOnFunction(): void
    {
        $this->loadFixture('function_depends_function.sql');
        $names = $this->sortedNames();
        $this->assertBefore('base_func', 'derived_func', $names);
    }

    /**
     * Regression test: the graph must order by dependency, not by name.
     * a_func depends on z_func, but alphabetically a_func < z_func.
     */
    public function testDependencyOrderOverridesAlphabeticalOrder(): void
    {
        $this->loadFixture('function_dependency_alpha_order.sql');
        $names = $this->sortedNames();
        $this->assertBefore('z_func', 'a_func', $names);
    }

    public function testTableDependsOnFunction(): void
    {
        $this->loadFixture('table_depends_function.sql');
        $names = $this->sortedNames();
        $this->assertBefore('gen_timestamp', 'orders', $names);
    }

    public function testGeneratedColumnDependsOnFunction(): void
    {
        $this->loadFixture('generated_columns.sql');
        $names = $this->sortedNames();
        $this->assertBefore('calculate_tax', 'invoices', $names);
    }

    public function testTableCheckConstraintDependsOnFunction(): void
    {
        $this->loadFixture('table_check_constraint.sql');
        $names = $this->sortedNames();
        $this->assertBefore('check_positive', 'products', $names);
    }

    public function testFunctionDependsOnTable(): void
    {
        $this->loadFixture('function_depends_table.sql');
        $names = $this->sortedNames();
        $this->assertBefore('customers', 'rewards_report', $names);
    }

    public function testDomainDependenciesNotInGraph(): void
    {
        $this->loadFixture('domain_depends_function.sql');
        $names = $this->sortedNames();
        // The function is in the graph, but domains are dumped separately
        // and are not nodes in the dependency graph.
        self::assertContains('validate_email', $names);
        self::assertNotContains('email_address', $names);
    }

    public function testPartitionDependsOnPartitionedTable(): void
    {
        $this->loadFixture('partitioned_table_with_partition.sql');
        $names = $this->sortedNames();
        // aaa_partition 字母序在前，但依赖序要求 measurements 先
        $this->assertBefore('measurements', 'aaa_partition', $names);
    }

    public function testTableDependsOnReferencedTable(): void
    {
        $this->loadFixture('table_depends_on_table_fk.sql');
        $names = $this->sortedNames();
        $this->assertBefore('parent_table', 'child_table', $names);
    }

    public function testAggregateDependsOnFunction(): void
    {
        $this->loadFixture('aggregate_depends_function.sql');
        $names = $this->sortedNames();
        $this->assertBefore('add_int', 'sum_int', $names);
    }

    public function testEscapeSchemaListDoesNotLeakAcrossInstances(): void
    {
        $a1 = new \PhpPgAdmin\Database\Dump\DependencyGraph\DependencyAnalyzer(self::$conn, ['schema_a']);
        $a2 = new \PhpPgAdmin\Database\Dump\DependencyGraph\DependencyAnalyzer(self::$conn, ['schema_b']);

        $ref = new \ReflectionMethod($a1, 'escapeSchemaList');
        $ref->setAccessible(true);
        $r1 = $ref->invoke($a1);
        $r2 = $ref->invoke($a2);

        self::assertNotEquals(
            $r1,
            $r2,
            'escapeSchemaList must not cache across instances: got "' . $r1 . '" for schema_b'
        );
    }

    public function testTableUsingDomainDoesNotBreakGraph(): void
    {
        $this->loadFixture('table_depends_on_domain.sql');
        $names = $this->sortedNames();
        self::assertContains('users', $names);
    }

    public function testCircularDependencyDetected(): void
    {
        $this->loadFixture('circular_dependencies.sql');
        $graph = $this->buildGraph();
        self::assertTrue(
            $graph->hasCircularDependencies(),
            'Expected func_a <-> func_b cycle to be detected'
        );
        $circular = $graph->getCircularNodes();
        $circularNames = array_map(fn($n) => $n->name, $circular);
        self::assertContains('func_a', $circularNames);
        self::assertContains('func_b', $circularNames);
    }

    public function testTableDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('table_depends_function.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        // 断言 SQL 里有 CREATE TABLE
        self::assertStringContainsString('CREATE TABLE', $sql);
    }

    /**
     * End-to-end: aggregate dump.
     */
    public function testAggregateDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('aggregate_depends_function.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('CREATE AGGREGATE', $sql);
        self::assertStringContainsString('sum_int', $sql);
    }

    /**
     * End-to-end: partitioned table dump.
     */
    public function testPartitionedTableDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('partitioned_table_with_partition.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('measurements', $sql);
        self::assertStringContainsString('PARTITION BY', $sql);
        self::assertStringContainsString('aaa_partition', $sql);
    }

    /**
     * End-to-end: FK table dump.
     */
    public function testForeignKeyTableDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('table_depends_on_table_fk.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('parent_table', $sql);
        self::assertStringContainsString('child_table', $sql);
        // FK constraints are deferred — check the FK section
        self::assertStringContainsString('FOREIGN KEY', $sql);
    }

    /**
     * End-to-end: generated column dump.
     */
    public function testGeneratedColumnDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('generated_columns.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('invoices', $sql);
        self::assertStringContainsString('GENERATED ALWAYS AS', $sql);
        self::assertStringContainsString('calculate_tax', $sql);
    }

    /**
     * End-to-end: nested view dependency (active_users → premium_users).
     */
    public function testViewDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('view_depends_on_view.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('active_users', $sql, 'active_users not in dump');
        self::assertStringContainsString('premium_users', $sql, 'premium_users not in dump');

        // Dependency order: active_users must come before premium_users
        $posActive = strpos($sql, 'active_users');
        $posPremium = strpos($sql, 'premium_users');

        self::assertNotFalse($posActive, 'active_users not found');
        self::assertNotFalse($posPremium, 'premium_users not found');
        self::assertTrue(
            $posActive < $posPremium,
            "active_users (pos $posActive) must be dumped before premium_users (pos $posPremium)"
        );
    }

    /**
     * End-to-end: enum type and composite type depending on it.
     */
    public function testTypeDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('type_enum_composite.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('CREATE TYPE', $sql, 'CREATE TYPE not in dump');
        self::assertStringContainsString('mood', $sql, 'mood type not in dump');
        self::assertStringContainsString('person', $sql, 'person type not in dump');
    }

    /**
     * End-to-end: materialized view with deferred refresh.
     */
    public function testMaterializedViewDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('materialized_view.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('MATERIALIZED VIEW', $sql, 'MATERIALIZED VIEW not in dump');
        self::assertStringContainsString('sales_summary', $sql, 'sales_summary not in dump');

        // Structure phase: WITH NO DATA
        self::assertStringContainsString(
            'WITH NO DATA',
            $sql,
            'WITH NO DATA not in dump (structure phase)'
        );

        // Deferred phase: REFRESH MATERIALIZED VIEW
        self::assertStringContainsString(
            'REFRESH MATERIALIZED VIEW',
            $sql,
            'REFRESH MATERIALIZED VIEW not in dump (deferred phase)'
        );
    }
    
    /**
     * End-to-end: multi-level partitioning.
     * measurements (parent) → measurements_2026 (sub-partitioned) → measurements_2026_q1 (leaf)
     */
    public function testSubPartitionedTableDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('sub_partitioned_table.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('measurements', $sql, 'measurements not in dump');
        self::assertStringContainsString('measurements_2026', $sql, 'measurements_2026 not in dump');
        self::assertStringContainsString('measurements_2026_q1', $sql, 'measurements_2026_q1 not in dump');
        self::assertStringContainsString('PARTITION BY', $sql, 'PARTITION BY not in dump');

        // Dependency order: parent before sub-partitioned before leaf
        $posParent = strpos($sql, 'measurements"');
        $posSub = strpos($sql, 'measurements_2026"');
        $posLeaf = strpos($sql, 'measurements_2026_q1"');

        self::assertNotFalse($posParent, 'measurements not found');
        self::assertNotFalse($posSub, 'measurements_2026 not found');
        self::assertNotFalse($posLeaf, 'measurements_2026_q1 not found');
        self::assertTrue(
            $posParent < $posSub,
            "measurements (pos $posParent) must be dumped before measurements_2026 (pos $posSub)"
        );
        self::assertTrue(
            $posSub < $posLeaf,
            "measurements_2026 (pos $posSub) must be dumped before measurements_2026_q1 (pos $posLeaf)"
        );
    }

    /**
     * End-to-end: custom operator with support function.
     * complex type → complex_add function → + operator
     */
    public function testOperatorDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('custom_operator.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('CREATE TYPE', $sql, 'CREATE TYPE not in dump');
        self::assertStringContainsString('complex', $sql, 'complex type not in dump');
        self::assertStringContainsString('CREATE OR REPLACE FUNCTION', $sql, 'CREATE FUNCTION not in dump');
        self::assertStringContainsString('complex_add', $sql, 'complex_add not in dump');
        self::assertStringContainsString('CREATE OPERATOR', $sql, 'CREATE OPERATOR not in dump');

        // Regression: operator references function, so function must come first
        $funcPos = strpos($sql, 'CREATE OR REPLACE FUNCTION');
        $opPos = strpos($sql, 'CREATE OPERATOR');
        self::assertNotFalse($funcPos, 'Function not found');
        self::assertNotFalse($opPos, 'Operator not found');
        self::assertTrue(
            $funcPos < $opPos,
            "Function (pos $funcPos) must be dumped before operator (pos $opPos)"
        );

        // Regression: function name must not have duplicated schema prefix
        // (e.g. "deps_test"."deps_test.complex_add")
        self::assertStringNotContainsString(
            '"' . self::$schema . '"."' . self::$schema . '.complex_add"',
            $sql,
            'Function name has duplicated schema prefix'
        );
        self::assertStringContainsString(
            '"' . self::$schema . '"."complex_add"',
            $sql,
            'Function name should be "<schema>"."complex_add"'
        );
    }

    /**
     * End-to-end: full database dump.
     * Uses DatabaseDumper instead of SchemaDumper.
     */
    public function testDatabaseDumpIsEmittedCorrectly(): void
    {
        $this->loadFixture('table_depends_function.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\DatabaseDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('database', [], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        self::assertNotEmpty($sql, 'Database dump is empty');
        self::assertStringContainsString('CREATE TABLE', $sql, 'CREATE TABLE not in dump');
        self::assertStringContainsString('orders', $sql, 'orders table not in dump');
        self::assertStringContainsString('gen_timestamp', $sql, 'gen_timestamp function not in dump');
    }

    /**
     * End-to-end: verify that the full SchemaDumper output places the
     * function definition before the deferred CHECK constraint that
     * references it.
     */
    public function testDomainConstraintIsEmittedAfterFunction(): void
    {
        $this->loadFixture('domain_depends_function.sql');

        $stream = fopen('php://temp', 'w+');
        $dumper = new \PhpPgAdmin\Database\Dump\SchemaDumper(self::$conn);
        $dumper->setOutputStream($stream);
        $dumper->dump('schema', ['schema' => self::$schema], []);
        rewind($stream);
        $sql = stream_get_contents($stream);
        fclose($stream);

        $funcPos = strpos($sql, 'CREATE OR REPLACE FUNCTION');
        $alterPos = strpos($sql, 'ADD CONSTRAINT');
        $checkPos = strpos($sql, 'validate_email(VALUE)');

        self::assertNotFalse($funcPos, 'Function CREATE missing from dump');
        self::assertNotFalse($alterPos, 'ALTER DOMAIN ADD CONSTRAINT missing from dump');
        self::assertNotFalse($checkPos, 'CHECK constraint missing from dump');

        self::assertTrue(
            $funcPos < $alterPos,
            "Function (pos $funcPos) must be dumped before ALTER DOMAIN ADD CONSTRAINT (pos $alterPos)"
        );
        self::assertTrue(
            $funcPos < $checkPos,
            "Function (pos $funcPos) must be dumped before the CHECK expression (pos $checkPos)"
        );
    }
}