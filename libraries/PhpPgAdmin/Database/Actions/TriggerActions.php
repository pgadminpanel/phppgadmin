<?php

namespace PhpPgAdmin\Database\Actions;


class TriggerActions extends ActionsBase
{
    public $triggerEvents = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'INSERT OR UPDATE',
        'INSERT OR DELETE',
        'DELETE OR UPDATE',
        'INSERT OR DELETE OR UPDATE'
    ];

    public $triggerExecTimes = ['BEFORE', 'AFTER'];
    public $triggerFrequency = ['ROW', 'STATEMENT'];


    /** @var FunctionActions */
    private $functionAction;


    private function getFunctionAction()
    {
        if ($this->functionAction === null) {
            $this->functionAction = new FunctionActions($this->connection);
        }

        return $this->functionAction;
    }

    /**
     * Grabs a single trigger.
     */
    public function getTrigger($table, $trigger)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);
        $this->connection->clean($trigger);

        $sql =
            "SELECT 
                t.*, 
                pg_get_triggerdef(t.oid, true) AS trigger_def,
                pg_get_functiondef(p.oid) AS function_def
            FROM pg_trigger t
            JOIN pg_class c ON t.tgrelid = c.oid
            JOIN pg_namespace n ON c.relnamespace = n.oid
            JOIN pg_proc p ON t.tgfoid = p.oid
            WHERE c.relname = '{$table}'
            AND t.tgname = '{$trigger}'
            AND n.nspname = '{$c_schema}'";

        return $this->connection->selectSet($sql);
    }

    /**
     * Grabs a list of triggers on a table.
     */
    public function getTriggers($table = '')
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT
                t.tgname, pg_catalog.pg_get_triggerdef(t.oid) AS tgdef,
                CASE WHEN t.tgenabled = 'D' THEN FALSE ELSE TRUE END AS tgenabled, p.oid AS prooid,
                p.proname || ' (' || pg_catalog.oidvectortypes(p.proargtypes) || ')' AS proproto,
                ns.nspname AS pronamespace
            FROM pg_catalog.pg_trigger t, pg_catalog.pg_proc p, pg_catalog.pg_namespace ns
            WHERE t.tgrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}'
                AND relnamespace=(SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}'))
                AND ( tgconstraint = 0 OR NOT EXISTS
                        (SELECT 1 FROM pg_catalog.pg_depend d    JOIN pg_catalog.pg_constraint c
                            ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                        WHERE d.classid = t.tableoid AND d.objid = t.oid AND d.deptype = 'i' AND c.contype = 'f'))
                AND p.oid=t.tgfoid
                AND p.pronamespace = ns.oid";

        return $this->connection->selectSet($sql);
    }

    /**
     * Helper for pre-7.4 trigger definitions (kept for completeness).
     */
    public function getTriggerDef($trigger)
    {
        $this->connection->fieldArrayClean($trigger);

        if (!defined('TRIGGER_TYPE_ROW'))
            define('TRIGGER_TYPE_ROW', (1 << 0));
        if (!defined('TRIGGER_TYPE_BEFORE'))
            define('TRIGGER_TYPE_BEFORE', (1 << 1));
        if (!defined('TRIGGER_TYPE_INSERT'))
            define('TRIGGER_TYPE_INSERT', (1 << 2));
        if (!defined('TRIGGER_TYPE_DELETE'))
            define('TRIGGER_TYPE_DELETE', (1 << 3));
        if (!defined('TRIGGER_TYPE_UPDATE'))
            define('TRIGGER_TYPE_UPDATE', (1 << 4));

        $trigger['tgisconstraint'] = $this->connection->phpBool($trigger['tgisconstraint']);
        $trigger['tgdeferrable'] = $this->connection->phpBool($trigger['tgdeferrable']);
        $trigger['tginitdeferred'] = $this->connection->phpBool($trigger['tginitdeferred']);

        $tgdef = $trigger['tgisconstraint'] ? 'CREATE CONSTRAINT TRIGGER ' : 'CREATE TRIGGER ';
        $tgdef .= "\"{$trigger['tgname']}\" ";

        if (($trigger['tgtype'] & TRIGGER_TYPE_BEFORE) == TRIGGER_TYPE_BEFORE) {
            $tgdef .= 'BEFORE';
        } else {
            $tgdef .= 'AFTER';
        }

        $findx = 0;
        if (($trigger['tgtype'] & TRIGGER_TYPE_INSERT) == TRIGGER_TYPE_INSERT) {
            $tgdef .= ' INSERT';
            $findx++;
        }
        if (($trigger['tgtype'] & TRIGGER_TYPE_DELETE) == TRIGGER_TYPE_DELETE) {
            if ($findx > 0) {
                $tgdef .= ' OR DELETE';
            } else {
                $tgdef .= ' DELETE';
                $findx++;
            }
        }
        if (($trigger['tgtype'] & TRIGGER_TYPE_UPDATE) == TRIGGER_TYPE_UPDATE) {
            if ($findx > 0) {
                $tgdef .= ' OR UPDATE';
            } else {
                $tgdef .= ' UPDATE';
            }
        }

        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $tgdef .= " ON \"{$f_schema}\".\"{$trigger['relname']}\" ";

        if ($trigger['tgisconstraint']) {
            if ($trigger['tgconstrrelid'] != 0) {
                $tgdef .= " FROM \"{$trigger['tgconstrrelname']}\" ";
            }
            if (!$trigger['tgdeferrable']) {
                $tgdef .= 'NOT ';
            }
            $tgdef .= 'DEFERRABLE INITIALLY ';
            if ($trigger['tginitdeferred']) {
                $tgdef .= 'DEFERRED ';
            } else {
                $tgdef .= 'IMMEDIATE ';
            }
        }

        if (($trigger['tgtype'] & TRIGGER_TYPE_ROW) == TRIGGER_TYPE_ROW) {
            $tgdef .= 'FOR EACH ROW ';
        } else {
            $tgdef .= 'FOR EACH STATEMENT ';
        }

        $tgdef .= "EXECUTE PROCEDURE \"{$trigger['tgfname']}\"(";

        $v = addcslashes($trigger['tgargs'], "\0");
        $params = explode('\\000', $v);
        for ($findx = 0; $findx < $trigger['tgnargs']; $findx++) {
            $param = "'" . str_replace("'", "\\'", $params[$findx]) . "'";
            $tgdef .= $param;
            if ($findx < ($trigger['tgnargs'] - 1)) {
                $tgdef .= ', ';
            }
        }

        $tgdef .= ')';

        return $tgdef;
    }

    /**
     * Returns trigger-capable functions.
     */
    public function getTriggerFunctions()
    {
        return $this->getFunctionAction()->getFunctions(true, 'trigger');
    }

    /**
     * Creates a trigger.
     */
    public function createTrigger($tgname, $table, $tgproc, $tgtime, $tgevent, $tgfrequency, $tgargs)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($tgname);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($tgproc);

        $sql = "CREATE TRIGGER \"{$tgname}\" {$tgtime}
                {$tgevent} ON \"{$f_schema}\".\"{$table}\"
                FOR EACH {$tgfrequency} EXECUTE PROCEDURE \"{$tgproc}\"({$tgargs})";

        return $this->connection->execute($sql);
    }

    /**
     * Renames a trigger.
     */
    public function alterTrigger($table, $trigger, $name)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);
        $this->connection->fieldClean($trigger);
        $this->connection->fieldClean($name);

        $sql = "ALTER TRIGGER \"{$trigger}\" ON \"{$f_schema}\".\"{$table}\" RENAME TO \"{$name}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Drops a trigger.
     */
    public function dropTrigger($tgname, $table, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($tgname);
        $this->connection->fieldClean($table);

        $sql = "DROP TRIGGER \"{$tgname}\" ON \"{$f_schema}\".\"{$table}\"";
        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }

    /**
     * Enables a trigger.
     */
    public function enableTrigger($tgname, $table)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($tgname);
        $this->connection->fieldClean($table);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" ENABLE TRIGGER \"{$tgname}\"";

        return $this->connection->execute($sql);
    }

    /**
     * Disables a trigger.
     */
    public function disableTrigger($tgname, $table)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($tgname);
        $this->connection->fieldClean($table);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" DISABLE TRIGGER \"{$tgname}\"";

        return $this->connection->execute($sql);
    }
}
