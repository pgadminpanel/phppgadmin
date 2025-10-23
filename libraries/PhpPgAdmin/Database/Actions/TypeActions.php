<?php

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet_array;
use ADORecordSet_empty;


class TypeActions extends ActionsBase
{
    // Base constructor inherited from Actions


    public const NON_SORTABLE_TYPES = [
        'xml' => 'xml',
        'json' => 'json',
        'bytea' => 'bytea',
        'tsvector' => 'tsvector',
        'tsquery' => 'tsquery',
        'record' => 'record'
    ];

    public const NON_COMPARABLE_TYPES = [
        'xml' => 'xml',
        'record' => 'record',
    ];

    protected const INTERNAL_TYPE_MAP = [
        'int2' => 'smallint',
        'int4' => 'integer',
        'int8' => 'bigint',
        'float4' => 'real',
        'float8' => 'double precision',

        'varchar' => 'character varying',
        'bpchar' => 'character',

        'bool' => 'boolean',

        'time' => 'time without time zone',
        'timetz' => 'time with time zone',
        'timestamp' => 'timestamp without time zone',
        'timestamptz' => 'timestamp with time zone',
    ];

    protected const EXTERNAL_TYPE_MAP = [
        'smallint' => 'int2',
        'integer' => 'int4',
        'bigint' => 'int8',
        'real' => 'float4',
        'double precision' => 'float8',
        'character varying' => 'varchar',
        'character' => 'bpchar',
        'boolean' => 'bool',
        'time without time zone' => 'time',
        'time with time zone' => 'timetz',
        'timestamp without time zone' => 'timestamp',
        'timestamp with time zone' => 'timestamptz',
    ];

    /**
     * Maps an external type name to its internal representation.
     * timestamp(6) with time zone[] --> _timestamptz(6)
     */
    public static function mapToInternal(string $t): string
    {
        $t = strtolower(trim($t));

        $isArray = false;
        if (substr_compare($t, '[]', -2) === 0) {
            $isArray = true;
            $t = trim(substr($t, 0, -2));
        }

        $size = '';
        $trailing = '';
        $baseNoSize = $t;
        if (preg_match('/^(.+?)\s*\(\s*([^)]+?)\s*\)\s*(.*)$/', $t, $m)) {
            $baseNoSize = trim($m[1]);
            $size = "({$m[2]})";
            $trailing = trim($m[3]);
        } elseif (preg_match('/^(.+?)\s+(.*)$/', $t, $m)) {
            $baseNoSize = trim($m[1]);
            $trailing = trim($m[2]);
        }

        // Use EXTERNAL_TYPE_MAP to map external -> internal
        $internalBase = self::EXTERNAL_TYPE_MAP[$baseNoSize] ?? $baseNoSize;

        // Decide whether to keep size with internal base. Here we keep it.
        $internal = $internalBase . $size;
        if (!empty($trailing)) {
            $internal = trim($internal . ' ' . $trailing);
        }

        if ($isArray) {
            return "_$internal";
        }

        return $internal;
    }

    /**
     * Maps an internal type name to its external representation.
     * _timestamp(6) --> timestamp(6) without time zone[]
     */
    public static function mapToExternal(string $t): string
    {
        $t = strtolower(trim($t));

        $isArray = false;
        if (str_starts_with($t, '_')) {
            $isArray = true;
            $t = trim(substr($t, 1));
        }

        $size = '';
        $trailing = '';
        $baseNoSize = $t;
        if (preg_match('/^(.+?)\s*\(\s*([^)]+?)\s*\)\s*(.*)$/', $t, $m)) {
            $baseNoSize = trim($m[1]);
            $size = "({$m[2]})";
            $trailing = trim($m[3]);
        } elseif (preg_match('/^(.+?)\s+(.*)$/', $t, $m)) {
            $baseNoSize = trim($m[1]);
            $trailing = trim($m[2]);
        }

        // Use INTERNAL_TYPE_MAP to map internal -> external
        $externalBase = self::INTERNAL_TYPE_MAP[$baseNoSize] ?? $baseNoSize;

        $out = $externalBase;
        if ($size !== '') {
            if (strpos($externalBase, ' with ') !== false || strpos($externalBase, ' without ') !== false) {
                $pos = strpos($externalBase, ' ');
                $out = ($pos !== false)
                    ? substr($externalBase, 0, $pos) . $size . substr($externalBase, $pos)
                    : $externalBase . $size;
            } else {
                $out = $externalBase . $size;
            }
        }

        if (!empty($trailing)) {
            $out = trim($out . ' ' . $trailing);
        }

        if ($isArray) {
            return "{$out}[]";
        }

        return $out;
    }


