<?php

namespace PhpPgAdmin\Database\Actions;



class ConstraintActions extends ActionsBase
{
    public const FK_ACTIONS = ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL', 'SET DEFAULT'];

    public const FK_MATCHES = ['MATCH SIMPLE', 'MATCH FULL', 'MATCH PARTIAL'];

    public const FK_DEFERRABLE = ['NOT DEFERRABLE', 'DEFERRABLE'];

    public const FK_INITIALLY = ['INITIALLY IMMEDIATE', 'INITIALLY DEFERRED'];

    /**
     * Returns a list of all constraints on a table.
     */
    /*
    public function getConstraints($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT
                pc.conname,
                pg_catalog.pg_get_constraintdef(pc.oid, true) AS consrc,
                pc.contype,
                CASE WHEN pc.contype='u' OR pc.contype='p' THEN (
                    SELECT
                        indisclustered
                    FROM
                        pg_catalog.pg_depend pd,
                        pg_catalog.pg_class pl,
                        pg_catalog.pg_index pi
                    WHERE
                        pd.refclassid=pc.tableoid
                        AND pd.refobjid=pc.oid
                        AND pd.objid=pl.oid
                        AND pl.oid=pi.indexrelid
                ) ELSE
                    NULL
                END AS indisclustered
            FROM
                pg_catalog.pg_constraint pc
            WHERE
                pc.conrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}'
                    AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace
                    WHERE nspname='{$c_schema}'))
            ORDER BY
                1
        ";

        return $this->connection->selectSet($sql);
    }
    */

    /**
     * Returns a list of all constraints on a table.
     * Modernized version with resolved column names (as array).
     */
    public function getConstraints($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $partition_fields = '';
        if ($this->connection->major_version >= 10) {
            $partition_fields = ', c.conislocal, c.coninhcount';
        }

        $sql =
            "SELECT
                c.conname,
                pg_catalog.pg_get_constraintdef(c.oid, true) AS consrc,
                c.contype,

                -- Array of column names
                array_agg(a.attname ORDER BY a.attnum) AS columns,

                -- Clustered info (only for PK/UNIQUE)
                i.indisclustered,

                -- Referenced table schema and name (for FK)
                ns2.nspname AS f_schema,
                r2.relname AS f_table

                -- Partition-related fields (PG 10+)
                {$partition_fields}

            FROM pg_catalog.pg_constraint c
            JOIN pg_catalog.pg_class r
                ON r.oid = c.conrelid
            JOIN pg_catalog.pg_namespace n
                ON n.oid = r.relnamespace

            -- Resolve column names
            LEFT JOIN pg_catalog.pg_attribute a
                ON a.attrelid = r.oid
                AND a.attnum = ANY (c.conkey)

            -- Join index directly (only PK/UNIQUE have conindid)
            LEFT JOIN pg_catalog.pg_index i
                ON i.indexrelid = c.conindid

            -- Referenced table (for FK)
            LEFT JOIN pg_catalog.pg_class r2
                ON r2.oid = c.confrelid
            LEFT JOIN pg_catalog.pg_namespace ns2
                ON ns2.oid = r2.relnamespace

            WHERE r.relname = '{$table}'
            AND n.nspname = '{$c_schema}'

            GROUP BY
                c.oid, c.conname, c.contype, i.indisclustered, ns2.nspname, r2.relname {$partition_fields}

            ORDER BY c.conname
        ";

        return $this->connection->selectSet($sql);
    }


