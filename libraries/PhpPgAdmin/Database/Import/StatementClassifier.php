<?php

namespace PhpPgAdmin\Database\Import;

class StatementClassifier
{
    /**
     * Classify a single SQL statement. Returns one of:
     * cluster_object, db_object, schema_object, data, rights, ownership_change,
     * self_affecting, connection_change, drop, unknown
     */
    public static function classify(string $sql, string $currentUser = ''): string
    {
        // Avoid creating large lowercase copies of huge statements (e.g., COPY blocks)
        // Work on the original string with case-insensitive regexes anchored at start.
        $s = ltrim($sql);

        // connection change: psql meta-command like \connect or \c
        if (preg_match('/^\\\\?c(onnect)?\b/i', $s) || preg_match('/^\\c\b/i', $s)) {
            return 'connection_change';
        }

        // Ownership changes (must execute after object creation but before rights)
        if (preg_match('/^\s*ALTER\s+(TABLE|SEQUENCE|VIEW|MATERIALIZED\s+VIEW|FUNCTION|PROCEDURE|SCHEMA|DATABASE|TABLESPACE|TYPE|DOMAIN|AGGREGATE)\s+.*\s+OWNER\s+TO\b/i', $s)) {
            return 'ownership_change';
        }
        if (preg_match('/^\s*REASSIGN\s+OWNED\s+BY\b/i', $s)) {
            return 'ownership_change';
        }

        // DROP statements
        if (preg_match('/^drop\b/i', $s))
            return 'drop';

        // Cluster-wide objects
        if (preg_match('/^create\s+(role|user|database|tablespace)\b/i', $s) || preg_match('/^alter\s+role\b/i', $s)) {
            // role/user alteration may be self-affecting
            if (!empty($currentUser) && preg_match('/\b(role|user)\s+"?' . preg_quote($currentUser, '/') . '"?/i', $s)) {
                return 'self_affecting';
            }
            return 'cluster_object';
        }

        // CREATE DATABASE/TABLESPACE specifically
        if (preg_match('/^create\s+(database|tablespace)\b/i', $s))
            return 'cluster_object';

        // Rights / grants
        if (preg_match('/^grant\b/i', $s) || preg_match('/^revoke\b/i', $s))
            return 'rights';

        // CREATE SCHEMA
        if (preg_match('/^create\s+schema\b/i', $s))
            return 'db_object';

        // Schema-level object creation
        if (preg_match('/^create\s+(table|view|index|sequence|function|type|domain|trigger)\b/i', $s))
            return 'schema_object';

        // COPY or INSERT or UPDATE/DELETE are data
        if (preg_match('/^copy\b/i', $s) || preg_match('/^insert\b/i', $s) || preg_match('/^update\b/i', $s) || preg_match('/^delete\b/i', $s))
            return 'data';

        // ALTER TABLE etc - treat as schema_object
        if (preg_match('/^alter\s+(table|view|sequence|function)\b/i', $s))
            return 'schema_object';

        // ALTER ROLE/DROP ROLE that affect current user
        if (!empty($currentUser) && preg_match('/^(alter|drop)\s+role\b.*\b' . preg_quote($currentUser, '/') . '\b/i', $s)) {
            return 'self_affecting';
        }

        return 'unknown';
    }
}
