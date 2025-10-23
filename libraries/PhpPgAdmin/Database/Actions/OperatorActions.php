<?php

namespace PhpPgAdmin\Database\Actions;



class OperatorActions extends ActionsBase
{

    /**
     * Gets all operators.
     */
    public function getOperators()
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $sql =
            "SELECT
                po.oid, po.oprname,
                pg_catalog.pg_get_userbyid(po.oprowner) AS oprowner,
                (SELECT pg_catalog.format_type(oid, NULL) FROM pg_catalog.pg_type pt WHERE pt.oid=po.oprleft) AS oprleftname,
                (SELECT pg_catalog.format_type(oid, NULL) FROM pg_catalog.pg_type pt WHERE pt.oid=po.oprright) AS oprrightname,
                po.oprresult::pg_catalog.regtype AS resultname,
                pg_catalog.obj_description(po.oid, 'pg_operator') AS oprcomment
            FROM
                pg_catalog.pg_operator po
            WHERE
                po.oprnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}')
            ORDER BY
                po.oprname, oprleftname, oprrightname
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns all details for a particular operator.
     */
    public function getOperator($operator_oid)
    {
        $this->connection->clean($operator_oid);

        $sql =
            "SELECT
                po.oid, po.oprname,
                oprleft::pg_catalog.regtype AS oprleftname,
                oprright::pg_catalog.regtype AS oprrightname,
                oprresult::pg_catalog.regtype AS resultname,
                po.oprcanhash,
                po.oprcanmerge,
                oprcom::pg_catalog.regoperator AS oprcom,
                oprnegate::pg_catalog.regoperator AS oprnegate,
                p.proname AS oprcode,
                pn.nspname AS procnsname,
                prest.proname AS oprrest,
                pnrest.nspname AS restnsname,
                pjoin.proname AS oprjoin,
                pnjoin.nspname AS joinnsname,
                pg_catalog.obj_description(po.oid, 'pg_operator') AS oprcomment
            FROM
                pg_catalog.pg_operator po
                LEFT JOIN pg_catalog.pg_proc p ON p.oid = po.oprcode::oid
                LEFT JOIN pg_catalog.pg_namespace pn ON pn.oid = p.pronamespace
                LEFT JOIN pg_catalog.pg_proc prest ON prest.oid = po.oprrest::oid
                LEFT JOIN pg_catalog.pg_namespace pnrest ON pnrest.oid = prest.pronamespace
                LEFT JOIN pg_catalog.pg_proc pjoin ON pjoin.oid = po.oprjoin::oid
                LEFT JOIN pg_catalog.pg_namespace pnjoin ON pnjoin.oid = pjoin.pronamespace
            WHERE
                po.oid='{$operator_oid}'
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Drops an operator.
     */
    public function dropOperator($operator_oid, $cascade)
    {
        $opr = $this->getOperator($operator_oid);
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($opr->fields['oprname']);

        $sql = "DROP OPERATOR \"{$f_schema}\".{$opr->fields['oprname']} (";
        if ($opr->fields['oprleftname'] !== null) {
            $sql .= $opr->fields['oprleftname'] . ', ';
        } else {
            $sql .= "NONE, ";
        }
        if ($opr->fields['oprrightname'] !== null) {
            $sql .= $opr->fields['oprrightname'] . ')';
        } else {
            $sql .= "NONE)";
        }

        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Creates a new operator.
     */
    public function createOperator($name, $procedure, $leftarg, $rightarg, $commutator, $negator, $restrict, $join, $hashes, $merges, $comment)
    {
        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        //$this->connection->fieldClean($name);
        //$f_schema = $this->connection->_schema;
        //$this->connection->fieldClean($f_schema);

        $sql = "CREATE OPERATOR {$name} (";

        // PROCEDURE is required for operator creation; strip any signature and properly quote schema/name
        if (!empty($procedure)) {
            // Strip any "(...)" if accidentally included
            if (strpos($procedure, '(') !== false) {
                $procedure = strstr($procedure, '(', true);
            }
            $procedure = trim($procedure);

            // Build a safe, quoted procedure reference
            if (strpos($procedure, '.') !== false) {
                list($pschema, $pfunc) = explode('.', $procedure, 2);
                $this->connection->fieldClean($pschema);
                $this->connection->fieldClean($pfunc);
                $pschema = '"' . $pschema . '"';
                $pfunc = '"' . $pfunc . '"';
                $proc_sql = "{$pschema}.{$pfunc}";
            } else {
                $this->connection->fieldClean($procedure);
                $proc_sql = '"' . $procedure . '"';
            }

            $sql .= "PROCEDURE = {$proc_sql}";
        } else {
            // Procedure missing
            $this->connection->rollbackTransaction();
            return -2;
        }

        if ($leftarg !== null && $leftarg !== '' && strtoupper($leftarg) !== 'NONE') {
            $sql .= ", LEFTARG = {$leftarg}";
        }
        if ($rightarg !== null && $rightarg !== '' && strtoupper($rightarg) !== 'NONE') {
            $sql .= ", RIGHTARG = {$rightarg}";
        }
        if (!empty($commutator)) {
            $sql .= ", COMMUTATOR = {$commutator}";
        }
        if (!empty($negator)) {
            $sql .= ", NEGATOR = {$negator}";
        }
        if (!empty($restrict)) {
            $sql .= ", RESTRICT = {$restrict}";
        }
        if (!empty($join)) {
            $sql .= ", JOIN = {$join}";
        }
        if ($hashes) {
            $sql .= ", HASHES";
        }
        if ($merges) {
            $sql .= ", MERGES";
        }

        $sql .= ")";

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -3;
        }

        // set comment if present
        $status = $this->connection->setComment('OPERATOR', $name, $leftarg, $comment, $rightarg);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -4;
        }

        return $this->connection->endTransaction();
    }
}