    /**
     * Returns all details for a particular type.
     */
    public function getType($typname)
    {
        $this->connection->clean($typname);

        $sql = "SELECT
                t.typtype,
                t.typbyval,
                t.typname,
                t.typinput AS typin,
                t.typoutput AS typout,
                t.typlen,
                t.typalign,
                pu.usename AS typowner,
                pg_catalog.obj_description(t.oid, 'pg_type') AS typcomment
            FROM pg_type t
                LEFT JOIN pg_catalog.pg_user pu ON t.typowner = pu.usesysid
            WHERE typname='{$typname}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns a list of all types in the database.
     */
    public function getTypes($all = false, $tabletypes = false, $domains = false)
    {
        if ($all) {
            $where = '1 = 1';
        } else {
            $c_schema = $this->connection->_schema;
            $this->connection->clean($c_schema);
            $where = "n.nspname = '{$c_schema}'";
        }

        $where2 = "AND c.relnamespace NOT IN (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname LIKE 'pg@_%' ESCAPE '@')";

        $tqry = "'c'";
        if ($tabletypes) {
            $tqry .= ", 'r', 'v'";
        }

        if (!$domains) {
            $where .= " AND t.typtype != 'd'";
        }

        $sql = "SELECT
            t.oid,
            t.typname AS basename,
            pg_catalog.format_type(t.oid, NULL) AS typname,
                pu.usename AS typowner,
                t.typtype,
                pg_catalog.obj_description(t.oid, 'pg_type') AS typcomment
            FROM (pg_catalog.pg_type t
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace)
                LEFT JOIN pg_catalog.pg_user pu ON t.typowner = pu.usesysid
            WHERE (t.typrelid = 0 OR (SELECT c.relkind IN ({$tqry}) FROM pg_catalog.pg_class c WHERE c.oid = t.typrelid {$where2}))
            AND t.typelem = 0  -- Exclude array types
            -- AND t.typname !~ '^_'
            AND {$where}
            ORDER BY typname
        ";
        //var_dump($sql);

        return $this->connection->selectSet($sql);
    }

    public function getTypeMetasByNames(array $typeNames)
    {
        if (empty($typeNames)) {
            return [];
        }

        // Build VALUES list of escaped literals
        $values = [];
        foreach ($typeNames as $external) {
            $externalTrim = trim($external);
            $escaped = $this->connection->escapeLiteral($externalTrim);
            $values[] = "($escaped)";
        }

        $valuesList = implode(',', $values);

        // Use regtype to resolve each textual type to its oid, then join pg_type
        $sql =
            "WITH inputs(input_text) AS (
                VALUES {$valuesList}
            )
            SELECT
                i.input_text,
                t.oid,
                t.typname,
                t.typtype,
                t.typlen,
                t.typelem,
                t.typinput,
                t.typbasetype::regtype AS base_type,
                format_type(t.oid, NULL) AS canonical_name
            FROM inputs i
            JOIN pg_type t ON t.oid = (i.input_text::regtype)::oid";

        $rs = $this->connection->selectSet($sql);

        $metas = [];
        if (\is_object($rs)) {
            while (!$rs->EOF) {
                $inputText = $rs->fields['input_text'];
                $metas[$inputText] = $rs->fields;
                $rs->moveNext();
            }
        }

        return $metas;
    }


