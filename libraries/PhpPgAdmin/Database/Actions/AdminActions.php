<?php

namespace PhpPgAdmin\Database\Actions;

use PhpPgAdmin\Database\ArrayRecordSet;


class AdminActions extends ActionsBase
{
    /**
     * Analyze a database
     * @param string $table (optional) The table to analyze
     */
    function analyzeDB($table = '')
    {
        if ($table != '') {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $this->connection->fieldClean($table);

            $sql = "ANALYZE \"{$f_schema}\".\"{$table}\"";
        } else
            $sql = "ANALYZE";

        return $this->connection->execute($sql);
    }

    /**
     * Vacuums a database
     * @param string $table The table to vacuum
     * @param bool $analyze If true, also does analyze
     * @param bool $full If true, selects "full" vacuum
     * @param bool $freeze If true, selects aggressive "freezing" of tuples
     */
    function vacuumDB($table = '', $analyze = false, $full = false, $freeze = false)
    {

        $sql = "VACUUM";
        if ($full)
            $sql .= " FULL";
        if ($freeze)
            $sql .= " FREEZE";
        if ($analyze)
            $sql .= " ANALYZE";
        if ($table != '') {
            $f_schema = $this->connection->_schema;
            $this->connection->fieldClean($f_schema);
            $this->connection->fieldClean($table);
            $sql .= " \"{$f_schema}\".\"{$table}\"";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Returns all autovacuum global configuration
     * @return array associative array( param => value, ...)
     */
    function getAutovacuum()
    {

        $_defaults = $this->connection->selectSet("SELECT name, setting
			FROM pg_catalog.pg_settings
			WHERE 
				name = 'autovacuum' 
				OR name = 'autovacuum_vacuum_threshold'
				OR name = 'autovacuum_vacuum_scale_factor'
				OR name = 'autovacuum_analyze_threshold'
				OR name = 'autovacuum_analyze_scale_factor'
				OR name = 'autovacuum_vacuum_cost_delay'
				OR name = 'autovacuum_vacuum_cost_limit'
				OR name = 'vacuum_freeze_min_age'
				OR name = 'autovacuum_freeze_max_age'
			"
        );

        $ret = [];
        while (!$_defaults->EOF) {
            $ret[$_defaults->fields['name']] = $_defaults->fields['setting'];
            $_defaults->moveNext();
        }

        return $ret;
    }

    /**
     * Returns all available autovacuum per table information.
     * @return int Status code
     */
    function saveAutovacuum(
        $table,
        $vacenabled,
        $vacthreshold,
        $vacscalefactor,
        $anathresold,
        $anascalefactor,
        $vaccostdelay,
        $vaccostlimit
    ) {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$table}\" SET (";

        if (!empty($vacenabled)) {
            $this->connection->clean($vacenabled);
            $params[] = "autovacuum_enabled='{$vacenabled}'";
        }
        if (!empty($vacthreshold)) {
            $this->connection->clean($vacthreshold);
            $params[] = "autovacuum_vacuum_threshold='{$vacthreshold}'";
        }
        if (!empty($vacscalefactor)) {
            $this->connection->clean($vacscalefactor);
            $params[] = "autovacuum_vacuum_scale_factor='{$vacscalefactor}'";
        }
        if (!empty($anathresold)) {
            $this->connection->clean($anathresold);
            $params[] = "autovacuum_analyze_threshold='{$anathresold}'";
        }
        if (!empty($anascalefactor)) {
            $this->connection->clean($anascalefactor);
            $params[] = "autovacuum_analyze_scale_factor='{$anascalefactor}'";
        }
        if (!empty($vaccostdelay)) {
            $this->connection->clean($vaccostdelay);
            $params[] = "autovacuum_vacuum_cost_delay='{$vaccostdelay}'";
        }
        if (!empty($vaccostlimit)) {
            $this->connection->clean($vaccostlimit);
            $params[] = "autovacuum_vacuum_cost_limit='{$vaccostlimit}'";
        }

        $sql = $sql . implode(',', $params) . ');';

        return $this->connection->execute($sql);
    }

    /**
     * Drops all autovacuum settings for a table (resets to global settings)
     * @param string $table The table to drop autovacuum settings for
     * @return int Status code
     */
    function dropAutovacuum($table)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($table);

        return $this->connection->execute(
            "
			ALTER TABLE \"{$f_schema}\".\"{$table}\" RESET (autovacuum_enabled, autovacuum_vacuum_threshold,
				autovacuum_vacuum_scale_factor, autovacuum_analyze_threshold, autovacuum_analyze_scale_factor,
				autovacuum_vacuum_cost_delay, autovacuum_vacuum_cost_limit
			);"
        );
    }

    /**
     * Returns all available process information.
     * @param string $database (optional) Find only connections to specified database
     * @return \ADORecordSet A recordset
     */
    function getProcesses($database = null)
    {
        // Different query for PostgreSQL versions < 9.5
        if ($this->connection->major_version < 9.5) {
            // PostgreSQL 9.1-9.4 format with procpid and current_query
            if ($database === null)
                $sql = "SELECT datname, usename, procpid AS pid, waiting, current_query AS query, query_start
					FROM pg_catalog.pg_stat_activity
					ORDER BY datname, usename, procpid";
            else {
                $this->connection->clean($database);
                $sql = "SELECT datname, usename, procpid AS pid, waiting, current_query AS query, query_start
					FROM pg_catalog.pg_stat_activity
					WHERE datname='{$database}'
					ORDER BY usename, procpid";
            }
        } else {
            // PostgreSQL 9.5+ format with wait_event and state
            if ($database === null)
                $sql = "SELECT datname, usename, pid, 
                    case when wait_event is null then 'false' else wait_event_type || '::' || wait_event end as waiting, 
                    query_start, application_name, client_addr, 
                  case when state='idle in transaction' then '<IDLE> in transaction' when state = 'idle' then '<IDLE>' else query end as query 
				FROM pg_catalog.pg_stat_activity
				ORDER BY datname, usename, pid";
            else {
                $this->connection->clean($database);
                $sql = "SELECT datname, usename, pid, 
                    case when wait_event is null then 'false' else wait_event_type || '::' || wait_event end as waiting, 
                    query_start, application_name, client_addr, 
                  case when state='idle in transaction' then '<IDLE> in transaction' when state = 'idle' then '<IDLE>' else query end as query 
				FROM pg_catalog.pg_stat_activity
				WHERE datname='{$database}'
				ORDER BY usename, pid";
            }
        }

        return $this->connection->selectSet($sql);
    }

    /**
     * Returns table locks information in the current database
     * @return \ADORecordSet A recordset
     */
    function getLocks()
    {
        $conf = $this->conf();

        if (!$conf['show_system'])
            $where = 'AND pn.nspname NOT LIKE $$pg\_%$$';
        else
            $where = "AND nspname !~ '^pg_t(emp_[0-9]+|oast)$'";

        $sql = "
			SELECT
				pn.nspname, pc.relname AS tablename, pl.pid, pl.mode, pl.granted, pl.virtualtransaction,
				(select transactionid from pg_catalog.pg_locks l2 where l2.locktype='transactionid'
					and l2.mode='ExclusiveLock' and l2.virtualtransaction=pl.virtualtransaction) as transaction
			FROM
				pg_catalog.pg_locks pl,
				pg_catalog.pg_class pc,
				pg_catalog.pg_namespace pn
			WHERE
				pl.relation = pc.oid AND pc.relnamespace=pn.oid
			{$where}
			ORDER BY pid,nspname,tablename";

        return $this->connection->selectSet($sql);
    }

    /**
     * Sends a cancel or kill command to a process
     * @param int|string $pid The ID of the backend process
     * @param string $signal 'CANCEL' or 'KILL'
     * @return int 0 success, -1 invalid signal type
     */
    function sendSignal($pid, $signal)
    {
        // Clean
        $pid = (int) $pid;

        if ($signal == 'CANCEL')
            $sql = "SELECT pg_catalog.pg_cancel_backend({$pid}) AS val";
        elseif ($signal == 'KILL')
            $sql = "SELECT pg_catalog.pg_terminate_backend({$pid}) AS val";
        else
            return -1;


        // Execute the query
        $val = $this->connection->selectField($sql, 'val');

        if ($val === 'f')
            return -1;
        elseif ($val === 't')
            return 0;
        else
            return -1;
    }

    /**
     * Returns all available autovacuum per table information.
     * @param $table if given, return autovacuum info for the given table or return all information for all tables
     *
     * @return ArrayRecordSet A recordset
     */
    function getTableAutovacuum($table = '')
    {

        $sql = '';

        if ($table !== '') {
            $this->connection->clean($table);
            $c_schema = $this->connection->_schema;
            $this->connection->clean($c_schema);

            $sql = "SELECT c.oid, nspname, relname, pg_catalog.array_to_string(reloptions, E',') AS reloptions
				FROM pg_class c
					LEFT JOIN pg_namespace n ON n.oid = c.relnamespace
				WHERE c.relkind = 'r'::\"char\"
					AND n.nspname NOT IN ('pg_catalog','information_schema')
					AND c.reloptions IS NOT NULL
					AND c.relname = '{$table}' AND n.nspname = '{$c_schema}'
				ORDER BY nspname, relname";
        } else {
            $sql = "SELECT c.oid, nspname, relname, pg_catalog.array_to_string(reloptions, E',') AS reloptions
				FROM pg_class c
					LEFT JOIN pg_namespace n ON n.oid = c.relnamespace
				WHERE c.relkind = 'r'::\"char\"
					AND n.nspname NOT IN ('pg_catalog','information_schema')
					AND c.reloptions IS NOT NULL
				ORDER BY nspname, relname";

        }

        /* tmp var to parse the results */
        $_autovacs = $this->connection->selectSet($sql);

        /* result array to return as RS */
        $autovacs = array();
        while (!$_autovacs->EOF) {
            $_ = array(
                'nspname' => $_autovacs->fields['nspname'],
                'relname' => $_autovacs->fields['relname']
            );

            $relOptions = explode(',', $_autovacs->fields['reloptions']);
            foreach ($relOptions as $var) {
                list($o, $v) = explode('=', $var);
                $_[$o] = $v;
            }

            $autovacs[] = $_;

            $_autovacs->moveNext();
        }

        return new ArrayRecordSet($autovacs);
    }
}