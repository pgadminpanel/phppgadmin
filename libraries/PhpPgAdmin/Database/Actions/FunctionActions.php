<?php

namespace PhpPgAdmin\Database\Actions;



class FunctionActions extends ActionsBase
{

    // Function properties
    public $funcprops = [
        ['', 'VOLATILE', 'IMMUTABLE', 'STABLE'],
        ['', 'CALLED ON NULL INPUT', 'RETURNS NULL ON NULL INPUT'],
        ['', 'SECURITY INVOKER', 'SECURITY DEFINER']
    ];

    /**
     * Returns all details for a particular function.
     */
    public function getFunction($function_oid)
    {
        $this->connection->clean($function_oid);

        $sql = "
            SELECT
                pc.oid AS prooid, proname, 
                pg_catalog.pg_get_userbyid(proowner) AS proowner,
                nspname as proschema, lanname as prolanguage, procost, prorows,
                pg_catalog.format_type(prorettype, NULL) as proresult, prosrc,
                probin, proretset, proisstrict, provolatile, prosecdef,
                pg_catalog.oidvectortypes(pc.proargtypes) AS proarguments,
                proargnames AS proargnames,
                pg_catalog.obj_description(pc.oid, 'pg_proc') AS procomment,
                proconfig,
                (select array_agg( (select typname from pg_type pt
                    where pt.oid = p.oid) ) from unnest(proallargtypes) p)
                AS proallarguments,
                proargmodes
            FROM
                pg_catalog.pg_proc pc, pg_catalog.pg_language pl,
                pg_catalog.pg_namespace pn
            WHERE
                pc.oid = '{$function_oid}'::oid AND pc.prolang = pl.oid
                AND pc.pronamespace = pn.oid
            ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns a list of all functions in the database.
     */
    public function getFunctions($all = false, $type = null)
    {
        if ($all) {
            $where = 'pg_catalog.pg_function_is_visible(p.oid)';
            $distinct = 'DISTINCT ON (p.proname)';

            if ($type) {
                $where .= " AND p.prorettype = (SELECT oid FROM pg_catalog.pg_type t WHERE t.typname = 'trigger')";
            }
        } else {
            $c_schema = $this->connection->_schema;
            $this->connection->clean($c_schema);
            $where = "n.nspname = '{$c_schema}'";
            $distinct = '';
        }

        // --- Version dependend fields ---
        if ($this->connection->major_version >= 11) {
            // PostgreSQL 11+
            $notAgg = "p.prokind <> 'a'";
            $protype = "
                CASE p.prokind
                    WHEN 'a' THEN 'agg'
                    WHEN 'w' THEN 'window'
                    WHEN 'p' THEN 'proc'
                    ELSE 'func'
                END
            ";
        } else {
            // PostgreSQL 9.0–10
            $notAgg = "NOT p.proisagg";
            $protype = "
                CASE
                    WHEN p.proisagg THEN 'agg'
                    WHEN p.proiswindow THEN 'window'
                    ELSE 'func'
                END
            ";
        }

        $sql =
            "SELECT
                {$distinct}
                p.oid AS prooid,
                p.proname,
                p.proretset,
                pg_catalog.format_type(p.prorettype, NULL) AS proresult,
                pg_catalog.oidvectortypes(p.proargtypes) AS proarguments,
                pl.lanname AS prolanguage,
                pg_catalog.obj_description(p.oid, 'pg_proc') AS procomment,
                p.proname || ' (' || pg_catalog.oidvectortypes(p.proargtypes) || ')' AS proproto,
                CASE WHEN p.proretset THEN 'setof ' ELSE '' END
                    || pg_catalog.format_type(p.prorettype, NULL) AS proreturns,
                u.usename AS proowner,
                {$protype} AS protype
            FROM pg_catalog.pg_proc p
                INNER JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                INNER JOIN pg_catalog.pg_language pl ON pl.oid = p.prolang
                LEFT JOIN pg_catalog.pg_user u ON u.usesysid = p.proowner
            WHERE
                {$notAgg}
                AND {$where}
            ORDER BY p.proname, proresult
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns an array containing a function's properties.
     */
    public function getFunctionProperties($f)
    {
        $temp = [];

        // Volatility
        if ($f['provolatile'] == 'v') {
            $temp[] = 'VOLATILE';
        } elseif ($f['provolatile'] == 'i') {
            $temp[] = 'IMMUTABLE';
        } elseif ($f['provolatile'] == 's') {
            $temp[] = 'STABLE';
        } else {
            return -1;
        }

        // Null handling
        $f['proisstrict'] = $this->connection->phpBool($f['proisstrict']);
        if ($f['proisstrict']) {
            $temp[] = 'RETURNS NULL ON NULL INPUT';
        } else {
            $temp[] = 'CALLED ON NULL INPUT';
        }

        // Security
        $f['prosecdef'] = $this->connection->phpBool($f['prosecdef']);
        if ($f['prosecdef']) {
            $temp[] = 'SECURITY DEFINER';
        } else {
            $temp[] = 'SECURITY INVOKER';
        }

        return $temp;
    }

    /**
     * Updates (replaces) a function.
     */
    public function setFunction($function_oid, $funcname, $newname, $args, $returns, $definition, $language, $flags, $setof, $funcown, $newown, $funcschema, $newschema, $cost, $rows, $comment)
    {
        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $status = $this->createFunction($funcname, $args, $returns, $definition, $language, $flags, $setof, $cost, $rows, $comment, true);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return $status;
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);

        $this->connection->fieldClean($newname);
        if ($funcname != $newname) {
            $sql = "ALTER FUNCTION \"{$f_schema}\".\"{$funcname}\"({$args}) RENAME TO \"{$newname}\"";
            $status = $this->connection->execute($sql);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -5;
            }

            $funcname = $newname;
        }

        if ($this->hasFunctionAlterOwner()) {
            $this->connection->fieldClean($newown);
            if ($funcown != $newown) {
                $sql = "ALTER FUNCTION \"{$f_schema}\".\"{$funcname}\"({$args}) OWNER TO \"{$newown}\"";
                $status = $this->connection->execute($sql);
                if ($status != 0) {
                    $this->connection->rollbackTransaction();
                    return -6;
                }
            }

        }

        if ($this->hasFunctionAlterSchema()) {
            $this->connection->fieldClean($newschema);
            if ($funcschema != $newschema) {
                $sql = "ALTER FUNCTION \"{$f_schema}\".\"{$funcname}\"({$args}) SET SCHEMA \"{$newschema}\"";
                $status = $this->connection->execute($sql);
                if ($status != 0) {
                    $this->connection->rollbackTransaction();
                    return -7;
                }
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Creates a new function.
     */
    public function createFunction($funcname, $args, $returns, $definition, $language, $flags, $setof, $cost, $rows, $comment, $replace = false)
    {
        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        $this->connection->fieldClean($funcname);
        $this->connection->clean($args);
        $this->connection->fieldClean($language);
        $this->connection->arrayClean($flags);
        $this->connection->clean($cost);
        $this->connection->clean($rows);
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);

        $sql = "CREATE";
        if ($replace) {
            $sql .= " OR REPLACE";
        }
        $sql .= " FUNCTION \"{$f_schema}\".\"{$funcname}\" (";

        if ($args != '') {
            $sql .= $args;
        }

        $sql .= ") RETURNS ";
        if ($setof) {
            $sql .= "SETOF ";
        }
        $sql .= "{$returns} AS ";

        if (is_array($definition)) {
            $this->connection->arrayClean($definition);
            $sql .= "'" . $definition[0] . "'";
            if ($definition[1]) {
                $sql .= ",'" . $definition[1] . "'";
            }
        } else {
            $this->connection->clean($definition);
            $sql .= "'" . $definition . "'";
        }

        $sql .= " LANGUAGE \"{$language}\"";

        if (!empty($cost)) {
            $sql .= " COST {$cost}";
        }

        if ($rows <> 0) {
            $sql .= " ROWS {$rows}";
        }

        foreach ($flags as $v) {
            if ($v == '') {
                continue;
            } else {
                $sql .= "\n{$v}";
            }
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -3;
        }

        $status = $this->connection->setComment('FUNCTION', "\"{$funcname}\"({$args})", null, $comment);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -4;
        }

        return $this->connection->endTransaction();
    }

    /**
     * Drops a function.
     */
    public function dropFunction($function_oid, $cascade)
    {
        $fn = $this->getFunction($function_oid);
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($fn->fields['proname']);

        $sql = "DROP FUNCTION \"{$f_schema}\".\"{$fn->fields['proname']}\"({$fn->fields['proarguments']})";
        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }

    function hasFunctionAlterOwner()
    {
        return true;
    }

    function hasFunctionAlterSchema()
    {
        return true;
    }

    function hasFunctionCosting()
    {
        return true;
    }

    function hasFunctionGUC()
    {
        return true;
    }

}
