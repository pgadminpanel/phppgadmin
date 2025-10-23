<?php

namespace PhpPgAdmin\Database\Import\Data;

use RuntimeException;
use PhpPgAdmin\Database\Postgres;

class ColumnHeaderBuilder
{
    /** @var Postgres */
    private $pg;

    public function __construct(Postgres $pg)
    {
        $this->pg = $pg;
    }

    /**
     * @param string     $schema
     * @param string     $table
     * @param array|null $headerRow  Header names (if provided) or null for numeric mapping
     * @param int|null   $dataCols   Column count from first data row (for numeric mapping)
     *
     * @return array{mapping: array, meta: array, serial_omitted: bool}
     */
    public function build(string $schema, string $table, ?array $headerRow, ?int $dataCols): array
    {
        $tableMeta = $this->fetchTableColumns($schema, $table);
        if (empty($tableMeta)) {
            throw new RuntimeException('Table has no columns or does not exist');
        }

        // Header-based mapping
        if ($headerRow !== null) {
            $mapping = [];
            foreach ($headerRow as $name) {
                $found = null;
                foreach ($tableMeta as $col) {
                    if ($col['name'] === $name) {
                        $found = $col['name'];
                        break;
                    }
                }
                if ($found === null) {
                    throw new RuntimeException('Header column not found in table: ' . $name);
                }
                $mapping[] = $found;
            }
            return ['mapping' => $mapping, 'meta' => $tableMeta, 'serial_omitted' => false];
        }

        // Numeric mapping with optional serial omission
        if ($dataCols === null) {
            throw new RuntimeException('Unable to infer column mapping');
        }

        $serialOmitted = false;
        if (count($tableMeta) === $dataCols + 1 && $this->isSerialColumn($tableMeta[0])) {
            $serialOmitted = true;
            $tableMeta = array_slice($tableMeta, 1);
        }

        if (count($tableMeta) !== $dataCols) {
            throw new RuntimeException('Column count mismatch: input=' . $dataCols . ' table=' . count($tableMeta));
        }

        $mapping = array_map(function ($col) {
            return $col['name'];
        }, $tableMeta);

        return ['mapping' => $mapping, 'meta' => $tableMeta, 'serial_omitted' => $serialOmitted];
    }

    private function fetchTableColumns(string $schema, string $table): array
    {
        $schemaLit = $this->pg->escapeLiteral($schema);
        $tableLit = $this->pg->escapeLiteral($table);
        $sql = <<<"SQL"
        SELECT column_name, data_type, column_default
        FROM information_schema.columns
        WHERE table_schema = {$schemaLit} AND table_name = {$tableLit}
        ORDER BY ordinal_position
        SQL;
        $rs = $this->pg->selectSet($sql);
        if (!$rs || $rs->recordCount() === 0) {
            return [];
        }

        $cols = [];
        while (!$rs->EOF) {
            $name = $rs->fields['column_name'];
            $type = strtolower($rs->fields['data_type']);
            $default = $rs->fields['column_default'] ?? '';
            $cols[] = [
                'name' => $name,
                'type' => $type,
                'default' => $default,
                'is_bytea' => ($type === 'bytea'),
                'is_json' => ($type === 'json' || $type === 'jsonb'),
            ];
            $rs->moveNext();
        }
        return $cols;
    }

    private function isSerialColumn(array $col): bool
    {
        if (empty($col['default'])) {
            return false;
        }
        $isIntish = in_array($col['type'], ['integer', 'bigint', 'smallint'], true);
        return $isIntish && (stripos($col['default'], 'nextval(') !== false);
    }
}