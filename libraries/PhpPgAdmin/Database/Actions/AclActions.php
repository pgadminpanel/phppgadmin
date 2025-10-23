<?php

namespace PhpPgAdmin\Database\Actions;



class AclActions extends ActionsBase
{
    // Base constructor inherited from Actions

    /** @var array map of ACL chars to privilege names */
    private const PRIV_MAP = [
        'r' => 'SELECT',
        'w' => 'UPDATE',
        'a' => 'INSERT',
        'd' => 'DELETE',
        'D' => 'TRUNCATE',
        'R' => 'RULE',
        'x' => 'REFERENCES',
        't' => 'TRIGGER',
        'X' => 'EXECUTE',
        'U' => 'USAGE',
        'C' => 'CREATE',
        'T' => 'TEMPORARY',
        'c' => 'CONNECT',
    ];

    // List of all legal privileges that can be applied to different types
    // of objects.
    public const PRIV_LIST = [
        'table' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REFERENCES', 'TRIGGER', 'ALL PRIVILEGES'],
        'view' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REFERENCES', 'TRIGGER', 'ALL PRIVILEGES'],
        'sequence' => ['USAGE', 'SELECT', 'UPDATE', 'ALL PRIVILEGES'],
        'database' => ['CREATE', 'TEMPORARY', 'CONNECT', 'ALL PRIVILEGES'],
        'function' => ['EXECUTE', 'ALL PRIVILEGES'],
        'language' => ['USAGE', 'ALL PRIVILEGES'],
        'schema' => ['CREATE', 'USAGE', 'ALL PRIVILEGES'],
        'tablespace' => ['CREATE', 'ALL PRIVILEGES'],
        'column' => ['SELECT', 'INSERT', 'UPDATE', 'REFERENCES', 'ALL PRIVILEGES']
    ];

    /*
    public const ACL_TYPE = 0;
    public const ACL_ENTITY = 1;
    public const ACL_PRIVS = 2;
    public const ACL_GRANTOR = 3;
    public const ACL_GRANTOPT = 4;
    */

    /**
     * Internal function used for parsing ACLs (aclitem[] -> structured array).
     * Mirrors legacy Postgres::_parseACL.
     *
     * @param string $acl
     * @return array|int privileges array or -3 on unknown privilege
     */
    /*
    public function parseAcl($acl)
    {
        $acl = substr($acl, 1, strlen($acl) - 2);

        $aces = [];
        $i = $j = 0;
        $in_quotes = false;
        while ($i < strlen($acl)) {
            $char = substr($acl, $i, 1);
            if ($char === '"' && ($i == 0 || substr($acl, $i - 1, 1) != '\\')) {
                $in_quotes = !$in_quotes;
            } elseif ($char === ',' && !$in_quotes) {
                $aces[] = substr($acl, $j, $i - $j);
                $j = $i + 1;
            }
            $i++;
        }
        $aces[] = substr($acl, $j);

        $result = [];

        foreach ($aces as $v) {
            if (strpos($v, '"') === 0) {
                $v = substr($v, 1, strlen($v) - 2);
                $v = str_replace('\\"', '"', $v);
                $v = str_replace('\\\\', '\\', $v);
            }

            if (strpos($v, '=') === 0) {
                $atype = 'public';
            } elseif ($this->connection->hasRoles()) {
                $atype = 'role';
            } elseif (strpos($v, 'group ') === 0) {
                $atype = 'group';
                $v = substr($v, 6);
            } else {
                $atype = 'user';
            }

            $i = 0;
            $in_quotes = false;
            $entity = null;
            $chars = null;
            while ($i < strlen($v)) {
                $char = substr($v, $i, 1);
                $next_char = substr($v, $i + 1, 1);
                if ($char === '"' && ($i == 0 || $next_char != '"')) {
                    $in_quotes = !$in_quotes;
                } elseif ($char === '"' && $next_char === '"') {
                    $i++;
                } elseif ($char === '=' && !$in_quotes) {
                    $entity = substr($v, 0, $i);
                    $chars = substr($v, $i + 1);
                    break;
                }
                $i++;
            }

            if (substr_compare($entity, '"', 0, 1) === 0) {
                $entity = substr($entity, 1, strlen($entity) - 2);
                $entity = str_replace('""', '"', $entity);
            }

            $row = [
                self::ACL_TYPE => $atype,
                self::ACL_ENTITY => $entity,
                self::ACL_PRIVS => [],
                self::ACL_GRANTOR => '',
                self::ACL_GRANTOPT => [],
            ];

            for ($i = 0; $i < strlen($chars); $i++) {
                $char = substr($chars, $i, 1);
                if ($char === '*') {
                    $row[self::ACL_GRANTOPT][] = self::PRIV_MAP[substr($chars, $i - 1, 1)] ?? null;
                } elseif ($char === '/') {
                    $grantor = substr($chars, $i + 1);
                    if (strpos($grantor, '"') === 0) {
                        $grantor = substr($grantor, 1, strlen($grantor) - 2);
                        $grantor = str_replace('""', '"', $grantor);
                    }
                    $row[self::ACL_GRANTOR] = $grantor;
                    break;
                } else {
                    if (!isset(self::PRIV_MAP[$char])) {
                        return -3;
                    }
                    $row[self::ACL_PRIVS][] = self::PRIV_MAP[$char];
                }
            }

            $result[] = $row;
        }

        return $result;
    }
    */

