<?php

namespace PhpPgAdmin\Tests\Unit\DependencyGraph;

use PHPUnit\Framework\TestCase;
use PhpPgAdmin\Database\Dump\DependencyGraph\DependencyGraph;
use PhpPgAdmin\Database\Dump\DependencyGraph\ObjectNode;

/**
 * Unit tests for DependencyGraph topological sorting.
 */
class DependencyGraphTest extends TestCase
{
    /**
     * Test simple linear dependency chain: A → B → C
     */
    public function testSimpleLinearChain()
    {
        $graph = new DependencyGraph();

        $nodeA = new ObjectNode('1', 'function', 'func_a', 'public');
        $nodeB = new ObjectNode('2', 'function', 'func_b', 'public');
        $nodeC = new ObjectNode('3', 'function', 'func_c', 'public');

        $graph->addNode($nodeA);
        $graph->addNode($nodeB);
        $graph->addNode($nodeC);

        // A depends on B, B depends on C
        $graph->addEdge('1', '2');
        $graph->addEdge('2', '3');

        $sorted = $graph->topologicalSort();

        // C should come first (no dependencies), then B, then A
        $this->assertCount(3, $sorted);
        $this->assertEquals('3', $sorted[0]->oid);
        $this->assertEquals('2', $sorted[1]->oid);
        $this->assertEquals('1', $sorted[2]->oid);
    }

    /**
     * Test function depending on table (composite type).
     */
    public function testFunctionDependsOnTable()
    {
        $graph = new DependencyGraph();

        $table = new ObjectNode('100', 'table', 'users', 'public');
        $function = new ObjectNode('200', 'function', 'get_user', 'public');

        $graph->addNode($table);
        $graph->addNode($function);

        // Function depends on table
        $graph->addEdge('200', '100');

        $sorted = $graph->topologicalSort();

        // Table should come before function
        $this->assertEquals('100', $sorted[0]->oid);
        $this->assertEquals('200', $sorted[1]->oid);
    }

    /**
     * Test table depending on function (default value).
     */
    public function testTableDependsOnFunction()
    {
        $graph = new DependencyGraph();

        $function = new ObjectNode('300', 'function', 'gen_id', 'public');
        $table = new ObjectNode('400', 'table', 'items', 'public');

        $graph->addNode($function);
        $graph->addNode($table);

        // Table depends on function
        $graph->addEdge('400', '300');

        $sorted = $graph->topologicalSort();

        // Function should come before table
        $this->assertEquals('300', $sorted[0]->oid);
        $this->assertEquals('400', $sorted[1]->oid);
    }

    /**
     * Test table depending on another table via foreign key.
     *
     * This mirrors the failing export case where a child table was dumped before
     * the parent table because table nodes were only ordered alphabetically.
     */
    public function testTableDependsOnReferencedTable()
    {
        $graph = new DependencyGraph();

        $child = new ObjectNode('200', 'table', 'license_devices', 'license');
        $parent = new ObjectNode('100', 'table', 'licenses', 'license');

        $graph->addNode($child);
        $graph->addNode($parent);

        // Child table depends on parent table via foreign key.
        $graph->addEdge('200', '100');

        $sorted = $graph->topologicalSort();

        $this->assertCount(2, $sorted);
        $this->assertEquals('100', $sorted[0]->oid);
        $this->assertEquals('200', $sorted[1]->oid);
    }

    /**
     * Test circular dependency detection.
     */
    public function testCircularDependency()
    {
        $graph = new DependencyGraph();

        $nodeA = new ObjectNode('10', 'function', 'func_a', 'public');
        $nodeB = new ObjectNode('20', 'function', 'func_b', 'public');

        $graph->addNode($nodeA);
        $graph->addNode($nodeB);

        // Circular: A → B → A
        $graph->addEdge('10', '20');
        $graph->addEdge('20', '10');

        $sorted = $graph->topologicalSort();

        // Should detect circular dependency
        $this->assertTrue($graph->hasCircularDependencies());

        $circularNodes = $graph->getCircularNodes();
        $this->assertCount(2, $circularNodes);
    }

    /**
     * Test complex mixed dependencies.
     */
    public function testComplexMixedDependencies()
    {
        $graph = new DependencyGraph();

        $funcBase = new ObjectNode('1', 'function', 'base_func', 'public');
        $table1 = new ObjectNode('2', 'table', 'table1', 'public');
        $funcDerived = new ObjectNode('3', 'function', 'derived_func', 'public');
        $table2 = new ObjectNode('4', 'table', 'table2', 'public');
        $domain = new ObjectNode('5', 'domain', 'email', 'public');

        $graph->addNode($funcBase);
        $graph->addNode($table1);
        $graph->addNode($funcDerived);
        $graph->addNode($table2);
        $graph->addNode($domain);

        // base_func (1) has no dependencies
        // table1 (2) depends on base_func (1)
        // derived_func (3) depends on table1 (2) - uses composite type
        // table2 (4) depends on derived_func (3) - default value
        // domain (5) depends on base_func (1) - check constraint

        $graph->addEdge('2', '1'); // table1 → base_func
        $graph->addEdge('3', '2'); // derived_func → table1
        $graph->addEdge('4', '3'); // table2 → derived_func
        $graph->addEdge('5', '1'); // domain → base_func

        $sorted = $graph->topologicalSort();

        // Verify ordering
        $positions = [];
        foreach ($sorted as $node) {
            $positions[$node->oid] = $node->position;
        }

        // base_func should come first
        $this->assertEquals(0, $positions['1']);

        // table1 and domain should come after base_func
        $this->assertGreaterThan($positions['1'], $positions['2']);
        $this->assertGreaterThan($positions['1'], $positions['5']);

        // derived_func should come after table1
        $this->assertGreaterThan($positions['2'], $positions['3']);

        // table2 should come after derived_func
        $this->assertGreaterThan($positions['3'], $positions['4']);
    }

    /**
     * Test shouldDefer method.
     */
    public function testShouldDefer()
    {
        $graph = new DependencyGraph();

        $funcA = new ObjectNode('1', 'function', 'func_a', 'public');
        $tableB = new ObjectNode('2', 'table', 'table_b', 'public');
        $funcC = new ObjectNode('3', 'function', 'func_c', 'public');

        $graph->addNode($funcA);
        $graph->addNode($tableB);
        $graph->addNode($funcC);

        // func_a has no deps, table_b depends on func_c
        $graph->addEdge('2', '3');

        $sorted = $graph->topologicalSort();

        // func_c comes before table_b, so table_b should NOT defer reference to func_c
        $this->assertFalse($graph->shouldDefer('2', '3'));

        // If table_b referenced func_a (which comes earlier), should NOT defer
        $this->assertFalse($graph->shouldDefer('2', '1'));

        // If func_a referenced table_b (which comes later), SHOULD defer
        $this->assertTrue($graph->shouldDefer('1', '2'));
    }

    /**
     * Test getPosition method.
     */
    public function testGetPosition()
    {
        $graph = new DependencyGraph();

        $nodeA = new ObjectNode('100', 'function', 'func_a', 'public');
        $nodeB = new ObjectNode('200', 'table', 'table_b', 'public');

        $graph->addNode($nodeA);
        $graph->addNode($nodeB);

        $sorted = $graph->topologicalSort();

        $posA = $graph->getPosition('100');
        $posB = $graph->getPosition('200');

        $this->assertGreaterThanOrEqual(0, $posA);
        $this->assertGreaterThanOrEqual(0, $posB);
        $this->assertNotEquals($posA, $posB);

        // Non-existent OID should return -1
        $this->assertEquals(-1, $graph->getPosition('999'));
    }
}
