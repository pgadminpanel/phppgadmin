<?php

namespace PhpPgAdmin\Database\Actions;



class ColumnActions extends ActionsBase
{
    public const EXCLUDE_TYPES = [

        // --- Polymorphic Type ---
        'anyelement',
        'anyarray',
        'anynonarray',
        'anyenum',
        'anyrange',
        'anymultirange',
        'anycompatible',
        'anycompatiblearray',
        'anycompatiblenonarray',
        'anycompatiblerange',
        'anycompatiblemultirange',

        // --- Internal Types ---
        'internal',
        'cstring',
        'oidvector',
        'tid',
        'xid',
        'xid8',
        'unknown',

        // --- Handler Types ---
        'trigger',
        'event_trigger',
        'fdw_handler',
        'table_am_handler',
        'index_am_handler',
        'tsm_handler',

        // --- System catalog types (reg*) ---
        'regclass',
        'regtype',
        'regproc',
        'regprocedure',
        'regoperator',
        'regoper',
        'regnamespace',
        'regrole',
        'regconfig',
        'regdictionary',
        'regcollation',

        // --- PG internal Structures ---
        'pg_lsn',
        'pg_snapshot',
        'pg_node_tree',
        'pg_mcv_list',
        'pg_ndistinct',
        'pg_dependencies',
        'pg_ddl_command',
        'pg_brin_bloom_summary',
        'pg_brin_minmax_multi_summary',

        // --- Information Schema Types ---
        'information_schema.cardinal_number',
        'information_schema.character_data',
        'information_schema.sql_identifier',
        'information_schema.time_stamp',
        'information_schema.yes_or_no',

        // Special cases: internal pseudotypes 
        '"any"',
        '"char"',
    ];

    /**
     * @var array
     */
    private $allowedStorage = ['p', 'e', 'm', 'x'];

    /**
     * Alters a column in a table
     * @param string $table The table in which the column resides
     * @param string $column The column to alter
     * @param string $name The new name for the column
     * @param bool $notnull (boolean) True if not null, false otherwise
     * @param bool $oldnotnull (boolean) True if column is already not null, false otherwise
     * @param mixed $default The new default for the column
     * @param mixed $olddefault The old default for the column
     * @param string $type The new type for the column
     * @param bool $array True if array type, false otherwise
     * @param string $length The optional size of the column (ie. 30 for varchar(30))
     * @param string $oldtype The old type for the column
     * @param string $comment Comment for the column
     * @return int 0 success
     * @return int -1 batch alteration failed
     * @return int -4 rename column error
     * @return int -5 comment error
     * @return int -6 transaction error
     */
    public function alterColumn(
        $table,
        $column,
        $name,
        $notnull,
        $oldnotnull,
        $default,
        $olddefault,
        $type,
        $length,
        $array,
        $oldtype,
        $comment
    ) {
        // Begin transaction
        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -6;
        }

