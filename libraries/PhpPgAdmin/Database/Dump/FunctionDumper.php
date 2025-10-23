<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL functions.
 */
class FunctionDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $functionOid = $params['function_oid'] ?? null;
        if (!$functionOid) {
            return;
        }

        $c_oid = $this->connection->escapeString($functionOid);

        $sql = "SELECT
                    pg_catalog.pg_get_functiondef('{$c_oid}'::oid) AS funcdef,
                    pg_catalog.pg_get_function_identity_arguments('{$c_oid}'::oid) AS funcid, proname, nspname 
                      FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE p.oid = '{$c_oid}'::oid";
        $rs = $this->connection->selectSet($sql);

        if (!$rs || $rs->EOF) {
            return;
        }

        $def = $rs->fields['funcdef'];


        $proname = $this->connection->quoteIdentifier($rs->fields['proname']);
        $nspname = $this->connection->quoteIdentifier($rs->fields['nspname']);

        $this->write("\n-- Function: {$nspname}.{$proname}\n");

        // Handle DROP if requested
        $this->writeDrop('FUNCTION', "{$nspname}.{$proname}({$rs->fields['funcid']})", $options);

        $this->write("$def;\n");
    }
}
