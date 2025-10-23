<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\TypeActions;

/**
 * Dumper for PostgreSQL types (Base, Enum, Composite).
 */
class TypeDumper extends ExportDumper
{
    private $schemaQuoted = '';

    private $typeQuoted = '';

    public function dump($subject, array $params, array $options = [])
    {
        $typeName = $params['type'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$typeName) {
            return;
        }

        $this->schemaQuoted = $this->connection->quoteIdentifier($schema);
        $this->typeQuoted = $this->connection->quoteIdentifier($typeName);

        $typeActions = new TypeActions($this->connection);
        $rs = $typeActions->getType($typeName);

        if ($rs && !$rs->EOF) {
            $this->write("\n-- Type: {$this->schemaQuoted}.{$this->typeQuoted}\n");
            $this->writeDrop('TYPE', "{$this->schemaQuoted}.{$this->typeQuoted}", $options);

            $typtype = $rs->fields['typtype'];

            switch ($typtype) {
                case 'e': // Enum
                    $this->dumpEnum($typeName, $schema, $options);
                    break;
                case 'c': // Composite
                    $this->dumpComposite($typeName, $schema, $options);
                    break;
                case 'b': // Base
                    $this->dumpBase($rs->fields, $schema, $options);
                    break;
                default:
                    // Other types (pseudo, etc.) might not be dumpable or needed
                    break;
            }

            // Add comment if present and requested
            if ($this->shouldIncludeComments($options)) {
                $typeActions = new TypeActions($this->connection);
                $typeInfo = $typeActions->getType($typeName);
                $comment = $typeInfo && !$typeInfo->EOF ? $typeInfo->fields['typcomment'] : null;
                if (!empty($comment)) {
                    $this->connection->clean($comment);
                    $this->write(
                        "\nCOMMENT ON TYPE {$this->schemaQuoted}.{$this->typeQuoted} IS '{$comment}';\n"
                    );
                }
            }

            $this->writeOwner(
                "{$this->schemaQuoted}.{$this->typeQuoted}",
                'TYPE',
                $rs->fields['typowner']
            );
            $this->writePrivileges($typeName, 'type', $rs->fields['typowner']);
        }
    }

    protected function dumpEnum($typeName, $schema, $options)
    {
        $typeActions = new TypeActions($this->connection);
        $valuesRs = $typeActions->getEnumValues($typeName);
        $values = [];
        while ($valuesRs && !$valuesRs->EOF) {
            $val = $valuesRs->fields['enumval'];
            $this->connection->clean($val);
            $values[] = "'{$val}'";
            $valuesRs->moveNext();
        }

        $this->write(
            "CREATE TYPE {$this->schemaQuoted}.{$this->typeQuoted}\n    AS ENUM (" . implode(', ', $values) . ");\n"
        );

    }

    protected function dumpComposite($typeName, $schema, $options)
    {
        // We need to fetch the attributes of the composite type
        // This is similar to fetching table attributes
        $sql = "SELECT a.attname,
                    pg_catalog.format_type(a.atttypid, a.atttypmod) as type
                FROM pg_catalog.pg_attribute a
                JOIN pg_catalog.pg_class c ON a.attrelid = c.oid
                JOIN pg_catalog.pg_type t ON c.reltype = t.oid
                WHERE t.typname = '{$typeName}'
                    AND a.attnum > 0
                    AND NOT a.attisdropped
                ORDER BY a.attnum";

        $rs = $this->connection->selectSet($sql);
        $fields = [];
        while ($rs && !$rs->EOF) {
            $name = $this->connection->quoteIdentifier($rs->fields['attname']);
            $fields[] = "$name {$rs->fields['type']}";
            $rs->moveNext();
        }

        $this->write("CREATE TYPE {$this->schemaQuoted}.{$this->typeQuoted} AS (" . implode(', ', $fields) . ");\n");

    }

    protected function dumpBase($fields, $schema, $options)
    {
        $this->write("CREATE TYPE {$this->schemaQuoted}.{$this->typeQuoted} (\n");
        $this->write("    INPUT = {$fields['typin']},\n");
        $this->write("    OUTPUT = {$fields['typout']}");

        if ($fields['typlen'] != -1) {
            $this->write(",\n    INTERNALLENGTH = {$fields['typlen']}");
        }
        if ($fields['typalign'] != '') {
            $this->write(",\n    ALIGNMENT = " . $this->getAlignmentName($fields['typalign']));
        }
        if ($fields['typstorage'] ?? '' != '') {
            $this->write(",\n    STORAGE = " . $this->getStorageName($fields['typstorage']));
        }

        $this->write("\n);\n");
    }

    protected function getAlignmentName($align)
    {
        switch ($align) {
            case 'c':
                return 'char';
            case 's':
                return 'int2';
            case 'i':
                return 'int4';
            case 'd':
                return 'double';
            default:
                return $align;
        }
    }

    protected function getStorageName($storage)
    {
        switch ($storage) {
            case 'p':
                return 'plain';
            case 'e':
                return 'external';
            case 'm':
                return 'main';
            case 'x':
                return 'extended';
            default:
                return $storage;
        }
    }
}
