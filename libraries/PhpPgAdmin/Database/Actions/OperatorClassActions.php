<?php

namespace PhpPgAdmin\Database\Actions;



class OperatorClassActions extends ActionsBase
{

    /**
     * Gets all opclasses.
     *
     * @return \ADORecordSet A recordset
     */
    public function getOpClasses()
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $sql =
            "SELECT
                opc.oid, opc.opcname, am.amname,
                opc.opcintype::pg_catalog.regtype AS opcintype,
                opc.opcdefault,
                pg_catalog.pg_get_userbyid(opc.opcowner) AS opcowner,
                pg_catalog.obj_description(opc.oid, 'pg_opclass') AS opccomment
            FROM
                pg_catalog.pg_opclass opc
                JOIN pg_catalog.pg_am am ON opc.opcmethod = am.oid
            WHERE
                opc.opcnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}')
            ORDER BY
                am.amname, opc.opcname
        ";

        return $this->connection->selectSet($sql);
    }
}
