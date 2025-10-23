<?php

namespace PhpPgAdmin\Database\Dump\DependencyGraph;

/**
 * Dependency graph for database objects with topological sorting.
 * 
 * Uses Kahn's algorithm for topological sorting with cycle detection.
 */
class DependencyGraph
{
    /**
     * @var array Map of OID => ObjectNode
     */
    private $nodes = [];

    /**
     * @var array Map of OID => array of dependent OIDs (adjacency list)
     */
    private $edges = [];

    /**
     * @var array Topologically sorted array of ObjectNode
     */
    private $sortedNodes = [];

    /**
     * @var array Nodes that could not be sorted (circular dependencies)
     */
    private $unsortedNodes = [];

    /**
     * @var bool Whether sorting has been performed
     */
    private $isSorted = false;

    /**
     * Add a node to the graph.
     *
     * @param ObjectNode $node Node to add
     */
    public function addNode(ObjectNode $node)
    {
        $this->nodes[$node->oid] = $node;
        if (!isset($this->edges[$node->oid])) {
            $this->edges[$node->oid] = [];
        }
        $this->isSorted = false;
    }

    /**
     * Add a dependency edge from one node to another.
     *
     * Semantics: addEdge(A, B) means "A depends on B", so B must come before A in the sorted output.
     * Example: addEdge(function_oid, table_oid) means the function depends on the table,
     * so the table must be dumped before the function.
     *
     * @param string $fromOid OID of dependent object (depends on toOid)
     * @param string $toOid OID of dependency (must come before fromOid)
     */
    public function addEdge($fromOid, $toOid)
    {
        if (!isset($this->edges[$fromOid])) {
            $this->edges[$fromOid] = [];
        }

        if (!in_array($toOid, $this->edges[$fromOid])) {
            $this->edges[$fromOid][] = $toOid;
        }

        if (isset($this->nodes[$fromOid])) {
            $this->nodes[$fromOid]->addDependency($toOid);
        }

        $this->isSorted = false;
    }

    /**
     * Get a node by OID.
     *
     * @param string $oid Object OID
     * @return ObjectNode|null Node or null if not found
     */
    public function getNode($oid)
    {
        return $this->nodes[$oid] ?? null;
    }

    /**
     * Get all nodes in the graph.
     *
     * @return array Map of OID => ObjectNode
     */
    public function getNodes()
    {
        return $this->nodes;
    }

    /**
     * Perform topological sort using Kahn's algorithm.
     * 
     * Detects circular dependencies and places unsorted nodes separately.
     * 
     * Semantics: If addEdge(A, B) was called, B comes before A in the output
     * (A depends on B, so B must be processed/dumped first).
     *
     * @return array Array of sorted ObjectNode instances
     */
    public function topologicalSort()
    {
        if ($this->isSorted) {
            return $this->sortedNodes;
        }

        // Build incoming edge count for each node
        // edges[A] = [B] means A depends on B, so A has B as a dependency (not an incoming edge from B)
        // We need to count: for each node, how many OTHER nodes depend on it
        $incomingCount = [];
        foreach ($this->nodes as $oid => $node) {
            $incomingCount[$oid] = 0;
        }

        // Iterate through all edges and count incoming edges correctly
        // If edges[A] contains B, it means A depends on B (B→A dependency arrow)
        // So B has an outgoing edge to A, which means A has an incoming edge from B
        foreach ($this->edges as $fromOid => $targets) {
            foreach ($targets as $toOid) {
                // fromOid depends on toOid, so fromOid should have an incoming edge
                if (isset($incomingCount[$fromOid])) {
                    $incomingCount[$fromOid]++;
                }
            }
        }

        // Queue nodes with no incoming edges (no dependencies)
        $queue = [];
        foreach ($incomingCount as $oid => $count) {
            if ($count === 0) {
                $queue[] = $oid;
            }
        }

        // Process queue
        $sorted = [];
        $position = 0;

        while (!empty($queue)) {
            $oid = array_shift($queue);
            $node = $this->nodes[$oid];
            $node->position = $position++;
            $sorted[] = $node;

            // For nodes that depend on the current node, reduce their incoming count
            // We need to find all nodes that have current node in their dependency list
            foreach ($this->edges as $dependentOid => $dependencies) {
                if (in_array($oid, $dependencies)) {
                    // dependentOid depends on current oid
                    // Now that oid is processed, reduce dependentOid's incoming count
                    if (isset($incomingCount[$dependentOid])) {
                        $incomingCount[$dependentOid]--;
                        if ($incomingCount[$dependentOid] === 0) {
                            $queue[] = $dependentOid;
                        }
                    }
                }
            }
        }

        // Check for circular dependencies
        $unsorted = [];
        if (count($sorted) < count($this->nodes)) {
            // Collect unsorted nodes
            foreach ($this->nodes as $oid => $node) {
                if ($node->position === -1) {
                    $unsorted[] = $node;
                }
            }

            // Sort unsorted nodes by schema and name for consistent output
            usort($unsorted, function ($a, $b) {
                $schemaCmp = strcmp($a->schema, $b->schema);
                if ($schemaCmp !== 0) {
                    return $schemaCmp;
                }
                $typeCmp = strcmp($a->type, $b->type);
                if ($typeCmp !== 0) {
                    return $typeCmp;
                }
                return strcmp($a->name, $b->name);
            });

            // Add unsorted nodes to end with positions
            foreach ($unsorted as $node) {
                $node->position = $position++;
                $sorted[] = $node;
            }
        }

        $this->sortedNodes = $sorted;
        $this->unsortedNodes = $unsorted;
        $this->isSorted = true;

        return $this->sortedNodes;
    }

