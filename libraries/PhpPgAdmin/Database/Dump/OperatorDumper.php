<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\OperatorActions;

/**
 * Dumper for PostgreSQL operators.
 */
class OperatorDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $oid = $params['operator_oid'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$oid) {
            return;
        }

        $operatorActions = new OperatorActions($this->connection);
        $rs = $operatorActions->getOperator($oid);

        if (!$rs || $rs->EOF) {
            return;
        }

        $schemaQuoted = $this->connection->quoteIdentifier($schema);

        $name = $rs->fields['oprname'];
        $leftType = $rs->fields['oprleftname'] ?? null;
        $rightType = $rs->fields['oprrightname'] ?? null;

        $oprcom = $rs->fields['oprcom'] ?? null;
        $oprnegate = $rs->fields['oprnegate'] ?? null;
        $oprrest = $rs->fields['oprrest'] ?? null;
        $oprjoin = $rs->fields['oprjoin'] ?? null;
        $oprcanhash = $rs->fields['oprcanhash'] ?? null;
        $oprcanmerge = $rs->fields['oprcanmerge'] ?? null;
        $oprcomment = $rs->fields['oprcomment'] ?? null;
        $functionQuoted = $this->connection->quoteIdentifier($rs->fields['oprcode']);
        $needSchema = !empty($rs->fields['procnsname']) &&
            $rs->fields['procnsname'] !== 'pg_catalog';
        if ($needSchema) {
            $functionQuoted = $this->connection->quoteIdentifier($rs->fields['procnsname']) . '.' . $functionQuoted;
        }

        $this->write("\n-- Operator: $schemaQuoted.{$name}\n");

        $this->writeDrop('OPERATOR', "$schemaQuoted.{$name} (" . ($leftType ?: 'NONE') . ", " . ($rightType ?: 'NONE') . ")", $options);

        $this->write("CREATE OPERATOR $schemaQuoted.{$name} (\n");
        $this->write("    FUNCTION = {$functionQuoted}");

        if ($leftType !== null) {
            $this->write(",\n    LEFTARG = {$leftType}");
        }
        if ($rightType !== null) {
            $this->write(",\n    RIGHTARG = {$rightType}");
        }
        if (!empty($oprcom)) {
            $this->write(",\n    COMMUTATOR = {$oprcom}");
        }
        if (!empty($oprnegate)) {
            $this->write(",\n    NEGATOR = {$oprnegate}");
        }
        if (!empty($oprrest) && $oprrest !== '-' && $oprrest !== '0') {
            $restQuoted = $this->connection->quoteIdentifier($oprrest);
            if (!empty($rs->fields['restnsname']) && $rs->fields['restnsname'] !== 'pg_catalog') {
                $restQuoted = $this->connection->quoteIdentifier($rs->fields['restnsname']) . '.' . $restQuoted;
            }
            $this->write(",\n    RESTRICT = {$restQuoted}");
        }
        if (!empty($oprjoin) && $oprjoin !== '-' && $oprjoin !== '0') {
            $joinQuoted = $this->connection->quoteIdentifier($oprjoin);
            if (!empty($rs->fields['joinnsname']) && $rs->fields['joinnsname'] !== 'pg_catalog') {
                $joinQuoted = $this->connection->quoteIdentifier($rs->fields['joinnsname']) . '.' . $joinQuoted;
            }
            $this->write(",\n    JOIN = {$joinQuoted}");
        }
        if ($oprcanhash === 't') {
            $this->write(",\n    HASHES");
        }
        if ($oprcanmerge === 't') {
            $this->write(",\n    MERGES");
        }

        $this->write("\n);\n");

        if ($oprcomment !== null && $this->shouldIncludeComments($options)) {
            $oprcomment = $this->connection->escapeString($oprcomment);
            $this->write("\nCOMMENT ON OPERATOR $schemaQuoted.{$name} (" . ($leftType ?: 'NONE') . ", " . ($rightType ?: 'NONE') . ") IS '{$oprcomment}';\n");
        }
    }
}
