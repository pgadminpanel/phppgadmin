<?php

/**
 * PHPPgAdmin 6.2.0
 */

namespace PhpPgAdmin\Database;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\TableActions;

/**
 * Modifies SELECT queries to replace bytea columns with their lengths.
 * This avoids transferring large binary data over the network for display purposes.
 * Original bytea data can be downloaded separately via key-based lookup.
 */
class ByteaQueryModifier extends AppContext
{
    /**
     * Modify a parsed SELECT query to replace bytea columns with octet_length() calls.
     *
     * @param array  $parsed    Parsed query array from PHPSQLParser
     * @param string $query     Original SQL query string
     * @param array  $keyFields Array of key field names for row identification
     *
     * @return array ['query' => string, 'bytea_columns' => array] Modified query and metadata
     */
    public function modifyQuery($parsed, $query, $keyFields)
    {
        // WITH queries are hard to rewrite safely with our regex-based approach.
        if (preg_match('/^\s*WITH\b/i', $query)) {
            return ['query' => $query, 'bytea_columns' => []];
        }

        // Safety checks - only handle simple SELECT queries
        if (empty($parsed['SELECT'])) {
            return ['query' => $query, 'bytea_columns' => []];
        }

        // Normalize key fields input:
        // - legacy: list of key field names
        // - new: map alias => list of key field names
        $keyFieldsByAlias = [];
        if (is_array($keyFields) && !empty($keyFields)) {
            $keys = array_keys($keyFields);
            $isAssoc = $keys !== range(0, count($keyFields) - 1);
            if ($isAssoc) {
                $keyFieldsByAlias = $keyFields;
            }
        }

        // Extract relation information from FROM clause
        $relations = $this->extractRelations($parsed);
        if ($relations === null || empty($relations)) {
            return ['query' => $query, 'bytea_columns' => []];
        }

        // Legacy behavior: single-table only + key list
        if (empty($keyFieldsByAlias)) {
            if (count($relations) !== 1) {
                return ['query' => $query, 'bytea_columns' => []];
            }
            $onlyAlias = array_keys($relations)[0];
            if (!is_array($keyFields) || empty($keyFields)) {
                return ['query' => $query, 'bytea_columns' => []];
            }
            $keyFieldsByAlias[$onlyAlias] = $keyFields;
        }

        // Get bytea columns per relation
        $byteaColumnsByAlias = [];
        foreach ($relations as $alias => $rel) {
            $byteaColumnsByAlias[$alias] = $this->getByteaColumns($rel['schema'], $rel['table']);
        }

        $hasAnyBytea = false;
        foreach ($byteaColumnsByAlias as $cols) {
            if (!empty($cols)) {
                $hasAnyBytea = true;
                break;
            }
        }
        if (!$hasAnyBytea) {
            return ['query' => $query, 'bytea_columns' => []];
        }

        // Determine what columns are selected per relation (for key availability checks)
        $selected = $this->getSelectedColumnsByAlias($parsed, $relations);

        // Check if query has SELECT * or alias.*
        $hasSelectStar = $selected['has_global_star'];
        $hasQualifiedStar = !empty($selected['alias_star']);

        // If we need to expand star(s), only do it when safe.
        $willExpandStar = $hasSelectStar || $hasQualifiedStar;
        if ($willExpandStar && !$this->isSafeToExpandStar($parsed)) {
            return ['query' => $query, 'bytea_columns' => []];
        }

        // Modify the SELECT clause
        $modifiedParsed = $parsed;
        $byteaMetadata = [];

        if ($willExpandStar) {
            $modifiedParsed['SELECT'] = $this->expandSelectStars(
                $relations,
                $byteaColumnsByAlias,
                $keyFieldsByAlias,
                $selected,
                $byteaMetadata
            );
        } else {
            $modifiedParsed['SELECT'] = $this->rewriteSelectItems(
                $parsed['SELECT'],
                $relations,
                $byteaColumnsByAlias,
                $keyFieldsByAlias,
                $selected,
                $byteaMetadata
            );
        }

        // Reconstruct the query
        $modifiedQuery = $this->reconstructQuery($modifiedParsed, $query);

        return ['query' => $modifiedQuery, 'bytea_columns' => $byteaMetadata];
    }