    /**
     * Get nodes that have circular dependencies.
     *
     * @return array Array of ObjectNode instances in circular dependencies
     */
    public function getCircularNodes()
    {
        if (!$this->isSorted) {
            $this->topologicalSort();
        }
        return $this->unsortedNodes;
    }

    /**
     * Check if there are circular dependencies.
     *
     * @return bool True if circular dependencies exist
     */
    public function hasCircularDependencies()
    {
        if (!$this->isSorted) {
            $this->topologicalSort();
        }
        return !empty($this->unsortedNodes);
    }

    /**
     * Get the position of an object in the sorted order.
     *
     * @param string $oid Object OID
     * @param string|null $type Optional type filter (unused, for compatibility)
     * @return int Position (0-based) or -1 if not found
     */
    public function getPosition($oid, $type = null)
    {
        if (!$this->isSorted) {
            $this->topologicalSort();
        }

        if (isset($this->nodes[$oid])) {
            return $this->nodes[$oid]->position;
        }

        return -1;
    }

    /**
     * Determine if a constraint/expression should be deferred.
     * 
     * Returns true if the target (dependency) comes after the source in dump order.
     *
     * @param string $sourceOid OID of object containing the constraint
     * @param string $targetOid OID of object referenced by the constraint
     * @return bool True if constraint should be deferred
     */
    public function shouldDefer($sourceOid, $targetOid)
    {
        if (!$this->isSorted) {
            $this->topologicalSort();
        }

        $sourcePos = $this->getPosition($sourceOid);
        $targetPos = $this->getPosition($targetOid);

        // If either not found, assume we should defer for safety
        if ($sourcePos === -1 || $targetPos === -1) {
            return true;
        }

        // Defer if target comes after source
        return $targetPos > $sourcePos;
    }

    /**
     * Get sorted nodes (performs sort if needed).
     *
     * @return array Array of sorted ObjectNode instances
     */
    public function getSortedNodes()
    {
        if (!$this->isSorted) {
            $this->topologicalSort();
        }
        return $this->sortedNodes;
    }

    /**
     * Get edges between circular dependency nodes for debugging.
     *
     * @return array Array of edge descriptions
     */
    public function getCircularEdges()
    {
        if (!$this->isSorted) {
            $this->topologicalSort();
        }

        $circularOids = [];
        foreach ($this->unsortedNodes as $node) {
            $circularOids[$node->oid] = true;
        }

        $edges = [];
        foreach ($this->unsortedNodes as $node) {
            foreach ($node->dependencies as $depOid) {
                if (isset($circularOids[$depOid])) {
                    $depNode = $this->nodes[$depOid];
                    $edges[] = [
                        'from' => $node->getQualifiedName(),
                        'from_type' => $node->type,
                        'to' => $depNode->getQualifiedName(),
                        'to_type' => $depNode->type,
                    ];
                }
            }
        }

        return $edges;
    }

    /**
     * Get count of nodes in graph.
     *
     * @return int Number of nodes
     */
    public function getNodeCount()
    {
        return count($this->nodes);
    }

    /**
     * Get all nodes in the graph.
     *
     * @return ObjectNode[] Array of all nodes
     */
    public function getAllNodes()
    {
        return array_values($this->nodes);
    }

    /**
     * Check if node A depends on node B (A → B).
     *
     * @param string $nodeOid OID of the dependent node
     * @param string $dependencyOid OID of the dependency
     * @return bool True if nodeOid depends on dependencyOid
     */
    public function hasDependency($nodeOid, $dependencyOid)
    {
        if (!isset($this->nodes[$nodeOid])) {
            return false;
        }

        return in_array($dependencyOid, $this->nodes[$nodeOid]->dependencies);
    }
}
