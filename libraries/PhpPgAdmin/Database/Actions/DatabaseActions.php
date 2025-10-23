<?php

namespace PhpPgAdmin\Database\Actions;



class DatabaseActions extends ActionsBase
{

    /**
     * Return all information about a particular database.
     * Mirrors legacy Postgres::getDatabase
     */
    public function getDatabase($database)
    {
        $this->connection->clean($database);
        $sql =
            "SELECT d.*, pg_catalog.pg_get_userbyid(d.datdba) AS owner
            FROM pg_database d
            WHERE datname='{$database}'";
        return $this->connection->selectSet($sql);
    }

    /**
     * Return all databases available on the server, honoring owned_only/show_system.
     * @param ?string $currentdatabase database name that should be on top of the resultset
     * @param bool $accessible_only if true, only return databases the current user can CONNECT to
     */
    public function getDatabases($currentdatabase = null, $accessible_only = true)
    {
        $conf = $this->conf();
        $misc = $this->misc();
        $roleActions = new RoleActions($this->connection);

        $server_info = $misc->getServerInfo();

        if (isset($conf['owned_only']) && $conf['owned_only'] && !$roleActions->isSuperUser()) {
            $username = $server_info['username'];
            $this->connection->clean($username);
            $clause = " AND pg_has_role('{$username}'::name,pr.rolname,'USAGE')";
        } else {
            $clause = '';
        }

        if ($currentdatabase !== null) {
            $this->connection->clean($currentdatabase);
            $orderby = "ORDER BY pdb.datname = '{$currentdatabase}' DESC, pdb.datname";
        } else {
            $orderby = "ORDER BY pdb.datname";
        }

        if (!$conf['show_system']) {
            $where = ' AND NOT pdb.datistemplate';
        } else {
            $where = ' AND pdb.datallowconn';
        }

        // Filter by CONNECT privilege if requested and user is not superuser
        if ($accessible_only && !$roleActions->isSuperUser()) {
            $accessible_clause = " AND pg_catalog.has_database_privilege(current_user, pdb.oid, 'CONNECT')";
        } else {
            $accessible_clause = '';
        }

        $sql =
            "SELECT pdb.datname AS datname, pr.rolname AS datowner, pg_encoding_to_char(encoding) AS datencoding,
                (SELECT description FROM pg_catalog.pg_shdescription pd WHERE pdb.oid=pd.objoid AND pd.classoid='pg_database'::regclass) AS datcomment,
                (SELECT spcname FROM pg_catalog.pg_tablespace pt WHERE pt.oid=pdb.dattablespace) AS tablespace,
                CASE WHEN pg_catalog.has_database_privilege(current_user, pdb.oid, 'CONNECT') 
                    THEN pg_catalog.pg_database_size(pdb.oid) 
                    ELSE -1 
                END as dbsize, pdb.datcollate, pdb.datctype
            FROM pg_catalog.pg_database pdb
                LEFT JOIN pg_catalog.pg_roles pr ON (pdb.datdba = pr.oid)
            WHERE true
                {$where}
                {$clause}
                {$accessible_clause}
            {$orderby}";

        return $this->connection->selectSet($sql);
    }

    /**
     * Return the database comment.
     */
    public function getDatabaseComment($database)
    {
        $this->connection->clean($database);
        $sql = "SELECT description FROM pg_catalog.pg_database JOIN pg_catalog.pg_shdescription ON (oid=objoid AND classoid='pg_database'::regclass) WHERE pg_database.datname = '{$database}' ";
        return $this->connection->selectSet($sql);
    }

    /**
     * Return the database owner.
     */
    public function getDatabaseOwner($database)
    {
        $this->connection->clean($database);
        $sql =
            "SELECT pg_catalog.pg_get_userbyid(datdba) AS owner
            FROM pg_catalog.pg_database
            WHERE datname = '{$database}'";
        return $this->connection->selectField($sql, 'owner');
    }

    /**
     * Returns the current database encoding.
     */
    public function getDatabaseEncoding()
    {
        return pg_parameter_status($this->connection->conn->_connectionID, 'server_encoding');
    }

    /**
     * Creates a database.
     */
    public function createDatabase($database, $encoding, $tablespace = '', $comment = '', $template = 'template1', $lc_collate = '', $lc_ctype = '')
    {
        $this->connection->fieldClean($database);
        $this->connection->clean($encoding);
        $this->connection->fieldClean($tablespace);
        $this->connection->fieldClean($template);
        $this->connection->clean($lc_collate);
        $this->connection->clean($lc_ctype);

        $sql = "CREATE DATABASE \"{$database}\" WITH TEMPLATE=\"{$template}\"";

        if ($encoding != '') {
            $sql .= " ENCODING='{$encoding}'";
        }
        if ($lc_collate != '') {
            $sql .= " LC_COLLATE='{$lc_collate}'";
        }
        if ($lc_ctype != '') {
            $sql .= " LC_CTYPE='{$lc_ctype}'";
        }

        if ($tablespace != '' && $this->connection->hasTablespaces()) {
            $sql .= " TABLESPACE \"{$tablespace}\"";
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            return -1;
        }

        if ($comment != '' && $this->connection->hasSharedComments()) {
            $status = $this->connection->setComment('DATABASE', $database, '', $comment);
            if ($status != 0) {
                return -2;
            }
        }

        return 0;
    }