    /**
     * Extract relations (schema/table + alias) from FROM clause.
     * Returns null if query is too complex (subqueries, etc.)
     *
     * @param array $parsed Parsed query
     *
     * @return array|null alias => ['schema' => string, 'table' => string]
     */
    private function extractRelations($parsed)
    {
        if (empty($parsed['FROM']) || !is_array($parsed['FROM'])) {
            return null;
        }

        $pg = $this->postgres();
        $defaultSchema = $pg->_schema ?? 'public';

        $relations = [];
        foreach ($parsed['FROM'] as $from) {
            if (($from['expr_type'] ?? '') !== 'table') {
                return null;
            }

            $parts = $from['no_quotes']['parts'] ?? [];
            if (empty($parts)) {
                return null;
            }

            $schema = $defaultSchema;
            $table = null;
            if (count($parts) === 2) {
                $schema = $parts[0];
                $table = $parts[1];
            } elseif (count($parts) === 1) {
                $table = $parts[0];
            } else {
                return null;
            }

            $alias = $from['alias']['name'] ?? $table;
            if (empty($alias) || empty($table)) {
                return null;
            }

            $relations[$alias] = ['schema' => $schema, 'table' => $table];
        }

        return $relations;
    }

    /**
     * Determine selected columns per relation alias.
     *
     * @return array ['has_global_star'=>bool, 'alias_star'=>array, 'cols'=>array]
     */
    private function getSelectedColumnsByAlias($parsed, $relations)
    {
        $hasGlobalStar = false;
        $aliasStar = [];
        $cols = [];
        foreach ($relations as $alias => $rel) {
            $cols[$alias] = [];
        }

        foreach ($parsed['SELECT'] as $item) {
            $base = $item['base_expr'] ?? '';
            if ($base === '*') {
                $hasGlobalStar = true;
                continue;
            }

            if (($item['expr_type'] ?? '') === 'colref') {
                $parts = $item['no_quotes']['parts'] ?? [];
                if (count($parts) === 2 && end($parts) === '*') {
                    $qual = $parts[0];
                    if (isset($relations[$qual])) {
                        $aliasStar[$qual] = true;
                    }
                    continue;
                }

                $colName = end($parts);
                if (empty($colName) || $colName === '*') {
                    continue;
                }

                if (count($parts) === 1) {
                    // Unqualified; only safe if a single relation exists
                    if (count($relations) === 1) {
                        $onlyAlias = array_keys($relations)[0];
                        $cols[$onlyAlias][$colName] = true;
                    }
                } elseif (count($parts) === 2) {
                    $qual = $parts[0];
                    if (isset($relations[$qual])) {
                        $cols[$qual][$colName] = true;
                    }
                } elseif (count($parts) === 3) {
                    // schema.table.col; map by table name if unique
                    $table = $parts[1];
                    $matches = [];
                    foreach ($relations as $alias => $rel) {
                        if ($rel['table'] === $table) {
                            $matches[] = $alias;
                        }
                    }
                    if (count($matches) === 1) {
                        $cols[$matches[0]][$colName] = true;
                    }
                }
            }
        }

        return ['has_global_star' => $hasGlobalStar, 'alias_star' => $aliasStar, 'cols' => $cols];
    }

    /**
     * Get list of bytea column names for a table.
     *
     * @param string $schema Schema name
     * @param string $table  Table name
     *
     * @return array Array of column names that are bytea type
     */
    private function getByteaColumns($schema, $table)
    {
        $pg = $this->postgres();
        // Ensure TableActions uses the correct schema context
        (new SchemaActions($pg))->setSchema($schema);
        $tableActions = new TableActions($pg);

        try {
            // Get all table attributes
            $attrs = $tableActions->getTableAttributes($table);
            if (!$attrs || $attrs->recordCount() === 0) {
                return [];
            }

            $byteaColumns = [];
            while (!$attrs->EOF) {
                $type = $attrs->fields['type'] ?? '';
                // Check for bytea type (could be "bytea" or with typmod)
                if (strpos($type, 'bytea') === 0) {
                    $byteaColumns[] = $attrs->fields['attname'];
                }
                $attrs->moveNext();
            }

            return $byteaColumns;
        } catch (\Exception $e) {
            // If we can't get column info, return empty array (graceful degradation)
            return [];
        }
    }

