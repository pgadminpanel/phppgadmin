<?php

namespace PhpPgAdmin\Database\Actions;



class IndexActions extends ActionsBase
{
    // Base constructor inherited from Actions
    public const INDEX_TYPES = ['BTREE', 'HASH', 'GIST', 'GIN'];

    /**
     * Grabs a list of indexes for a table.
     */
    public function getIndexes($table = '', $unique = false)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql =
            "SELECT c2.relname AS indname, i.indisprimary, i.indisunique,
                i.indisclustered, i.indexrelid,
                pg_catalog.pg_get_indexdef(i.indexrelid, 0, true) AS inddef
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_catalog.pg_index i ON c.oid = i.indrelid
            JOIN pg_catalog.pg_class c2 ON i.indexrelid = c2.oid
            WHERE c.relname = '{$table}' AND n.nspname = '{$c_schema}'";
        if ($unique) {
            $sql .= " AND i.indisunique ";
        }
        $sql .= " ORDER BY c2.relname";

        return $this->connection->selectSet($sql);
    }

    /**
     * Test if a table has been clustered on an index.
     */
    public function alreadyClustered($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT i.indisclustered
            FROM pg_catalog.pg_class c, pg_catalog.pg_index i
            WHERE c.relname = '{$table}'
                AND c.oid = i.indrelid AND i.indisclustered
                AND c.relnamespace = (SELECT oid FROM pg_catalog.pg_namespace
                    WHERE nspname='{$c_schema}')
                ";

        $v = $this->connection->selectSet($sql);

        if ($v->recordCount() == 0) {
            return false;
        }

        return true;
    }

    /**
     * Creates an index.
     */
    public function createIndex($name, $table, $columns, $type, $unique, $where, $tablespace, $concurrently)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($table);

        $sql = "CREATE";
        if ($unique) {
            $sql .= " UNIQUE";
        }
        $sql .= " INDEX";
        if ($concurrently) {
            $sql .= " CONCURRENTLY";
        }
        $sql .= " \"{$name}\" ON \"{$f_schema}\".\"{$table}\" USING {$type} ";

        if (is_array($columns)) {
            $this->connection->arrayClean($columns);
            $sql .= "(\"" . implode('","', $columns) . "\")";
        } else {
            $sql .= "(" . $columns . ")";
        }

        if ($this->connection->hasTablespaces() && $tablespace != '') {
            $this->connection->fieldClean($tablespace);
            $sql .= " TABLESPACE \"{$tablespace}\"";
        }

        if (trim($where) != '') {
            $sql .= " WHERE ({$where})";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Removes an index from the database.
     */
    public function dropIndex($index, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($index);

        $sql = "DROP INDEX \"{$f_schema}\".\"{$index}\"";
        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Rebuild indexes.
     */
    public function reindex($type, $name, $force = false)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);
        switch ($type) {
            case 'DATABASE':
                $sql = "REINDEX {$type} \"{$name}\"";
                if ($force) {
                    $sql .= ' FORCE';
                }
                break;
            case 'TABLE':
            case 'INDEX':
                $sql = "REINDEX {$type} \"{$f_schema}\".\"{$name}\"";
                if ($force) {
                    $sql .= ' FORCE';
                }
                break;
            default:
                return -1;
        }

        return $this->connection->execute($sql);
    }

    /**
     * Clusters an index.
     */
    public function clusterIndex($table = '', $index = '')
    {
        $sql = 'CLUSTER';

        if (!empty($table)) {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $this->connection->fieldClean($table);
            $sql .= " \"{$f_schema}\".\"{$table}\"";

            if (!empty($index)) {
                $this->connection->fieldClean($index);
                $sql .= " USING \"{$index}\"";
            }
        }

        return $this->connection->execute($sql);
    }
}