    /**
     * Internal function used for parsing ACLs (aclitem[] -> structured array).
     * Mirrors legacy Postgres::_parseACL.
     *
     * @param string $acl
     * @return array|int privileges array or -3 on unknown privilege
     */
    public function parseAcl($acl)
    {
        // Strip surrounding braces: { ... }
        $acl = trim($acl, '{}');

        $entries = [];
        $current = '';
        $inQuotes = false;
        $len = strlen($acl);

        // --- Split ACL list manually ---
        for ($i = 0; $i < $len; $i++) {
            $ch = $acl[$i];

            if ($ch === '"' && ($i === 0 || $acl[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
                $current .= $ch;
                continue;
            }

            if ($ch === ',' && !$inQuotes) {
                $entries[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }
        if ($current !== '') {
            $entries[] = $current;
        }

        $result = [];

        foreach ($entries as $entry) {

            // --- Unescape quoted ACE ---
            if (isset($entry[0]) && $entry[0] === '"') {
                $entry = substr($entry, 1, -1);
                $entry = str_replace(['\\"', '\\\\'], ['"', '\\'], $entry);
            }

            // --- Determine ACL type ---
            if (strpos($entry, '=') === 0) {
                $type = 'public';
            } else {
                $type = 'role';
            }

            // --- Split entity and privilege chars manually ---
            $entity = '';
            $chars = '';
            $inQuotes = false;

            for ($i = 0, $len2 = strlen($entry); $i < $len2; $i++) {
                $ch = $entry[$i];
                $next = $entry[$i + 1] ?? null;

                if ($ch === '"' && $next !== '"') {
                    $inQuotes = !$inQuotes;
                    continue;
                }

                if ($ch === '"' && $next === '"') {
                    $i++;
                    $entity .= '"';
                    continue;
                }

                if ($ch === '=' && !$inQuotes) {
                    $chars = substr($entry, $i + 1);
                    break;
                }

                $entity .= $ch;
            }

            // Unescape entity
            if (substr_compare($entity, '"', 0, 1) === 0) {
                $entity = substr($entity, 1, -1);
                $entity = str_replace('""', '"', $entity);
            }

            // --- Build result row ---
            $row = [
                'type' => $type,
                'entity' => $entity,
                'privileges' => [],
                'grantor' => '',
                'grantable' => [],
            ];

            // --- Parse privilege chars ---
            $len3 = strlen($chars);
            for ($i = 0; $i < $len3; $i++) {
                $ch = $chars[$i];

                // WITH GRANT OPTION
                if ($ch === '*') {
                    $prev = $chars[$i - 1] ?? null;
                    if ($prev && isset(self::PRIV_MAP[$prev])) {
                        $row['grantable'][] = self::PRIV_MAP[$prev];
                    }
                    continue;
                }

                // WITH GRANT OPTION FOR ALL PRIVILEGES
                if ($ch === 'm') {
                    foreach ($row['privileges'] as $priv) {
                        $row['grantable'][] = $priv;
                    }
                    continue;
                }

                // GRANTOR
                if ($ch === '/') {
                    $grantor = substr($chars, $i + 1);
                    if (isset($grantor[0]) && $grantor[0] === '"') {
                        $grantor = substr($grantor, 1, -1);
                        $grantor = str_replace('""', '"', $grantor);
                    }
                    $row['grantor'] = $grantor;
                    break;
                }

                // Normal privilege
                if (!isset(self::PRIV_MAP[$ch])) {
                    return -3;
                }

                $row['privileges'][] = self::PRIV_MAP[$ch];
            }

            $result[] = $row;
        }

        return $result;
    }


    /**
     * Grabs an array of users and their privileges for an object, given its type.
     * Returns -1 invalid type, -2 object not found, -3 unknown privilege.
     */
    public function getPrivileges($object, $type, $table = null)
    {
        $c_schema = $this->connection->_schema ?? null;
        $this->connection->clean($c_schema);
        $this->connection->clean($object);

        switch ($type) {
            case 'column':
                $this->connection->clean($table);
                $sql =
                    "SELECT E'{' || pg_catalog.array_to_string(attacl, E',') || E'}' as acl
                    FROM pg_catalog.pg_attribute a
                        LEFT JOIN pg_catalog.pg_class c ON (a.attrelid = c.oid)
                        LEFT JOIN pg_catalog.pg_namespace n ON (c.relnamespace=n.oid)
                    WHERE n.nspname='{$c_schema}'
                        AND c.relname='{$table}'
                        AND a.attname='{$object}'";
                break;
            case 'table':
            case 'view':
            case 'sequence':
                $sql =
                    "SELECT relacl AS acl FROM pg_catalog.pg_class
                    WHERE relname='{$object}'
                        AND relnamespace=(SELECT oid FROM pg_catalog.pg_namespace
                            WHERE nspname='{$c_schema}')";
                break;
            case 'database':
                $sql = "SELECT datacl AS acl FROM pg_catalog.pg_database WHERE datname='{$object}'";
                break;
            case 'function':
                $sql = "SELECT proacl AS acl FROM pg_catalog.pg_proc WHERE oid='{$object}'";
                break;
            case 'language':
                $sql = "SELECT lanacl AS acl FROM pg_catalog.pg_language WHERE lanname='{$object}'";
                break;
            case 'schema':
                $sql = "SELECT nspacl AS acl FROM pg_catalog.pg_namespace WHERE nspname='{$object}'";
                break;
            case 'tablespace':
                $sql = "SELECT spcacl AS acl FROM pg_catalog.pg_tablespace WHERE spcname='{$object}'";
                break;
            default:
                return -1;
        }

        $acl = $this->connection->selectField($sql, 'acl');
        if ($acl == -1) {
            return -2;
        } elseif ($acl === '' || $acl === null) {
            return [];
        }

        //var_dump($acl); // Debug line, can be removed later
        return $this->parseAcl($acl);
    }

    /**
     * Grants or revokes privileges.
     * Returns -1 invalid type, -2 invalid entity, -3 invalid privileges,
     * -4 not granting to anything, -5 invalid mode.
     */
    public function setPrivileges($mode, $type, $object, $public, $usernames, $groupnames, $privileges, $grantoption, $cascade, $table)
    {
        $f_schema = $this->connection->_schema ?? '';
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldArrayClean($usernames);
        $this->connection->fieldArrayClean($groupnames);

        if (!is_array($privileges) || sizeof($privileges) == 0) {
            return -3;
        }
        if (!is_array($usernames) || !is_array($groupnames) || (!$public && sizeof($usernames) == 0 && sizeof($groupnames) == 0)) {
            return -4;
        }
        if ($mode != 'GRANT' && $mode != 'REVOKE') {
            return -5;
        }

        $sql = $mode;

        if ($this->connection->hasGrantOption() && $mode == 'REVOKE' && $grantoption) {
            $sql .= ' GRANT OPTION FOR';
        }

        if (in_array('ALL PRIVILEGES', $privileges)) {
            $sql .= ' ALL PRIVILEGES';
        } else {
            if ($type == 'column') {
                $this->connection->fieldClean($object);
                $sql .= ' ' . join(" (\"{$object}\"), ", $privileges);
            } else {
                $sql .= ' ' . join(', ', $privileges);
            }
        }

        switch ($type) {
            case 'column':
                $sql .= " (\"{$object}\")";
                $object = $table;
            // fallthrough
            case 'table':
            case 'view':
            case 'sequence':
                $this->connection->fieldClean($object);
                $sql .= " ON \"{$f_schema}\".\"{$object}\"";
                break;
            case 'database':
                $this->connection->fieldClean($object);
                $sql .= " ON DATABASE \"{$object}\"";
                break;
            case 'function':
                $fn = $this->fetchFunctionSignature($object);
                if (!$fn) {
                    return -2;
                }
                $this->connection->fieldClean($fn['proname']);
                $sql .= " ON FUNCTION \"{$f_schema}\".\"{$fn['proname']}\"({$fn['proarguments']})";
                break;
            case 'language':
                $this->connection->fieldClean($object);
                $sql .= " ON LANGUAGE \"{$object}\"";
                break;
            case 'schema':
                $this->connection->fieldClean($object);
                $sql .= " ON SCHEMA \"{$object}\"";
                break;
            case 'tablespace':
                $this->connection->fieldClean($object);
                $sql .= " ON TABLESPACE \"{$object}\"";
                break;
            default:
                return -1;
        }

        $first = true;
        $sql .= ($mode == 'GRANT') ? ' TO ' : ' FROM ';
        if ($public) {
            $sql .= 'PUBLIC';
            $first = false;
        }
        foreach ($usernames as $v) {
            if ($first) {
                $sql .= "\"{$v}\"";
                $first = false;
            } else {
                $sql .= ", \"{$v}\"";
            }
        }
        foreach ($groupnames as $v) {
            if ($first) {
                $sql .= "GROUP \"{$v}\"";
                $first = false;
            } else {
                $sql .= ", GROUP \"{$v}\"";
            }
        }

        if ($this->connection->hasGrantOption() && $mode == 'GRANT' && $grantoption) {
            $sql .= ' WITH GRANT OPTION';
        }
        if ($this->connection->hasGrantOption() && $mode == 'REVOKE' && $cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }

    private function fetchFunctionSignature($oid)
    {
        $this->connection->clean($oid);
        $sql = "SELECT proname, pg_catalog.pg_get_function_arguments(oid) AS proarguments FROM pg_catalog.pg_proc WHERE oid='{$oid}'";
        $rs = $this->connection->selectSet($sql);
        if (is_object($rs) && $rs->RecordCount() > 0) {
            return $rs->fields;
        }

        return null;
    }
}