    /**
     * Renames a database (cannot run on current DB).
     */
    public function alterDatabaseRename($oldName, $newName)
    {
        $this->connection->fieldClean($oldName);
        $this->connection->fieldClean($newName);

        if ($oldName != $newName) {
            $sql = "ALTER DATABASE \"{$oldName}\" RENAME TO \"{$newName}\"";
            return $this->connection->execute($sql);
        }

        return 0;
    }

    /**
     * Drops a database.
     */
    public function dropDatabase($database)
    {
        $this->connection->fieldClean($database);
        $sql = "DROP DATABASE \"{$database}\"";
        return $this->connection->execute($sql);
    }

    /**
     * Changes ownership of a database.
     */
    public function alterDatabaseOwner($dbName, $newOwner)
    {
        $this->connection->fieldClean($dbName);
        $this->connection->fieldClean($newOwner);

        $sql = "ALTER DATABASE \"{$dbName}\" OWNER TO \"{$newOwner}\"";
        return $this->connection->execute($sql);
    }

    /**
     * Alters a database (rename/owner/comment) in a transaction.
     */
    public function alterDatabase($dbName, $newName, $newOwner = '', $comment = '')
    {
        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($dbName != $newName) {
            $status = $this->alterDatabaseRename($dbName, $newName);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -3;
            }
            $dbName = $newName;
        }

        if ($newOwner != '') {
            $status = $this->alterDatabaseOwner($newName, $newOwner);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -2;
            }
        }

        $this->connection->fieldClean($dbName);
        $status = $this->connection->setComment('DATABASE', $dbName, '', $comment);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -4;
        }
        return $this->connection->endTransaction();
    }

    /**
     * Get database statistics including sessions, transactions, tuples,
     * and block I/O
     *
     * @return array Array with keys: sessions, transactions,
     * tuples_in, tuples_out, blocks, timestamp
     */
    public function getDatabaseStats()
    {
        $stats = [
            'timestamp' => microtime(true),
            'sessions' => ['total' => 0, 'active' => 0, 'idle' => 0, 'idle_in_xact' => 0],
            'transactions' => ['commits' => 0, 'rollbacks' => 0],
            'tuples_in' => ['inserts' => 0, 'updates' => 0, 'deletes' => 0],
            'tuples_out' => ['fetched' => 0, 'returned' => 0],
            'blocks' => ['reads' => 0, 'hits' => 0]
        ];

        // Get session counts from pg_stat_activity
        if ($this->connection->major_version >= 9.2) {
            // Postgres >= 9.2: use state column for accurate session states
            $sql = "SELECT state, count(*) AS count
                FROM pg_stat_activity
                WHERE datname = current_database()
                GROUP BY state";
            $result = $this->connection->selectSet($sql);

            if (\is_object($result)) {
                while (!$result->EOF) {
                    $state = $result->fields['state'] ?? null;
                    $count = (int) $result->fields['count'];
                    $stats['sessions']['total'] += $count;

                    if ($state === 'active') {
                        $stats['sessions']['active'] = $count;
                    } elseif ($state === 'idle') {
                        $stats['sessions']['idle'] = $count;
                    } elseif ($state === 'idle in transaction') {
                        $stats['sessions']['idle_in_xact'] = $count;
                    }
                    $result->moveNext();
                }
            }
        } else {
            // PostgreSQL 9.0 / 9.1: no state column, use current_query values
            $sql = "SELECT
                    sum(CASE WHEN current_query NOT IN ('<IDLE>', '<IDLE> in transaction') THEN 1 ELSE 0 END) AS active,
                    sum(CASE WHEN current_query = '<IDLE>' THEN 1 ELSE 0 END) AS idle,
                    sum(CASE WHEN current_query = '<IDLE> in transaction' THEN 1 ELSE 0 END) AS idle_in_xact,
                    count(*) AS total
                FROM pg_stat_activity
                WHERE datname = current_database()";
            $result = $this->connection->selectSet($sql);

            if (\is_object($result) && !$result->EOF) {
                $stats['sessions']['active'] = (int) $result->fields['active'];
                $stats['sessions']['idle'] = (int) $result->fields['idle'];
                $stats['sessions']['idle_in_xact'] = (int) $result->fields['idle_in_xact'];
                $stats['sessions']['total'] = (int) $result->fields['total'];
            }
        }

        // Get database statistics from pg_stat_database (works in 9.0+)
        $sql = "SELECT xact_commit, xact_rollback, tup_returned, tup_fetched,
                   tup_inserted, tup_updated, tup_deleted, blks_read, blks_hit
            FROM pg_stat_database
            WHERE datname = current_database()";
        $result = $this->connection->selectSet($sql);

        if (\is_object($result) && !$result->EOF) {
            $stats['transactions']['commits'] = (int) $result->fields['xact_commit'];
            $stats['transactions']['rollbacks'] = (int) $result->fields['xact_rollback'];
            $stats['tuples_in']['inserts'] = (int) $result->fields['tup_inserted'];
            $stats['tuples_in']['updates'] = (int) $result->fields['tup_updated'];
            $stats['tuples_in']['deletes'] = (int) $result->fields['tup_deleted'];
            $stats['tuples_out']['fetched'] = (int) $result->fields['tup_fetched'];
            $stats['tuples_out']['returned'] = (int) $result->fields['tup_returned'];
            $stats['blocks']['reads'] = (int) $result->fields['blks_read'];
            $stats['blocks']['hits'] = (int) $result->fields['blks_hit'];
        }

        return $stats;
    }


}
