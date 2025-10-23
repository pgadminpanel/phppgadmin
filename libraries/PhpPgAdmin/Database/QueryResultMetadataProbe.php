<?php

namespace PhpPgAdmin\Database;

use PhpPgAdmin\Core\AppContext;

/**
 * Probes a SELECT query's result metadata without scanning tables.
 *
 * Uses a WHERE false wrapper so PostgreSQL returns a RowDescription
 * (column names + type OIDs) while producing zero rows.
 */
class QueryResultMetadataProbe extends AppContext
{
    /**
     * PostgreSQL OID for bytea.
     */
    private const BYTEA_OID = 17;

    /**
     * @param string $query SQL query (expected SELECT/WITH)
     *
     * @return array|null
     *   null on error
     *   otherwise: ['fields' => array<array{name:string,type_oid:int,is_bytea:bool}>, 'has_duplicate_names' => bool]
     */
    public function probeResultFields($query)
    {
        $pg = $this->postgres();
        $conn = $pg->conn->_connectionID ?? null;
        if (!$conn) {
            return null;
        }

        $query = trim($query);
        $query = rtrim($query, ';');

        // Wrap the query: forces type resolution, returns 0 rows.
        $probeSql = 'SELECT * FROM (' . $query . ') AS sub WHERE false LIMIT 0';

        // Avoid interfering with other pending results.
        while (pg_get_result($conn)) {
            // drain
        }

        if (!pg_send_query($conn, $probeSql)) {
            return null;
        }

        $res = pg_get_result($conn);
        if ($res === false) {
            return null;
        }

        $status = pg_result_status($res);
        if ($status !== PGSQL_TUPLES_OK && $status !== PGSQL_COMMAND_OK) {
            return null;
        }

        $num = pg_num_fields($res);
        $fields = [];
        $names = [];
        $hasDup = false;

        for ($i = 0; $i < $num; $i++) {
            $name = pg_field_name($res, $i);
            $oid = (int) pg_field_type_oid($res, $i);

            if (isset($names[$name])) {
                $hasDup = true;
            }
            $names[$name] = true;

            $fields[] = [
                'name' => $name,
                'type_oid' => $oid,
                'is_bytea' => ($oid === self::BYTEA_OID),
            ];
        }

        return ['fields' => $fields, 'has_duplicate_names' => $hasDup];
    }

    /**
     * Build an outer SELECT that replaces bytea output columns with octet_length().
     *
     * Requires unique output column names.
     *
     * @param string $query
     * @param array $fields output of probeResultFields()['fields']
     *
     * @return string
     */
    public function rewriteQueryReplaceByteaWithLength($query, $fields)
    {
        $query = trim($query);
        $query = rtrim($query, ';');

        $selectParts = [];
        $byteaMeta = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $qName = pg_escape_id($name);
            $ref = 'sub.' . $qName;

            if (!empty($field['is_bytea'])) {
                $selectParts[] = 'octet_length(' . $ref . ') AS ' . $qName;
                $byteaMeta[$name] = [
                    'schema' => null,
                    'table' => null,
                    'column' => $name,
                    'key_fields' => [],
                ];
            } else {
                $selectParts[] = $ref;
            }
        }

        $rewritten = 'SELECT ' . implode(', ', $selectParts) . ' FROM (' . $query . ') AS sub';
        return $rewritten;
    }
}
