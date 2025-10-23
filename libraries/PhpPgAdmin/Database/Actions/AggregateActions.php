<?php

namespace PhpPgAdmin\Database\Actions;



class AggregateActions extends ActionsBase
{

    /**
     * Creates a new aggregate in the database.
     */
    public function createAggregate($name, $basetype, $sfunc, $stype, $ffunc, $initcond, $sortop, $comment)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);

        // Types and function names should be emitted unquoted (they may contain spaces
        // like "double precision"), so clean them for safety but do not quote.
        $this->connection->clean($basetype);
        $this->connection->clean($sfunc);
        $this->connection->clean($stype);
        $this->connection->clean($ffunc);
        $this->connection->clean($initcond);
        $this->connection->clean($sortop);

        $this->connection->beginTransaction();

        $sql = "CREATE AGGREGATE \"{$f_schema}\".\"{$name}\" (BASETYPE = {$basetype}, SFUNC = {$sfunc}, STYPE = {$stype}";
        if (trim($ffunc) != '') {
            $sql .= ", FINALFUNC = {$ffunc}";
        }
        if (trim($initcond) != '') {
            $sql .= ", INITCOND = '{$initcond}'";
        }
        if (trim($sortop) != '') {
            $sql .= ", SORTOP = {$sortop}";
        }
        $sql .= ")";

        $status = $this->connection->execute($sql);
        if ($status) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if (trim($comment) != '') {
            $status = $this->connection->setComment('AGGREGATE', $name, '', $comment, $basetype);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Renames an aggregate function.
     */
    public function renameAggregate($aggrschema, $aggrname, $aggrtype, $newaggrname)
    {
        // support NONE and unquoted type names (may contain spaces)
        if ($aggrtype === null || $aggrtype === '') {
            $typessql = 'NONE';
        } else {
            $this->connection->clean($aggrtype);
            $typessql = $aggrtype;
        }
        $sql = "ALTER AGGREGATE \"{$aggrschema}\"." . "\"{$aggrname}\" ({$typessql}) RENAME TO \"{$newaggrname}\"";
        return $this->connection->execute($sql);
    }

    /**
     * Removes an aggregate function from the database.
     */
    public function dropAggregate($aggrname, $aggrtype, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($aggrname);
        // Types may contain spaces; clean but don't quote
        $this->connection->clean($aggrtype);

        $typessql = ($aggrtype === null || $aggrtype === '') ? 'NONE' : $aggrtype;

        $sql = "DROP AGGREGATE \"{$f_schema}\".\"{$aggrname}\" ({$typessql})";
        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Gets all information for an aggregate.
     */
    public function getAggregate($name, $basetype)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($basetype);

        $sql =
            "SELECT p.proname, CASE p.proargtypes[0]
                WHEN 'pg_catalog.\"any\"'::pg_catalog.regtype THEN NULL
                ELSE pg_catalog.format_type(p.proargtypes[0], NULL)
                END AS proargtypes,
                a.aggtransfn::pg_catalog.regproc AS aggtransfn,
                sfn.nspname AS sfuncnspname,
                format_type(a.aggtranstype, NULL) AS aggstype,
                a.aggfinalfn::pg_catalog.regproc AS aggfinalfn,
                ffn.nspname AS finalfnnspname,
                a.agginitval, a.aggsortop, nop.nspname AS oprnspname,
                o.oprname, u.usename,
                pg_catalog.obj_description(p.oid, 'pg_proc') AS aggrcomment
            FROM pg_catalog.pg_proc p
            JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
            JOIN pg_catalog.pg_user u ON p.proowner = u.usesysid
            JOIN pg_catalog.pg_aggregate a ON p.oid = a.aggfnoid
            LEFT JOIN pg_catalog.pg_operator o ON o.oid = a.aggsortop::oid
            LEFT JOIN pg_catalog.pg_namespace nop ON nop.oid = o.oprnamespace
            LEFT JOIN pg_catalog.pg_proc sproc ON sproc.oid = a.aggtransfn::oid
            LEFT JOIN pg_catalog.pg_namespace sfn ON sfn.oid = sproc.pronamespace
            LEFT JOIN pg_catalog.pg_proc fproc ON fproc.oid = a.aggfinalfn::oid
            LEFT JOIN pg_catalog.pg_namespace ffn ON ffn.oid = fproc.pronamespace
            WHERE p.prokind = 'a'
                AND n.nspname = '{$c_schema}'
                AND p.proname = '{$name}'
                AND CASE p.proargtypes[0]
                    WHEN 'pg_catalog.\"any\"'::pg_catalog.regtype THEN ''
                    ELSE pg_catalog.format_type(p.proargtypes[0], NULL)
                END = '{$basetype}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Gets all aggregates.
     */
    public function getAggregates()
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);

        if ($this->connection->major_version >= 11) {
            $aggFlag = "p.prokind = 'a'";
        } else {
            $aggFlag = "p.proisagg";
        }

        $sql =
            "SELECT
                p.proname,
                CASE p.proargtypes[0]
                    WHEN 'pg_catalog.\"any\"'::pg_catalog.regtype THEN NULL
                    ELSE pg_catalog.format_type(p.proargtypes[0], NULL)
                END AS proargtypes,
                a.aggtransfn,
                u.usename,
                pg_catalog.obj_description(p.oid, 'pg_proc') AS aggrcomment
            FROM
                pg_catalog.pg_proc p,
                pg_catalog.pg_namespace n,
                pg_catalog.pg_user u,
                pg_catalog.pg_aggregate a
            WHERE
                n.oid = p.pronamespace
                AND p.proowner = u.usesysid
                AND p.oid = a.aggfnoid
                AND {$aggFlag}
                AND n.nspname = '{$c_schema}'
            ORDER BY 1, 2
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Changes the owner of an aggregate function.
     */
    public function changeAggregateOwner($aggrname, $aggrtype, $newaggrowner)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($aggrname);
        $this->connection->fieldClean($newaggrowner);
        $this->connection->clean($aggrtype);
        $typessql = ($aggrtype === null || $aggrtype === '') ? 'NONE' : $aggrtype;
        $sql = "ALTER AGGREGATE \"{$f_schema}\".\"{$aggrname}\" ({$typessql}) OWNER TO \"{$newaggrowner}\"";
        return $this->connection->execute($sql);
    }

    /**
     * Changes the schema of an aggregate function.
     */
    public function changeAggregateSchema($aggrname, $aggrtype, $newaggrschema)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($aggrname);
        $this->connection->fieldClean($newaggrschema);
        $this->connection->clean($aggrtype);
        $typessql = ($aggrtype === null || $aggrtype === '') ? 'NONE' : $aggrtype;
        $sql = "ALTER AGGREGATE \"{$f_schema}\".\"{$aggrname}\" ({$typessql}) SET SCHEMA  \"{$newaggrschema}\"";
        return $this->connection->execute($sql);
    }

    /**
     * Alters an aggregate (rename/owner/schema/comment).
     */
    public function alterAggregate($aggrname, $aggrtype, $aggrowner, $aggrschema, $aggrcomment, $newaggrname, $newaggrowner, $newaggrschema, $newaggrcomment)
    {
        $this->connection->fieldClean($aggrname);
        $this->connection->fieldClean($aggrtype);
        $this->connection->fieldClean($aggrowner);
        $this->connection->fieldClean($aggrschema);
        $this->connection->fieldClean($newaggrname);
        $this->connection->fieldClean($newaggrowner);
        $this->connection->fieldClean($newaggrschema);

        $this->connection->beginTransaction();

        if ($aggrowner != $newaggrowner) {
            $status = $this->changeAggregateOwner($aggrname, $aggrtype, $newaggrowner);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        if ($aggrcomment != $newaggrcomment) {
            $status = $this->connection->setComment('AGGREGATE', $aggrname, '', $newaggrcomment, $aggrtype);
            if ($status) {
                $this->connection->rollbackTransaction();
                return -2;
            }
        }

        if ($aggrschema != $newaggrschema) {
            $status = $this->changeAggregateSchema($aggrname, $aggrtype, $newaggrschema);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -3;
            }
        }

        if ($aggrname != $newaggrname) {
            $status = $this->renameAggregate($newaggrschema, $aggrname, $aggrtype, $newaggrname);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -4;
            }
        }

        return $this->connection->endTransaction();
    }
}
