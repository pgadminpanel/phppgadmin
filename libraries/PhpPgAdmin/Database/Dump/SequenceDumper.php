<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\SequenceActions;

/**
 * Dumper for PostgreSQL sequences.
 */
class SequenceDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $this->setDumpOptions($options);

        $sequence = $params['sequence'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$sequence) {
            return;
        }

        $sequenceActions = new SequenceActions($this->connection);
        $rs = $sequenceActions->getSequence($sequence);

        if (!$rs || $rs->EOF) {
            return;
        }

        $schemaQuoted = $this->connection->quoteIdentifier($schema);
        $sequenceQuoted = $this->connection->quoteIdentifier($sequence);
        $dumpStructure = empty($options['data_only']);
        $dumpSequenceValue = empty($options['structure_only']);
        $hasLastValue = $rs->fields['last_value'] !== null && $rs->fields['last_value'] !== '';

        if ($dumpStructure || ($dumpSequenceValue && $hasLastValue)) {
            $this->write("\n-- Sequence: {$schemaQuoted}.{$sequenceQuoted}\n");
        }

        if ($dumpStructure) {
            $this->writeDrop('SEQUENCE', "{$schemaQuoted}.{$sequenceQuoted}", $options);

            $ifNotExists = $this->getIfNotExists($options);
            $this->write("CREATE SEQUENCE {$ifNotExists}{$schemaQuoted}.{$sequenceQuoted}\n");
            $this->write("    START WITH {$rs->fields['start_value']}\n");
            $this->write("    INCREMENT BY {$rs->fields['increment_by']}\n");
            $this->write("    MINVALUE {$rs->fields['min_value']}\n");
            $this->write("    MAXVALUE {$rs->fields['max_value']}\n");
            $this->write("    CACHE {$rs->fields['cache_value']}");
            if ($this->connection->phpBool($rs->fields['is_cycled'])) {
                $this->write("\n    CYCLE");
            }
            $this->write(";\n");
        }

        // Set the current value
        if ($dumpSequenceValue && $hasLastValue) {
            $this->write("SELECT pg_catalog.setval('{$schemaQuoted}.{$sequenceQuoted}', {$rs->fields['last_value']}, " . ($this->connection->phpBool($rs->fields['is_called']) ? 'true' : 'false') . ");\n");
        }

        if (!$dumpStructure) {
            return;
        }

        // Add comment if present and requested
        if (!empty($rs->fields['seqcomment']) && $this->shouldIncludeComments($options)) {
            $comment = $this->connection->escapeString($rs->fields['seqcomment']);
            $this->write("\nCOMMENT ON SEQUENCE {$schemaQuoted}.{$sequenceQuoted} IS '{$comment}';\n");
        }

        // Defer sequence ownership to be applied after all tables are dumped
        if (!empty($rs->fields['owned_table']) && !empty($rs->fields['owned_column'])) {
            if ($this->parentDumper instanceof SchemaDumper) {
                $this->parentDumper->addDeferredSequenceOwnership(
                    $schema,
                    $sequence,
                    $rs->fields['owned_table'],
                    $rs->fields['owned_column']
                );
            }
        }

        $this->writeOwner(
            "{$schemaQuoted}.{$sequenceQuoted}",
            'SEQUENCE',
            $rs->fields['seqowner']
        );
        $this->writePrivileges(
            $sequence,
            'sequence',
            $rs->fields['seqowner']
        );

    }
}