        // Rename the column, if it has been changed
        if ($column != $name) {
            $status = $this->renameColumn($table, $column, $name);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -4;
            }
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);

        $toAlter = array();
        // Create the command for changing nullability
        if ($notnull != $oldnotnull) {
            $toAlter[] = "ALTER COLUMN \"{$name}\" " . (($notnull) ? 'SET' : 'DROP') . " NOT NULL";
        }

        // Add default, if it has changed
        if ($default != $olddefault) {
            if ($default == '') {
                $toAlter[] = "ALTER COLUMN \"{$name}\" DROP DEFAULT";
            } else {
                $toAlter[] = "ALTER COLUMN \"{$name}\" SET DEFAULT {$default}";
            }
        }

        // Add type, if it has changed
        if ($length == '')
            $ftype = $type;
        else {
            switch ($type) {
                // Have to account for weird placing of length for with/without
                // time zone types
                case 'timestamp with time zone':
                case 'timestamp without time zone':
                    $qual = substr($type, 9);
                    $ftype = "timestamp({$length}){$qual}";
                    break;
                case 'time with time zone':
                case 'time without time zone':
                    $qual = substr($type, 4);
                    $ftype = "time({$length}){$qual}";
                    break;
                default:
                    $ftype = "{$type}({$length})";
            }
        }

        // Add array qualifier, if requested
        if ($array)
            $ftype .= '[]';

        if ($ftype != $oldtype) {
            $toAlter[] = "ALTER COLUMN \"{$name}\" TYPE {$ftype}";
        }

        // Attempt to process the batch alteration, if anything has been changed
        if (!empty($toAlter)) {
            // Initialise an empty SQL string
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" "
                . implode(',', $toAlter);

            $status = $this->connection->execute($sql);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        // Update the comment on the column
        $status = $this->connection->setComment('COLUMN', $name, $table, $comment);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -5;
        }

        return $this->connection->endTransaction();
    }


    /**
     * Add a new column to a table.
     */
    public function addColumn($table, $column, $type, $array, $length, $notnull, $default, $comment, $isGenerated = false)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($type);
        $this->connection->clean($length);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ADD COLUMN \"{$column}\"";
        if ($length == '')
            $sql .= " {$type}";
        else {
            switch ($type) {
                case 'timestamp with time zone':
                case 'timestamp without time zone':
                    $qual = substr($type, 9);
                    $sql .= " timestamp({$length}){$qual}";
                    break;
                case 'time with time zone':
                case 'time without time zone':
                    $qual = substr($type, 4);
                    $sql .= " time({$length}){$qual}";
                    break;
                default:
                    $sql .= " {$type}({$length})";
            }
        }

        if ($array)
            $sql .= '[]';
        if ($notnull)
            $sql .= ' NOT NULL';

        // Handle generated columns vs regular defaults
        if ($isGenerated) {
            // For generated columns, the 'default' parameter contains the generation expression
            if ($default != '') {
                $sql .= ' GENERATED ALWAYS AS (' . $default . ') STORED';
            }
        } else {
            // Regular default value
            if ($default != '')
                $sql .= ' DEFAULT ' . $default;
        }

        $status = $this->connection->execute($sql);
        if ($status == 0 && trim($comment) != '') {
            $this->connection->setComment('COLUMN', $column, $table, $comment, true);
        }

        return $status;
    }

    /**
     * Rename a column.
     */
    public function renameColumn($table, $column, $newName)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->fieldClean($newName);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" RENAME COLUMN \"{$column}\" TO \"{$newName}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Set a column's DEFAULT.
     */
    public function setColumnDefault($table, $column, $default)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($default);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET DEFAULT {$default}";

        return $this->connection->execute($sql);
    }

    /**
     * Drop a column's DEFAULT.
     */
    public function dropColumnDefault($table, $column)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" DROP DEFAULT";

        return $this->connection->execute($sql);
    }

    /**
     * Set column nullability.
     */
    public function setColumnNull($table, $column, $state)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\"";
        $sql .= ($state) ? ' SET' : ' DROP';
        $sql .= ' NOT NULL';

        return $this->connection->execute($sql);
    }

    /**
     * Drop a column.
     */
    public function dropColumn($table, $column, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" DROP COLUMN \"{$column}\"";
        if ($cascade)
            $sql .= " CASCADE";

        return $this->connection->execute($sql);
    }

    /**
     * Set column statistics target.
     */
    public function setColumnStats($table, $column, $value)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($value);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET STATISTICS {$value}";

        return $this->connection->execute($sql);
    }

    /**
     * Set column storage.
     */
    public function setColumnStorage($table, $column, $storage)
    {
        if (!in_array($storage, $this->allowedStorage)) {
            return -1;
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($storage);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET STORAGE {$storage}";

        return $this->connection->execute($sql);
    }

    /**
     * Set column compression (Pg14+). Included for completeness.
     */
    public function setColumnCompression($table, $column, $compression)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($compression);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET COMPRESSION {$compression}";

        return $this->connection->execute($sql);
    }

    /**
     * Set column type.
     */
    public function setColumnType($table, $column, $type, $length, $array, $default, $notnull)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        $this->connection->clean($type);
        $this->connection->clean($length);

        if ($length == '')
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" TYPE {$type}";
        else {
            switch ($type) {
                case 'timestamp with time zone':
                case 'timestamp without time zone':
                    $qual = substr($type, 9);
                    $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" TYPE timestamp({$length}){$qual}";
                    break;
                case 'time with time zone':
                case 'time without time zone':
                    $qual = substr($type, 4);
                    $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" TYPE time({$length}){$qual}";
                    break;
                default:
                    $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" TYPE {$type}({$length})";
            }
        }

        if ($array)
            $sql .= '[]';
        if ($default != '')
            $sql .= " USING {$default}";
        $status = $this->connection->execute($sql);

        if ($status == 0) {
            $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\"";
            if ($notnull) {
                $sql .= ' SET NOT NULL';
            } else {
                $sql .= ' DROP NOT NULL';
            }
            $status = $this->connection->execute($sql);
        }

        return $status;
    }

    /**
     * Set a generated column's expression (PostgreSQL 17+).
     * Changes the generation expression of a stored generated column.
     *
     * @param string $table The table containing the column
     * @param string $column The column name
     * @param string $expression The new generation expression (without parentheses)
     * @return int 0 on success, non-zero on error
     */
    public function setGeneratedExpression($table, $column, $expression)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);
        // Don't clean expression - it's an SQL expression

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" SET EXPRESSION AS ({$expression})";

        return $this->connection->execute($sql);
    }

    /**
     * Drop a generated column's expression (PostgreSQL 13+).
     * Converts a stored generated column to a regular column, keeping existing values.
     *
     * @param string $table The table containing the column
     * @param string $column The column name
     * @return int 0 on success, non-zero on error
     */
    public function dropGeneratedExpression($table, $column)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($column);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ALTER COLUMN \"{$column}\" DROP EXPRESSION";

        return $this->connection->execute($sql);
    }
}