    /**
     * Get all columns for a table (for SELECT * expansion).
     *
     * @param string $schema Schema name
     * @param string $table  Table name
     *
     * @return array|null Array of ['name' => string, 'type' => string] or null on error
     */
    private function getTableColumns($schema, $table)
    {
        $pg = $this->postgres();
        // Ensure TableActions uses the correct schema context
        (new SchemaActions($pg))->setSchema($schema);
        $tableActions = new TableActions($pg);

        try {
            $attrs = $tableActions->getTableAttributes($table);
            if (!$attrs || $attrs->recordCount() === 0) {
                return null;
            }

            $columns = [];
            while (!$attrs->EOF) {
                $columns[] = [
                    'name' => $attrs->fields['attname'],
                    'type' => $attrs->fields['type'] ?? ''
                ];
                $attrs->moveNext();
            }

            return $columns;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if it's safe to expand SELECT *.
     * Avoid expansion if query has features that make it risky.
     *
     * @param array $parsed Parsed query
     *
     * @return bool True if safe to expand
     */
    private function isSafeToExpandStar($parsed)
    {
        // Don't expand if DISTINCT is used
        if (!empty($parsed['DISTINCT'])) {
            return false;
        }

        // Don't expand if GROUP BY is used
        if (!empty($parsed['GROUP'])) {
            return false;
        }

        // Don't expand if UNION/INTERSECT/EXCEPT is used
        if (!empty($parsed['UNION']) || !empty($parsed['INTERSECT']) || !empty($parsed['EXCEPT'])) {
            return false;
        }

        return true;
    }

    /**
     * Expand SELECT * / alias.* to explicit column list with bytea modifications.
     *
     * @param array $relations alias => ['schema'=>..., 'table'=>...]
     * @param array $byteaColumnsByAlias alias => [byteaCol...]
     * @param array $keyFieldsByAlias alias => [keyField...]
     * @param array $selected output of getSelectedColumnsByAlias()
     * @param array $byteaMetadata (by-ref) collects metadata keyed by output column name
     *
     * @return array Modified SELECT clause items
     */
    private function expandSelectStars($relations, $byteaColumnsByAlias, $keyFieldsByAlias, $selected, &$byteaMetadata)
    {
        $selectItems = [];

        $hasGlobalStar = !empty($selected['has_global_star']);
        $aliasStar = $selected['alias_star'] ?? [];

        foreach ($relations as $alias => $rel) {
            if (!$hasGlobalStar && empty($aliasStar[$alias])) {
                continue;
            }

            $cols = $this->getTableColumns($rel['schema'], $rel['table']);
            if ($cols === null) {
                // Graceful degradation: abort expansion
                return $selectItems;
            }

            $byteaCols = $byteaColumnsByAlias[$alias] ?? [];
            $keys = $keyFieldsByAlias[$alias] ?? [];
            if (empty($keys) || !is_array($keys)) {
                // No way to download rows from this relation
                $keys = [];
            }

            foreach ($cols as $column) {
                $colName = $column['name'];
                $isBytea = in_array($colName, $byteaCols);
                $qualifiedExpr = pg_escape_id($alias) . '.' . pg_escape_id($colName);

                if ($isBytea && !empty($keys)) {
                    $selectItems[] = [
                        'expr_type' => 'expression',
                        'base_expr' => 'octet_length(' . $qualifiedExpr . ')',
                        'alias' => [
                            'as' => true,
                            'name' => $colName,
                            'base_expr' => 'AS ' . pg_escape_id($colName)
                        ]
                    ];

                    $byteaMetadata[$colName] = [
                        'schema' => $rel['schema'],
                        'table' => $rel['table'],
                        'column' => $colName,
                        'key_fields' => array_values($keys)
                    ];
                } else {
                    $selectItems[] = [
                        'expr_type' => 'colref',
                        'base_expr' => $qualifiedExpr,
                        'no_quotes' => ['parts' => [$alias, $colName]]
                    ];
                }
            }
        }

        return $selectItems;
    }

    /**
     * Rewrite SELECT items to replace bytea columns with octet_length().
     * Only modifies plain column references, leaves expressions/functions alone.
     *
     * @param array $selectItems Original SELECT items
     * @param array $relations alias => ['schema'=>..., 'table'=>...]
     * @param array $byteaColumnsByAlias alias => [byteaCol...]
     * @param array $keyFieldsByAlias alias => [keyField...]
     * @param array $selected output of getSelectedColumnsByAlias()
     * @param array $byteaMetadata (by-ref) collects metadata keyed by output column name
     *
     * @return array Modified SELECT items
     */
    private function rewriteSelectItems($selectItems, $relations, $byteaColumnsByAlias, $keyFieldsByAlias, $selected, &$byteaMetadata)
    {
        $modified = [];

        $selectedCols = $selected['cols'] ?? [];
        $hasGlobalStar = !empty($selected['has_global_star']);
        $aliasStar = $selected['alias_star'] ?? [];

        foreach ($selectItems as $item) {
            // Only modify plain column references (colref)
            if (($item['expr_type'] ?? '') === 'colref') {
                // Extract column name from parts
                $parts = $item['no_quotes']['parts'] ?? [];
                $colName = end($parts); // last part

                if (!empty($colName) && $colName !== '*') {
                    // Resolve relation alias
                    $resolvedAlias = null;
                    if (count($parts) === 1) {
                        if (count($relations) === 1) {
                            $resolvedAlias = array_keys($relations)[0];
                        }
                    } elseif (count($parts) === 2) {
                        $qual = $parts[0];
                        if (isset($relations[$qual])) {
                            $resolvedAlias = $qual;
                        }
                    } elseif (count($parts) === 3) {
                        $table = $parts[1];
                        $matches = [];
                        foreach ($relations as $alias => $rel) {
                            if ($rel['table'] === $table) {
                                $matches[] = $alias;
                            }
                        }
                        if (count($matches) === 1) {
                            $resolvedAlias = $matches[0];
                        }
                    }

                    if ($resolvedAlias !== null) {
                        $byteaCols = $byteaColumnsByAlias[$resolvedAlias] ?? [];
                        $keys = $keyFieldsByAlias[$resolvedAlias] ?? [];

                        if (!empty($keys) && is_array($keys) && in_array($colName, $byteaCols)) {
                            // Ensure key fields for this relation are selected
                            $keysSelected = $hasGlobalStar || !empty($aliasStar[$resolvedAlias]);
                            if (!$keysSelected) {
                                $keysSelected = true;
                                foreach ($keys as $keyField) {
                                    if (empty($selectedCols[$resolvedAlias][$keyField])) {
                                        $keysSelected = false;
                                        break;
                                    }
                                }
                            }

                            if ($keysSelected) {
                                // Preserve existing alias if present; otherwise keep original column name
                                $outputName = $item['alias']['name'] ?? $colName;
                                $expr = $item['base_expr'];

                                $modified[] = [
                                    'expr_type' => 'expression',
                                    'base_expr' => 'octet_length(' . $expr . ')',
                                    'alias' => [
                                        'as' => true,
                                        'name' => $outputName,
                                        'base_expr' => 'AS ' . pg_escape_id($outputName)
                                    ]
                                ];

                                $byteaMetadata[$outputName] = [
                                    'schema' => $relations[$resolvedAlias]['schema'],
                                    'table' => $relations[$resolvedAlias]['table'],
                                    'column' => $colName,
                                    'key_fields' => array_values($keys)
                                ];
                                continue;
                            }
                        }
                    }
                }
            }

            // Keep everything else as-is
            $modified[] = $item;
        }

        return $modified;
    }

    /**
     * Reconstruct SQL query from modified parsed structure.
     * Only modifies the SELECT clause, keeping other parts unchanged.
     *
     * @param array  $parsed Modified parsed query
     * @param string $originalQuery Original query for fallback
     *
     * @return string Modified SQL query
     */
    private function reconstructQuery($parsed, $originalQuery)
    {
        // Build SELECT clause by reconstructing each item
        $selectParts = [];
        foreach ($parsed['SELECT'] as $item) {
            $expr = $this->reconstructExpression($item);

            if (!empty($item['alias']['name'])) {
                $selectParts[] = $expr . ' AS ' . $item['alias']['name'];
            } else {
                $selectParts[] = $expr;
            }
        }
        $selectClause = 'SELECT ' . implode(', ', $selectParts);

        // Replace only the SELECT clause in the original query
        if (preg_match('/^(.*?)\bSELECT\b\s+(.+?)\s+\bFROM\b(.*)$/is', $originalQuery, $matches)) {
            return $matches[1] . $selectClause . ' FROM' . $matches[3];
        }

        // Complete fallback: return original query unchanged
        return $originalQuery;
    }

    /**
     * Recursively reconstruct an expression from parsed structure.
     *
     * @param array $item Parsed expression item
     *
     * @return string Reconstructed expression
     */
    private function reconstructExpression($item)
    {
        $exprType = $item['expr_type'] ?? '';

        // For aggregate_function and simple_function, reconstruct with arguments
        if ($exprType === 'aggregate_function' || $exprType === 'simple_function') {
            $funcName = $item['base_expr'];
            $args = [];

            if (!empty($item['sub_tree']) && is_array($item['sub_tree'])) {
                foreach ($item['sub_tree'] as $arg) {
                    $args[] = $this->reconstructExpression($arg);
                }
            }

            return $funcName . '(' . implode(', ', $args) . ')';
        }

        // For other expression types, use base_expr
        return $item['base_expr'] ?? '';
    }
}
