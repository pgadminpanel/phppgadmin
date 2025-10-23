<?php

namespace PhpPgAdmin\Database\Actions;



class RuleActions extends ActionsBase
{
    // Rule action types
    public const RULE_EVENTS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /**
     * Returns a list of all rules on a table or view.
     */
    public function getRules($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "
            SELECT *
            FROM pg_catalog.pg_rules
            WHERE schemaname='{$c_schema}' AND tablename='{$table}'
            ORDER BY rulename
        ";

        return $this->connection->selectSet($sql);
    }

    /**
     * Edits a rule on a table or view.
     * @return int 0 success, -1 invalid event
     */
    public function setRule($name, $event, $table, $where, $instead, $type, $action)
    {
        return $this->createRule($name, $event, $table, $where, $instead, $type, $action, true);
    }

    /**
     * Creates a rule.
     * @return int 0 success, -1 invalid event
     */
    public function createRule($name, $event, $table, $where, $instead, $type, $action, $replace = false)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($name);
        $this->connection->fieldClean($table);

        if (!in_array($event, self::RULE_EVENTS)) {
            return -1;
        }

        $sql = 'CREATE';
        if ($replace) {
            $sql .= ' OR REPLACE';
        }

        $sql .= " RULE \"{$name}\" AS ON {$event} TO \"{$f_schema}\".\"{$table}\"";

        // WHERE clause cannot be safely escaped since it is raw SQL.
        if ($where != '') {
            $sql .= " WHERE {$where}";
        }

        $sql .= ' DO';
        if ($instead) {
            $sql .= ' INSTEAD';
        }

        if ($type === 'NOTHING') {
            $sql .= ' NOTHING';
        } else {
            $sql .= " ({$action})";
        }

        return $this->connection->execute($sql);
    }

    /**
     * Removes a rule from a table or view.
     */
    public function dropRule($rule, $relation, $cascade)
    {
        $f_schema = $this->connection->_schema;
        $this->connection->fieldClean($f_schema);
        $this->connection->fieldClean($rule);
        $this->connection->fieldClean($relation);

        $sql = "DROP RULE \"{$rule}\" ON \"{$f_schema}\".\"{$relation}\"";

        if ($cascade) {
            $sql .= ' CASCADE';
        }

        return $this->connection->execute($sql);
    }
}
