<?php

namespace PhpPgAdmin\Database\Dump\DependencyGraph;

/**
 * Represents a database object node in the dependency graph.
 * 
 * Nodes can represent functions, tables, domains, or other objects
 * that participate in dependency relationships.
 */
class ObjectNode
{
    /**
     * @var string Object OID in PostgreSQL catalog
     */
    public $oid;

    /**
     * @var string Object type: 'function', 'table', 'domain', 'type'
     */
    public $type;

    /**
     * @var string Object name (unquoted)
     */
    public $name;

    /**
     * @var string Schema name (unquoted)
     */
    public $schema;

    /**
     * @var int Position in topologically sorted order (set after sorting)
     */
    public $position = -1;

    /**
     * @var array Array of OIDs this node depends on
     */
    public $dependencies = [];

    /**
     * @var array Additional metadata for specific object types
     */
    public $metadata = [];

    /**
     * Create a new object node.
     *
     * @param string $oid Object OID
     * @param string $type Object type
     * @param string $name Object name
     * @param string $schema Schema name
     * @param array $metadata Optional metadata
     */
    public function __construct($oid, $type, $name, $schema, array $metadata = [])
    {
        $this->oid = $oid;
        $this->type = $type;
        $this->name = $name;
        $this->schema = $schema;
        $this->metadata = $metadata;
    }

    /**
     * Add a dependency edge from this node to another.
     *
     * @param string $dependsOnOid OID of object this node depends on
     */
    public function addDependency($dependsOnOid)
    {
        if (!in_array($dependsOnOid, $this->dependencies)) {
            $this->dependencies[] = $dependsOnOid;
        }
    }

    /**
     * Get fully qualified object name.
     *
     * @return string Schema-qualified name
     */
    public function getQualifiedName()
    {
        return $this->schema . '.' . $this->name;
    }

    /**
     * Convert node to string for debugging.
     *
     * @return string String representation
     */
    public function __toString()
    {
        return sprintf(
            '%s %s (OID: %s, Position: %d)',
            ucfirst($this->type),
            $this->getQualifiedName(),
            $this->oid,
            $this->position
        );
    }
}