    /**
     * Returns a list of all constraints on a table with field details.
     * Optimized version (PG ≥ 9.0), drop‑in compatible.
     */
    public function getConstraintsWithFields($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql =
            "SELECT
                c.oid AS conid,
                c.contype,
                c.conname,
                pg_catalog.pg_get_constraintdef(c.oid, true) AS consrc,

                ns1.nspname AS p_schema,
                r1.relname AS p_table,

                ns2.nspname AS f_schema,
                r2.relname AS f_table,

                a1.attname AS p_field,
                a1.attnum AS p_attnum,

                a2.attname AS f_field,
                a2.attnum AS f_attnum,

                pg_catalog.obj_description(c.oid, 'pg_constraint') AS constcomment,

                c.conrelid,
                c.confrelid

            FROM pg_catalog.pg_constraint c

            -- primary/unique/check table
            JOIN pg_catalog.pg_class r1
                ON r1.oid = c.conrelid
            JOIN pg_catalog.pg_namespace ns1
                ON ns1.oid = r1.relnamespace

            -- referenced table (FK only)
            LEFT JOIN pg_catalog.pg_class r2
                ON r2.oid = c.confrelid
            LEFT JOIN pg_catalog.pg_namespace ns2
                ON ns2.oid = r2.relnamespace

            -- columns of the constrained table
            LEFT JOIN pg_catalog.pg_attribute a1
                ON a1.attrelid = r1.oid
                AND a1.attnum = ANY(c.conkey)

            -- columns of the referenced table (FK only)
            LEFT JOIN pg_catalog.pg_attribute a2
                ON a2.attrelid = r2.oid
                AND a2.attnum = ANY(c.confkey)

            WHERE r1.relname = '{$table}'
            AND ns1.nspname = '{$c_schema}'

            ORDER BY c.oid, a1.attnum
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns a list of all constraints on a table with field details.
     */
    /*
    public function getConstraintsWithFields($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT DISTINCT
            max(SUBSTRING(array_dims(c.conkey) FROM  \$pattern\$^\\[.*:(.*)\\]$\$pattern\$)) as nb
        FROM pg_catalog.pg_constraint AS c
            JOIN pg_catalog.pg_class AS r ON (c.conrelid=r.oid)
            JOIN pg_catalog.pg_namespace AS ns ON (r.relnamespace=ns.oid)
        WHERE
            r.relname = '{$table}' AND ns.nspname='{$c_schema}'";

        $rs = $this->connection->selectSet($sql);

        if ($rs->EOF) {
            $max_col = 0;
        } else {
            $max_col = $rs->fields['nb'];
        }

        $sql = '
            SELECT
                c.oid AS conid, c.contype, c.conname, pg_catalog.pg_get_constraintdef(c.oid, true) AS consrc,
                ns1.nspname as p_schema, r1.relname as p_table, ns2.nspname as f_schema,
                r2.relname as f_table, f1.attname as p_field, f1.attnum AS p_attnum, f2.attname as f_field,
                f2.attnum AS f_attnum, pg_catalog.obj_description(c.oid, \'pg_constraint\') AS constcomment,
                c.conrelid, c.confrelid
            FROM
                pg_catalog.pg_constraint AS c
                JOIN pg_catalog.pg_class AS r1 ON (c.conrelid=r1.oid)
                JOIN pg_catalog.pg_attribute AS f1 ON (f1.attrelid=r1.oid AND (f1.attnum=c.conkey[1]';
        for ($i = 2; $i <= $rs->fields['nb']; $i++) {
            $sql .= " OR f1.attnum=c.conkey[$i]";
        }
        $sql .= '))
                JOIN pg_catalog.pg_namespace AS ns1 ON r1.relnamespace=ns1.oid
                LEFT JOIN (
                    pg_catalog.pg_class AS r2 JOIN pg_catalog.pg_namespace AS ns2 ON (r2.relnamespace=ns2.oid)
                ) ON (c.confrelid=r2.oid)
                LEFT JOIN pg_catalog.pg_attribute AS f2 ON
                    (f2.attrelid=r2.oid AND ((c.confkey[1]=f2.attnum AND c.conkey[1]=f1.attnum)';
        for ($i = 2; $i <= $rs->fields['nb']; $i++) {
            $sql .= " OR (c.confkey[$i]=f2.attnum AND c.conkey[$i]=f1.attnum)";
        }

        $sql .= sprintf("))
            WHERE
                r1.relname = '%s' AND ns1.nspname='%s'
            ORDER BY 1", $table, $c_schema);

        return $this->connection->selectSet($sql);
    }
    */

    /**
     * Adds a primary key constraint to a table.
     */
    public function addPrimaryKey($table, $fields, $name = '', $tablespace = '')
    {
        if (!is_array($fields) || sizeof($fields) == 0) {
            return -1;
        }
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldArrayClean($fields);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($tablespace);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ADD ";
        if ($name != '') {
            $sql .= "CONSTRAINT \"{$name}\" ";
        }
        $sql .= "PRIMARY KEY (\"" . join('","', $fields) . "\")";

        if ($tablespace != '' && $this->connection->hasTablespaces()) {
            $sql .= " USING INDEX TABLESPACE \"{$tablespace}\"";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Adds a unique constraint to a table.
     */
    public function addUniqueKey($table, $fields, $name = '', $tablespace = '')
    {
        if (!is_array($fields) || sizeof($fields) == 0) {
            return -1;
        }
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldArrayClean($fields);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($tablespace);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ADD ";
        if ($name != '') {
            $sql .= "CONSTRAINT \"{$name}\" ";
        }
        $sql .= "UNIQUE (\"" . join('","', $fields) . "\")";

        if ($tablespace != '' && $this->connection->hasTablespaces()) {
            $sql .= " USING INDEX TABLESPACE \"{$tablespace}\"";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Adds a check constraint to a table.
     */
    public function addCheckConstraint($table, $definition, $name = '')
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($name);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ADD ";
        if ($name != '') {
            $sql .= "CONSTRAINT \"{$name}\" ";
        }
        $sql .= "CHECK ({$definition})";

        return $this->connection->execute($sql);
    }

    /**
     * Drops a check constraint from a table.
     */
    public function dropCheckConstraint($table, $name)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $c_table = $table;
        $this->connection->fieldClean($table);
        $this->connection->clean($c_table);
        $this->connection->clean($name);

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            return -2;
        }

        $sql = "LOCK TABLE \"{$f_schema}\".\"{$table}\" IN ACCESS EXCLUSIVE MODE";
        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -3;
        }

        $sql = "DELETE FROM pg_relcheck WHERE rcrelid=(SELECT oid FROM pg_catalog.pg_class WHERE relname='{$c_table}'
            AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE
            nspname = '{$c_schema}')) AND rcname='{$name}'";
        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -4;
        }

        $sql = "UPDATE pg_class SET relchecks=(SELECT COUNT(*) FROM pg_relcheck WHERE
                    rcrelid=(SELECT oid FROM pg_catalog.pg_class WHERE relname='{$c_table}'
                        AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE
                        nspname = '{$c_schema}')))
                    WHERE relname='{$c_table}'";
        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -4;
        }

        return $this->connection->endTransaction();
    }

    /**
     * Adds a foreign key constraint to a table.
     */
    public function addForeignKey(
        $table,
        $targschema,
        $targtable,
        $sfields,
        $tfields,
        $upd_action,
        $del_action,
        $match,
        $deferrable,
        $initially,
        $name = ''
    ) {
        if (
            !is_array($sfields) || sizeof($sfields) == 0 ||
            !is_array($tfields) || sizeof($tfields) == 0
        ) {
            return -1;
        }
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($targschema);
        $this->connection->fieldClean($targtable);
        $this->connection->fieldArrayClean($sfields);
        $this->connection->fieldArrayClean($tfields);
        $this->connection->fieldClean($name);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ADD ";
        if ($name != '') {
            $sql .= "CONSTRAINT \"{$name}\" ";
        }
        $sql .= "FOREIGN KEY (\"" . join('","', $sfields) . "\") ";
        $sql .= "REFERENCES \"{$targschema}\".\"{$targtable}\"(\"" . join('","', $tfields) . "\") ";
        //$fkmatches = ['MATCH SIMPLE', 'MATCH FULL', 'MATCH PARTIAL'];
        //$fkactions = ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL', 'SET DEFAULT'];
        //$fkdeferrable = ['NOT DEFERRABLE', 'DEFERRABLE'];
        //$fkinitial = ['IMMEDIATE', 'DEFERRED'];
        if ($match != self::FK_MATCHES[0]) {
            $sql .= " {$match}";
        }
        if ($upd_action != self::FK_ACTIONS[0]) {
            $sql .= " ON UPDATE {$upd_action}";
        }
        if ($del_action != self::FK_ACTIONS[0]) {
            $sql .= " ON DELETE {$del_action}";
        }
        if ($deferrable != self::FK_DEFERRABLE[0]) {
            $sql .= " {$deferrable}";
        }
        if ($initially != self::FK_INITIALLY[0]) {
            $sql .= " {$initially}";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Removes a constraint from a relation.
     */
    public function dropConstraint($constraint, $relation, $type, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($constraint);
        $this->connection->fieldClean($relation);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$relation}\" DROP CONSTRAINT \"{$constraint}\"";
        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Gets all columns linked by foreign keys given a group of tables.
     */
    public function getLinkingKeys($tables)
    {
        if (!is_array($tables)) {
            return -1;
        }

        $this->connection->clean($tables[0]['tablename']);
        $this->connection->clean($tables[0]['schemaname']);
        $tables_list = "'{$tables[0]['tablename']}'";
        $schema_list = "'{$tables[0]['schemaname']}'";
        $schema_tables_list = "'{$tables[0]['schemaname']}.{$tables[0]['tablename']}'";

        for ($i = 1; $i < sizeof($tables); $i++) {
            $this->connection->clean($tables[$i]['tablename']);
            $this->connection->clean($tables[$i]['schemaname']);
            $tables_list .= ", '{$tables[$i]['tablename']}'";
            $schema_list .= ", '{$tables[$i]['schemaname']}'";
            $schema_tables_list .= ", '{$tables[$i]['schemaname']}.{$tables[$i]['tablename']}'";
        }

        $maxDimension = 1;

        $sql = "
            SELECT DISTINCT
                array_dims(pc.conkey) AS arr_dim,
                pgc1.relname AS p_table
            FROM
                pg_catalog.pg_constraint AS pc,
                pg_catalog.pg_class AS pgc1
            WHERE
                pc.contype = 'f'
                AND (pc.conrelid = pgc1.relfilenode OR pc.confrelid = pgc1.relfilenode)
                AND pgc1.relname IN ($tables_list)
            ";

        $rs = $this->connection->selectSet($sql);
        while (!$rs->EOF) {
            $arrData = explode(':', $rs->fields['arr_dim']);
            $dimension = intval(substr($arrData[1], 0, -1));
            $maxDimension = max($dimension, $maxDimension);
            $rs->MoveNext();
        }

        $cons_str = '( (pfield.attnum = conkey[1] AND cfield.attnum = confkey[1]) ';
        for ($i = 2; $i <= $maxDimension; $i++) {
            $cons_str .= "OR (pfield.attnum = conkey[{$i}] AND cfield.attnum = confkey[{$i}]) ";
        }
        $cons_str .= ') ';

        $sql = "
            SELECT
                pgc1.relname AS p_table,
                pgc2.relname AS f_table,
                pfield.attname AS p_field,
                cfield.attname AS f_field,
                pgns1.nspname AS p_schema,
                pgns2.nspname AS f_schema
            FROM
                pg_catalog.pg_constraint AS pc,
                pg_catalog.pg_class AS pgc1,
                pg_catalog.pg_class AS pgc2,
                pg_catalog.pg_attribute AS pfield,
                pg_catalog.pg_attribute AS cfield,
                (SELECT oid AS ns_id, nspname FROM pg_catalog.pg_namespace WHERE nspname IN ($schema_list) ) AS pgns1,
                 (SELECT oid AS ns_id, nspname FROM pg_catalog.pg_namespace WHERE nspname IN ($schema_list) ) AS pgns2
            WHERE
                pc.contype = 'f'
                AND pgc1.relnamespace = pgns1.ns_id
                 AND pgc2.relnamespace = pgns2.ns_id
                AND pc.conrelid = pgc1.relfilenode
                AND pc.confrelid = pgc2.relfilenode
                AND pfield.attrelid = pc.conrelid
                AND cfield.attrelid = pc.confrelid
                AND $cons_str
                AND pgns1.nspname || '.' || pgc1.relname IN ($schema_tables_list)
                AND pgns2.nspname || '.' || pgc2.relname IN ($schema_tables_list)
        ";
        return $this->connection->selectSet($sql);
    }

    /**
     * Finds the foreign keys that refer to the specified table.
     */
    public function getReferrers($table)
    {
        $this->connection->clean($table);

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            return -1;
        }

        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);

        $sql = "
            SELECT
                pn.nspname,
                pl.relname,
                pc.conname,
                pg_catalog.pg_get_constraintdef(pc.oid) AS consrc
            FROM
                pg_catalog.pg_constraint pc,
                pg_catalog.pg_namespace pn,
                pg_catalog.pg_class pl
            WHERE
                pc.connamespace = pn.oid
                AND pc.conrelid = pl.oid
                AND pc.contype = 'f'
                AND confrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}'
                    AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace
                    WHERE nspname='{$c_schema}'))
            ORDER BY 1,2,3
        ";

        return $this->connection->selectSet($sql);
    }
}