    public function isLargeTypeMeta($meta)
    {
        if (!is_array($meta)) {
            return false;
        }
        // Arrays are always varlena
        if (
            $meta['typelem'] != '0' &&
            $meta['typinput'] === 'array_in'
        ) {
            return true;
        }

        // Enums are always small
        if ($meta['typtype'] === 'e') {
            return false;
        }

        // varlena → large
        if ($meta['typlen'] == '-1') {
            return true;
        }

        // all other → small
        return false;
    }

    public function isArrayType($meta)
    {
        if (!is_array($meta)) {
            return false;
        }
        return $meta['typelem'] != '0' && $meta['typinput'] === 'array_in';
    }


    /**
     * Creates a new base type.
     */
    public function createBaseType($typname, $typin, $typout, $typlen, $typdef, $typelem, $typdelim, $typbyval, $typalign, $typstorage)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typname);
        $this->connection->fieldClean($typin);
        $this->connection->fieldClean($typout);

        $sql = "
            CREATE TYPE \"{$f_schema}\".\"{$typname}\" (
                INPUT = \"{$typin}\",
                OUTPUT = \"{$typout}\",
                INTERNALLENGTH = {$typlen}";
        if ($typdef != '') {
            $sql .= ", DEFAULT = {$typdef}";
        }
        if ($typelem != '') {
            $sql .= ", ELEMENT = {$typelem}";
        }
        if ($typdelim != '') {
            $sql .= ", DELIMITER = {$typdelim}";
        }
        if ($typbyval) {
            $sql .= ", PASSEDBYVALUE, ";
        }
        if ($typalign != '') {
            $sql .= ", ALIGNMENT = {$typalign}";
        }
        if ($typstorage != '') {
            $sql .= ", STORAGE = {$typstorage}";
        }

        $sql .= ")";

        return $this->connection->execute($sql);
    }

    /**
     * Drops a type.
     */
    public function dropType($typname, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typname);

        $sql = "DROP TYPE \"{$f_schema}\".\"{$typname}\"";
        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }

    /**
     * Creates a new enum type in the database.
     */
    public function createEnumType($name, $values, $typcomment)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);

        if (empty($values)) {
            return -2;
        }

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            return -1;
        }

        $values = array_unique($values);
        $nbval = count($values);

        for ($i = 0; $i < $nbval; $i++) {
            $this->connection->clean($values[$i]);
        }

        $sql = "CREATE TYPE \"{$f_schema}\".\"{$name}\" AS ENUM ('";
        $sql .= implode("','", $values);
        $sql .= "')";

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($typcomment != '') {
            $status = $this->connection->setComment('TYPE', $name, '', $typcomment, true);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Get defined values for a given enum.
     */
    public function getEnumValues($name)
    {
        $this->connection->clean($name);

        $sql = "SELECT enumlabel AS enumval
        FROM pg_catalog.pg_type t JOIN pg_catalog.pg_enum e ON (t.oid=e.enumtypid)
        WHERE t.typname = '{$name}' ORDER BY e.oid";
        return $this->connection->selectSet($sql);
    }

    /**
     * Creates a new composite type in the database.
     */
    public function createCompositeType($name, $fields, $field, $type, $array, $length, $colcomment, $typcomment)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            return -1;
        }

        $found = false;
        $first = true;
        $comment_sql = '';
        $sql = "CREATE TYPE \"{$f_schema}\".\"{$name}\" AS (";
        for ($i = 0; $i < $fields; $i++) {
            $this->connection->fieldClean($field[$i]);
            $this->connection->clean($type[$i]);
            $this->connection->clean($length[$i]);
            $this->connection->clean($colcomment[$i]);

            if ($field[$i] == '' || $type[$i] == '') {
                continue;
            }
            if (!$first) {
                $sql .= ", ";
            } else {
                $first = false;
            }

            switch ($type[$i]) {
                case 'timestamp with time zone':
                case 'timestamp without time zone':
                    $qual = substr($type[$i], 9);
                    $sql .= "\"{$field[$i]}\" timestamp";
                    if ($length[$i] != '') {
                        $sql .= "({$length[$i]})";
                    }
                    $sql .= $qual;
                    break;
                case 'time with time zone':
                case 'time without time zone':
                    $qual = substr($type[$i], 4);
                    $sql .= "\"{$field[$i]}\" time";
                    if ($length[$i] != '') {
                        $sql .= "({$length[$i]})";
                    }
                    $sql .= $qual;
                    break;
                default:
                    $sql .= "\"{$field[$i]}\" {$type[$i]}";
                    if ($length[$i] != '') {
                        $sql .= "({$length[$i]})";
                    }
            }

            if ($array[$i] == '[]') {
                $sql .= '[]';
            }

            if ($colcomment[$i] != '') {
                $comment_sql .= "COMMENT ON COLUMN \"{$f_schema}\".\"{$name}\".\"{$field[$i]}\" IS '{$colcomment[$i]}';\n";
            }

            $found = true;
        }

        if (!$found) {
            return -1;
        }

        $sql .= ")";

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($typcomment != '') {
            $status = $this->connection->setComment('TYPE', $name, '', $typcomment, true);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        if ($comment_sql != '') {
            $status = $this->connection->execute($comment_sql);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }
        return $this->connection->endTransaction();
    }

    /**
     * Returns a list of all conversions in the database.
     */
    public function getConversions()
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $sql = "
            SELECT
                   c.conname,
                   pg_catalog.pg_encoding_to_char(c.conforencoding) AS conforencoding,
                   pg_catalog.pg_encoding_to_char(c.contoencoding) AS contoencoding,
                   c.condefault,
                   pg_catalog.obj_description(c.oid, 'pg_conversion') AS concomment
            FROM pg_catalog.pg_conversion c, pg_catalog.pg_namespace n
            WHERE n.oid = c.connamespace
                  AND n.nspname='{$c_schema}'
            ORDER BY 1;
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Renames a type.
     */
    public function renameType($typename, $newname)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typename);
        $this->connection->fieldClean($newname);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$typename}\" RENAME TO \"{$newname}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Changes the owner of a type.
     */
    public function changeTypeOwner($typename, $newowner)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typename);
        $this->connection->fieldClean($newowner);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$typename}\" OWNER TO \"{$newowner}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Changes the schema of a type.
     */
    public function changeTypeSchema($typename, $newschema)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($typename);
        $this->connection->fieldClean($newschema);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$typename}\" SET SCHEMA \"{$newschema}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Renames an enum type value.
     */
    public function renameEnumTypeValue($type, $oldValue, $newValue)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($type);
        $this->connection->clean($oldValue);
        $this->connection->clean($newValue);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$type}\" RENAME VALUE '{$oldValue}' TO '{$newValue}'";

        return $this->connection->execute($sql);
    }

    /**
     * Adds a new value to an enum type.
     */
    public function addEnumTypeValue($type, $newValue, $before = null, $after = null)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($type);
        $this->connection->clean($newValue);

        $sql = "ALTER TYPE \"{$f_schema}\".\"{$type}\" ADD VALUE '{$newValue}'";

        if ($before !== null) {
            $this->connection->clean($before);
            $sql .= " BEFORE '{$before}'";
        } elseif ($after !== null) {
            $this->connection->clean($after);
            $sql .= " AFTER '{$after}'";
        }

        return $this->connection->execute($sql);
    }

    public function hasTypeAddValue(): bool
    {
        return $this->connection->major_version >= 9.1;
    }

    public function hasTypeRenameValue(): bool
    {
        return $this->connection->major_version >= 10;
    }

    public function hasEnumTypes(): bool
    {
        // Todo remove validation?
        return true;
    }

}
