<?php

namespace PhpPgAdmin\Database\Cursor;

use PhpPgAdmin\Database\Postgres;

/**
 * Estimates row sizes for PostgreSQL tables
 * using sampling and pg_column_size() queries.
 */
class RowSizeEstimator
{
    /**
     * TOAST-capable types that can store large values
     */
    const TOAST_TYPES = [
        'text',
        'bytea',
        'json',
        'jsonb',
        'xml',
        'tsvector',
        'tsquery',
        'geometry',
        'geography',
        'raster',
    ];

    /**
     * Estimate maximum row size by sampling rows and using pg_column_size()
     * 
     * @param Postgres $connection Database connection
     * @param string $tableName Table name
     * @param string $schemaName Schema name
     * @param int $sampleSize Number of rows to sample (default 1000)
     * @return int Estimated maximum row size in bytes
     * @param string|null $relationKind Relation kind (table, view, etc.)
     */
    public static function estimateMaxRowSize(
        Postgres $connection,
        string $tableName,
        string $schemaName,
        int $sampleSize = 1000,
        ?string $relationKind = null
    ): int {
        try {
            // Get all column names
            $columns = self::getTableColumns($connection, $tableName, $schemaName);

            if (empty($columns)) {
                return 1024; // Default fallback: 1KB per row
            }

            // Build query to sum pg_column_size() for all columns
            $sizeExpressions = [];
            foreach ($columns as $column) {
                $escapedColumn = $connection->fieldClean($column);
                $sizeExpressions[] = sprintf('pg_column_size(%s)', $escapedColumn);
            }

            $sizeSql = implode(' + ', $sizeExpressions);

            // Sample random rows and get maximum total size
            $escapedSchema = $connection->fieldClean($schemaName);
            $escapedTable = $connection->fieldClean($tableName);

            if ($relationKind === null) {
                $relationKind = self::getRelationKind($connection, $tableName, $schemaName);
            }

            // TABLESAMPLE is only valid for ordinary tables, partitioned tables, and materialized views
            $hasTableSample = in_array($relationKind, ['r', 'p', 'm'], true) && $connection->major_version >= 9.5;
            $sql = sprintf(
                'SELECT MAX(row_size) as max_size, AVG(row_size) as avg_size, MIN(row_size) as min_size
                    FROM (
                        SELECT (%s) as row_size
                        FROM %s.%s
                        %s
                        LIMIT %d
                    ) t',
                $sizeSql,
                $escapedSchema,
                $escapedTable,
                $hasTableSample ? 'TABLESAMPLE SYSTEM(10)' : '',
                $sampleSize
            );

            $result = $connection->selectSet($sql);

            if ($result && !$result->EOF) {
                $maxSize = (int) $result->fields['max_size'];
                //$avgSize = (int) $result->fields['avg_size'];

                // Use max size, but add 20% buffer for variability
                $estimatedSize = (int) ceil($maxSize * 1.2);

                // Minimum 100 bytes per row
                return max(100, $estimatedSize);
            }

            // Fallback: estimate based on column count (500 bytes per column average)
            return count($columns) * 500;

        } catch (\Exception $e) {
            error_log('Failed to estimate row size: ' . $e->getMessage());

            // Fallback to conservative estimate
            return 5000; // 50KB per row default
        }
    }

    /**
     * Get list of columns with TOAST-capable large types
     * 
     * @param Postgres $connection Database connection
     * @param string $tableName Table name
     * @param string $schemaName Schema name
     * @return array Array of column names with large types
     */
    /*
    public static function getLargeColumns(
        Postgres $connection,
        string $tableName,
        string $schemaName
    ): array {
        try {
            $escapedSchema = $connection->clean($schemaName);
            $escapedTable = $connection->clean($tableName);

            // Build type list for WHERE clause
            $typeList = array_map(function ($type) use ($connection) {
                return $connection->clean($type);
            }, self::TOAST_TYPES);

            $typeListSql = "'" . implode("','", array_map('pg_escape_string', self::TOAST_TYPES)) . "'";

            $sql = sprintf(
                "SELECT a.attname as column_name, 
                        t.typname as type_name,
                        CASE 
                            WHEN a.atttypmod > 0 THEN a.atttypmod - 4
                            ELSE NULL
                        END as type_length
                 FROM pg_attribute a
                 JOIN pg_class c ON a.attrelid = c.oid
                 JOIN pg_namespace n ON c.relnamespace = n.oid
                 JOIN pg_type t ON a.atttypid = t.oid
                 WHERE n.nspname = '%s'
                   AND c.relname = '%s'
                   AND a.attnum > 0
                   AND NOT a.attisdropped
                   AND t.typname IN (%s)
                 ORDER BY a.attnum",
                $escapedSchema,
                $escapedTable,
                $typeListSql
            );

            $result = $connection->selectSet($sql);

            $largeColumns = [];
            while ($result && !$result->EOF) {
                $largeColumns[] = [
                    'name' => $result->fields['column_name'],
                    'type' => $result->fields['type_name'],
                    'length' => $result->fields['type_length'],
                ];
                $result->moveNext();
            }

            return $largeColumns;

        } catch (\Exception $e) {
            error_log('Failed to get large columns: ' . $e->getMessage());
            return [];
        }
    }
    */

