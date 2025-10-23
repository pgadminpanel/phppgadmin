<?php

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet;

use ADORecordSet_empty;
use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\RuleActions;

class PartitionActions extends ActionsBase
{
    public const PARTITION_STRATEGY_MAP = [
        'r' => 'RANGE',
        'l' => 'LIST',
        'h' => 'HASH'
    ];

    /**
     * Returns information about a partitioned table's partition strategy and keys.
     * @param $table The table name
     * @return \ADORecordSet|int A recordset with partstrat and partition key columns
     */
    public function getPartitionInfo($table)
    {
        if ($this->connection->major_version < 10) {
            return -1;
        }

        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT 
                p.partstrat,
                p.partnatts,
                ARRAY(
                    SELECT a.attname
                    FROM pg_attribute a
                    WHERE a.attrelid = p.partrelid
                    AND a.attnum = ANY(p.partattrs)
                    ORDER BY array_position(p.partattrs, a.attnum)
                ) AS partition_keys
            FROM pg_partitioned_table p
            JOIN pg_class c ON c.oid = p.partrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = '{$c_schema}'
            AND c.relname = '{$table}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns all partitions of a partitioned table.
     * @param $table The parent table name
     * @return \ADORecordSet|int A recordset with partition information
     */
    public function getPartitions($table)
    {
        if ($this->connection->major_version < 10) {
            return -1;
        }

        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT 
                c.relname,
                c.relkind,
                pg_get_expr(c.relpartbound, c.oid) AS partition_bound,
                c.reltuples::bigint,
                pg_relation_size(c.oid) AS size,
                pg_catalog.pg_get_userbyid(c.relowner) AS relowner,
                pg_catalog.obj_description(c.oid, 'pg_class') AS comment
            FROM pg_class c
            JOIN pg_inherits i ON i.inhrelid = c.oid
            JOIN pg_class parent ON parent.oid = i.inhparent
            JOIN pg_namespace n ON n.oid = parent.relnamespace
            WHERE n.nspname = '{$c_schema}'
            AND parent.relname = '{$table}'
            AND parent.relkind = 'p'
            ORDER BY c.relname";

        return $this->connection->selectSet($sql);
    }

    /**
     * Checks if a table is partitioned.
     * @param $table The table name
     * @return bool True if table is partitioned
     */
    public function isPartitionedTable($table)
    {
        if ($this->connection->major_version < 10) {
            return false;
        }

        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT c.relkind
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = '{$c_schema}'
            AND c.relname = '{$table}'";

        $relkind = $this->connection->selectField($sql, 'relkind');
        return $relkind == 'p';
    }

    /**
     * Checks if a table is a partition of another table.
     * @param $table The table name
     * @return bool True if table is a partition
     */
    public function isPartition($table)
    {
        if ($this->connection->major_version < 10) {
            return false;
        }

        $schema = $this->connection->_schema;
        $this->connection->clean($schema);
        $this->connection->clean($table);

        $sql =
            "SELECT c.relispartition
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = '{$schema}'
            AND c.relname = '{$table}'";

        return $this->connection->selectField($sql, 'relispartition') === 't';
    }


    /**
     * Returns the parent table of a partition.
     * @param $table The partition table name
     * @return string|null The parent table name or null
     */
    public function getPartitionParent($table)
    {
        if ($this->connection->major_version < 10) {
            return null;
        }

        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT 
                parent.relname,
                pn.nspname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_inherits i ON i.inhrelid = c.oid
            JOIN pg_class parent ON parent.oid = i.inhparent
            JOIN pg_namespace pn ON pn.oid = parent.relnamespace
            WHERE n.nspname = '{$c_schema}'
            AND c.relname = '{$table}'
            AND parent.relkind = 'p'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Checks if a partition is a default partition (PG11+).
     * @param $table The partition table name
     * @return bool True if partition is default
     */
    public function isDefaultPartition($table)
    {
        if ($this->connection->major_version < 11) {
            return false;
        }

        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT 
                c.relpartbound IS NULL AS is_default
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_inherits i ON i.inhrelid = c.oid
            WHERE n.nspname = '{$c_schema}'
            AND c.relname = '{$table}'";

        return $this->connection->selectField($sql, 'is_default') === 't';
    }

    /**
     * Returns constraints for a partition, distinguishing inherited from local.
     * @param $table The table name
     * @param $inherited If true, return inherited constraints; if false, return local only; if null, return all
     * @return \ADORecordSet A recordset with constraint information
     */
    public function getPartitionConstraints($table, $inherited = null)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $inheritedFilter = '';
        if ($inherited === true) {
            $inheritedFilter = "AND con.coninhcount > 0";
        } elseif ($inherited === false) {
            $inheritedFilter = "AND con.coninhcount = 0";
        }

        $sql = "SELECT 
                con.conname,
                con.contype,
                con.conislocal,
                con.coninhcount,
                pg_get_constraintdef(con.oid) AS condef
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = '{$c_schema}'
            AND c.relname = '{$table}'
            {$inheritedFilter}
            ORDER BY con.conname";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns total row count across all partitions.
     * @param $table The partitioned table name
     * @return int Total estimated rows
     */
    public function getTotalPartitionRows($table)
    {
        if ($this->connection->major_version < 10) {
            return 0;
        }

        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT COALESCE(SUM(c.reltuples::bigint), 0) AS total_rows
            FROM pg_class c
            JOIN pg_inherits i ON i.inhrelid = c.oid
            JOIN pg_class parent ON parent.oid = i.inhparent
            JOIN pg_namespace n ON n.oid = parent.relnamespace
            WHERE n.nspname = '{$c_schema}'
            AND parent.relname = '{$table}'
            AND parent.relkind = 'p'";

        return $this->connection->selectField($sql, 'total_rows');
    }

    /**
     * Analyzes a partitioned table and all its partitions.
     * @param $table The partitioned table name
     * @return int 0 on success, -1 on error
     */
    public function analyzeAllPartitions($table)
    {
        if ($this->connection->major_version < 10) {
            return -1;
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);

        // ANALYZE on parent table cascades to all partitions in PG10+
        $sql = "ANALYZE \"{$f_schema}\".\"{$table}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Checks if partition pruning is enabled.
     * @return array Array with 'enabled' boolean and 'setting' value
     */
    public function getPartitionPruningEnabled()
    {
        if ($this->connection->major_version < 10) {
            return ['enabled' => false, 'setting' => 'N/A'];
        }

        $sql = "SHOW enable_partition_pruning";
        $setting = $this->connection->selectField($sql, 'enable_partition_pruning');

        return [
            'enabled' => $setting === 'on',
            'setting' => $setting
        ];
    }

    /**
     * Parses and returns partition boundaries for RANGE partitions with date/timestamp keys.
     * @param $table The partitioned table name
     * @return array|null Array of partition boundaries or null
     */
    public function getPartitionBoundaries($table)
    {
        if ($this->connection->major_version < 10) {
            return null;
        }

        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        // First check if this is a RANGE partition
        $info = $this->getPartitionInfo($table);
        if (!is_object($info) || $info->recordCount() == 0 || $info->fields['partstrat'] != 'r') {
            return null;
        }

        // Get partitions with bounds
        $sql = "SELECT 
                c.relname,
                pg_get_expr(c.relpartbound, c.oid) AS partition_bound,
                c.relpartbound IS NULL AS is_default
            FROM pg_class c
            JOIN pg_inherits i ON i.inhrelid = c.oid
            JOIN pg_class parent ON parent.oid = i.inhparent
            JOIN pg_namespace n ON n.oid = parent.relnamespace
            WHERE n.nspname = '{$c_schema}'
            AND parent.relname = '{$table}'
            AND parent.relkind = 'p'
            ORDER BY c.relpartbound NULLS LAST, c.relname";

        $result = $this->connection->selectSet($sql);
        $boundaries = [];

        while (!$result->EOF) {
            if ($result->fields['is_default'] === 't') {
                $boundaries[] = [
                    'name' => $result->fields['relname'],
                    'is_default' => true,
                    'from' => null,
                    'to' => null
                ];
            } else {
                $bound = $result->fields['partition_bound'];
                // Parse bounds like "FOR VALUES FROM ('2024-01-01') TO ('2024-02-01')"
                if (preg_match("/FROM \('([^']+)'\) TO \('([^']+)'\)/", $bound, $matches)) {
                    $boundaries[] = [
                        'name' => $result->fields['relname'],
                        'is_default' => false,
                        'from' => $matches[1],
                        'to' => $matches[2]
                    ];
                }
            }
            $result->moveNext();
        }

        return $boundaries;
    }

    /**
     * Creates a new partition for a partitioned table.
     * @param string $parentTable The parent partitioned table name
     * @param string $partitionName The name for the new partition
     * @param string $strategy Partition strategy ('r' for RANGE, 'l' for LIST, 'h' for HASH)
     * @param array $values Partition values based on strategy:
     *                      - RANGE: ['from' => value, 'to' => value]
     *                      - LIST: ['values' => comma-separated values]
     *                      - HASH: ['modulus' => int, 'remainder' => int]
     * @param bool $isDefault Whether this is a default partition (PG11+)
     * @return int 0 on success, -1 on error
     */
    public function createPartition($parentTable, $partitionName, $strategy, $values = [], $isDefault = false)
    {
        if ($this->connection->major_version < 10) {
            return -1;
        }

        // Check for default partition support
        if ($isDefault && $this->connection->major_version < 11) {
            return -1;
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($parentTable);
        $this->connection->fieldClean($partitionName);

        $sql = "CREATE TABLE \"{$f_schema}\".\"{$partitionName}\" PARTITION OF \"{$f_schema}\".\"{$parentTable}\"";

        // Check for default partition
        if ($isDefault) {
            $sql .= " DEFAULT";
        } else {
            // Add FOR VALUES clause based on strategy
            switch ($strategy) {
                case 'r': // RANGE
                    $this->connection->clean($values['from']);
                    $this->connection->clean($values['to']);
                    $sql .= " FOR VALUES FROM ('{$values['from']}') TO ('{$values['to']}')";
                    break;

                case 'l': // LIST
                    $values = explode(',', $values['values']);
                    $escapedValues = [];
                    foreach ($values as $val) {
                        $escapedValues[] = $this->connection->escapeLiteral(
                            trim($val)
                        );
                    }
                    $valuesList = implode(', ', $escapedValues);
                    $sql .= " FOR VALUES IN ({$valuesList})";
                    break;

                case 'h': // HASH
                    $this->connection->clean($values['modulus']);
                    $this->connection->clean($values['remainder']);
                    $sql .= " FOR VALUES WITH (MODULUS '{$values['modulus']}', REMAINDER '{$values['remainder']}')";
                    break;

                default:
                    return -1;
            }
        }

        return $this->connection->execute($sql);
    }

    /**
     * Attaches an existing table as a partition to a partitioned table.
     * @param string $parentTable The parent partitioned table name
     * @param string $partitionTable The existing table to attach as partition
     * @param string $strategy Partition strategy ('r' for RANGE, 'l' for LIST, 'h' for HASH)
     * @param array $values Partition values (same format as createPartition)
     * @param bool $isDefault Whether this is a default partition (PG11+)
     * @return int 0 on success, -1 on error
     */
    public function attachPartition($parentTable, $partitionTable, $strategy, $values = [], $isDefault = false)
    {
        if ($this->connection->major_version < 10) {
            return -1;
        }

        // Check for default partition support
        if ($isDefault && $this->connection->major_version < 11) {
            return -1;
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($parentTable);
        $this->connection->fieldClean($partitionTable);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$parentTable}\" ATTACH PARTITION \"{$f_schema}\".\"{$partitionTable}\"";

        // Check for default partition
        if ($isDefault) {
            $sql .= " DEFAULT";
        } else {
            // Add FOR VALUES clause based on strategy
            switch ($strategy) {
                case 'r': // RANGE
                    $this->connection->clean($values['from']);
                    $this->connection->clean($values['to']);
                    $sql .= " FOR VALUES FROM ('{$values['from']}') TO ('{$values['to']}')";
                    break;

                case 'l': // LIST
                    $values = explode(',', $values['values']);
                    $escapedValues = [];
                    foreach ($values as $val) {
                        $escapedValues[] = $this->connection->escapeLiteral(
                            trim($val)
                        );
                    }
                    $valuesList = implode(', ', $escapedValues);
                    $sql .= " FOR VALUES IN ({$valuesList})";
                    break;

                case 'h': // HASH
                    $this->connection->clean($values['modulus']);
                    $this->connection->clean($values['remainder']);
                    $sql .= " FOR VALUES WITH (MODULUS '{$values['modulus']}', REMAINDER '{$values['remainder']}')";
                    break;

                default:
                    return -1;
            }
        }

        return $this->connection->execute($sql);
    }
}
