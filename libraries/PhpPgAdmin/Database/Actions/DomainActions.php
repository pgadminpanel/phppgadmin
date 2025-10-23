<?php

namespace PhpPgAdmin\Database\Actions;



class DomainActions extends ActionsBase
{

    /**
     * Gets all information for a single domain.
     */
    public function getDomain($domain)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($domain);

        $sql =
            "SELECT
                t.oid,
                t.typname AS domname,
                pg_catalog.format_type(t.typbasetype, t.typtypmod) AS domtype,
                t.typnotnull AS domnotnull,
                t.typdefault AS domdef,
                pg_catalog.pg_get_userbyid(t.typowner) AS domowner,
                pg_catalog.obj_description(t.oid, 'pg_type') AS domcomment
            FROM
                pg_catalog.pg_type t
            WHERE
                t.typtype = 'd'
                AND t.typname = '{$domain}'
                AND t.typnamespace = (SELECT oid FROM pg_catalog.pg_namespace
                    WHERE nspname = '{$c_schema}')";

        return $this->connection->selectSet($sql);
    }

    /**
     * Return all domains in current schema.
     */
    public function getDomains()
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);

        $sql = "
            SELECT
                t.typname AS domname,
                pg_catalog.format_type(t.typbasetype, t.typtypmod) AS domtype,
                t.typnotnull AS domnotnull,
                t.typdefault AS domdef,
                pg_catalog.pg_get_userbyid(t.typowner) AS domowner,
                pg_catalog.obj_description(t.oid, 'pg_type') AS domcomment
            FROM
                pg_catalog.pg_type t
            WHERE
                t.typtype = 'd'
                AND t.typnamespace = (SELECT oid FROM pg_catalog.pg_namespace
                    WHERE nspname='{$c_schema}')
            ORDER BY t.typname";

        return $this->connection->selectSet($sql);
    }

    /**
     * Get domain constraints.
     */
    public function getDomainConstraints($domain)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($domain);

        $sql = "
            SELECT
                conname,
                contype,
                pg_catalog.pg_get_constraintdef(oid, true) AS consrc
            FROM
                pg_catalog.pg_constraint
            WHERE
                contypid = (
                    SELECT oid FROM pg_catalog.pg_type
                    WHERE typname='{$domain}'
                        AND typnamespace = (
                            SELECT oid FROM pg_catalog.pg_namespace
                            WHERE nspname = '{$c_schema}')
                )
            ORDER BY conname";

        return $this->connection->selectSet($sql);
    }

    /**
     * Creates a domain.
     */
    public function createDomain($domain, $type, $length, $array, $notnull, $default, $check, $comment = '')
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($domain);

        $sql = "CREATE DOMAIN \"{$f_schema}\".\"{$domain}\" AS ";

        if ($length == '') {
            $sql .= $type;
        } else {
            switch ($type) {
                case 'timestamp with time zone':
                case 'timestamp without time zone':
                    $qual = substr($type, 9);
                    $sql .= "timestamp({$length}){$qual}";
                    break;
                case 'time with time zone':
                case 'time without time zone':
                    $qual = substr($type, 4);
                    $sql .= "time({$length}){$qual}";
                    break;
                default:
                    $sql .= "{$type}({$length})";
            }
        }

        if ($array) {
            $sql .= '[]';
        }

        if ($notnull) {
            $sql .= ' NOT NULL';
        }
        if ($default != '') {
            $sql .= " DEFAULT {$default}";
        }
        if ($this->connection->hasDomainConstraints() && $check != '') {
            $sql .= " CHECK ({$check})";
        }

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            return -1;
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($comment != '') {
            $status = $this->connection->setComment('DOMAIN', $domain, '', $comment, true);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -1;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Alters a domain.
     */
    public function alterDomain($domain, $domdefault, $domnotnull, $domowner, $comment = '')
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($domain);
        $this->connection->fieldClean($domowner);

        $status = $this->connection->beginTransaction();
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -1;
        }

        if ($domdefault == '') {
            $sql = "ALTER DOMAIN \"{$f_schema}\".\"{$domain}\" DROP DEFAULT";
        } else {
            $sql = "ALTER DOMAIN \"{$f_schema}\".\"{$domain}\" SET DEFAULT {$domdefault}";
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -2;
        }

        if ($domnotnull) {
            $sql = "ALTER DOMAIN \"{$f_schema}\".\"{$domain}\" SET NOT NULL";
        } else {
            $sql = "ALTER DOMAIN \"{$f_schema}\".\"{$domain}\" DROP NOT NULL";
        }

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -3;
        }

        $sql = "ALTER DOMAIN \"{$f_schema}\".\"{$domain}\" OWNER TO \"{$domowner}\"";

        $status = $this->connection->execute($sql);
        if ($status != 0) {
            $this->connection->rollbackTransaction();
            return -4;
        }

        if ($comment !== null) {
            $status = $this->connection->setComment('DOMAIN', $domain, '', $comment);
            if ($status != 0) {
                $this->connection->rollbackTransaction();
                return -5;
            }
        }

        return $this->connection->endTransaction();
    }

    /**
     * Drops a domain.
     */
    public function dropDomain($domain, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($domain);

        $sql = "DROP DOMAIN \"{$f_schema}\".\"{$domain}\"";
        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Adds a check constraint to a domain.
     */
    public function addDomainCheckConstraint($domain, $definition, $name = '')
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($domain);
        $this->connection->fieldClean($name);

        $sql = "ALTER DOMAIN \"{$f_schema}\".\"{$domain}\" ADD ";
        if ($name != '') {
            $sql .= "CONSTRAINT \"{$name}\" ";
        }
        $sql .= "CHECK ({$definition})";

        return $this->connection->execute($sql);
    }

    /**
     * Drops a domain constraint.
     */
    public function dropDomainConstraint($domain, $constraint, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($domain);
        $this->connection->fieldClean($constraint);

        $sql = "ALTER DOMAIN \"{$f_schema}\".\"{$domain}\" DROP CONSTRAINT \"{$constraint}\"";
        if ($cascade) {
            $sql .= " CASCADE";
        }

        return $this->connection->execute($sql);
    }
}