    /**
     * Get table statistics (size, row count, average row size)
     * 
     * @param Postgres $connection Database connection
     * @param string $tableName Table name
     * @param string $schemaName Schema name
     * @return array ['total_bytes' => int, 'row_count' => int, 'avg_row_bytes' => int]
     */
    /*
    public static function getTableStats(
        Postgres $connection,
        string $tableName,
        string $schemaName
    ): array {
        try {
            $escapedSchema = $connection->clean($schemaName);
            $escapedTable = $connection->clean($tableName);

            $sql = sprintf(
                "SELECT 
                    pg_total_relation_size(c.oid) as total_bytes,
                    pg_table_size(c.oid) as table_bytes,
                    c.reltuples::bigint as row_count,
                    CASE 
                        WHEN c.reltuples > 0 THEN 
                            (pg_table_size(c.oid) / NULLIF(c.reltuples, 0))::bigint
                        ELSE 0
                    END as avg_row_bytes
                 FROM pg_class c
                 JOIN pg_namespace n ON c.relnamespace = n.oid
                 WHERE n.nspname = '%s'
                   AND c.relname = '%s'
                   AND c.relkind IN ('r', 'p')",
                $escapedSchema,
                $escapedTable
            );

            $result = $connection->selectSet($sql);

            if ($result && !$result->EOF) {
                return [
                    'total_bytes' => (int) $result->fields['total_bytes'],
                    'table_bytes' => (int) $result->fields['table_bytes'],
                    'row_count' => (int) $result->fields['row_count'],
                    'avg_row_bytes' => (int) $result->fields['avg_row_bytes'],
                ];
            }

            return [
                'total_bytes' => 0,
                'table_bytes' => 0,
                'row_count' => 0,
                'avg_row_bytes' => 0,
            ];

        } catch (\Exception $e) {
            error_log('Failed to get table stats: ' . $e->getMessage());

            return [
                'total_bytes' => 0,
                'table_bytes' => 0,
                'row_count' => 0,
                'avg_row_bytes' => 0,
            ];
        }
    }
    */

    /**
     * Get list of table columns
     * 
     * @param Postgres $connection Database connection
     * @param string $tableName Table name
     * @param string $schemaName Schema name
     * @return array Array of column names
     */
    protected static function getTableColumns(
        Postgres $connection,
        string $tableName,
        string $schemaName
    ): array {
        try {
            $escapedSchema = $connection->clean($schemaName);
            $escapedTable = $connection->clean($tableName);

            $sql = sprintf(
                "SELECT a.attname as column_name
                 FROM pg_attribute a
                 JOIN pg_class c ON a.attrelid = c.oid
                 JOIN pg_namespace n ON c.relnamespace = n.oid
                 WHERE n.nspname = '%s'
                   AND c.relname = '%s'
                   AND a.attnum > 0
                   AND NOT a.attisdropped
                 ORDER BY a.attnum",
                $escapedSchema,
                $escapedTable
            );

            $result = $connection->selectSet($sql);

            $columns = [];
            while ($result && !$result->EOF) {
                $columns[] = $result->fields['column_name'];
                $result->moveNext();
            }

            return $columns;

        } catch (\Exception $e) {
            error_log('Failed to get table columns: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get relation kind from pg_class for given schema/table
     *
     * @param Postgres $connection Database connection
     * @param string $tableName Table name
     * @param string $schemaName Schema name
     * @return string|null relkind or null if not found
     */
    protected static function getRelationKind(
        Postgres $connection,
        string $tableName,
        string $schemaName
    ): ?string {
        try {
            $escapedSchema = $connection->clean($schemaName);
            $escapedTable = $connection->clean($tableName);

            $sql = sprintf(
                "SELECT c.relkind
                 FROM pg_class c
                 JOIN pg_namespace n ON c.relnamespace = n.oid
                 WHERE n.nspname = '%s'
                   AND c.relname = '%s'",
                $escapedSchema,
                $escapedTable
            );

            $result = $connection->selectSet($sql);

            if ($result && !$result->EOF) {
                return $result->fields['relkind'];
            }

            return null;
        } catch (\Exception $e) {
            error_log('Failed to get relation kind: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if table is likely to have large rows (based on TOAST columns)
     * 
     * @param Postgres $connection Database connection
     * @param string $tableName Table name
     * @param string $schemaName Schema name
     * @return bool True if table has TOAST-capable columns
     */
    /*
    public static function hasLargeColumns(
        Postgres $connection,
        string $tableName,
        string $schemaName
    ): bool {
        $largeColumns = self::getLargeColumns($connection, $tableName, $schemaName);
        return !empty($largeColumns);
    }
    */

    /**
     * Get comprehensive table size analysis
     * 
     * @param Postgres $connection Database connection
     * @param string $tableName Table name
     * @param string $schemaName Schema name
     * @return array Comprehensive analysis
     */
    /*
    public static function analyzeTable(
        Postgres $connection,
        string $tableName,
        string $schemaName
    ): array {
        $stats = self::getTableStats($connection, $tableName, $schemaName);
        $largeColumns = self::getLargeColumns($connection, $tableName, $schemaName);
        $maxRowSize = self::estimateMaxRowSize($connection, $tableName, $schemaName, 1000);

        return [
            'table_name' => $tableName,
            'schema_name' => $schemaName,
            'total_size_bytes' => $stats['total_bytes'],
            'total_size_mb' => round($stats['total_bytes'] / 1024 / 1024, 2),
            'row_count' => $stats['row_count'],
            'avg_row_bytes' => $stats['avg_row_bytes'],
            'max_row_bytes_estimated' => $maxRowSize,
            'has_large_columns' => !empty($largeColumns),
            'large_columns' => $largeColumns,
            'recommended_chunk_size' => max(50, min(50000, (int) (5 * 1024 * 1024 / max(1, $maxRowSize)))),
        ];
    }
    */
}
