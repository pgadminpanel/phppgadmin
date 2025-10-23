<?php

namespace PhpPgAdmin\Tests\Unit\Dump;

use PhpPgAdmin\Database\Dump\SchemaDumper;
use PhpPgAdmin\Database\Dump\SequenceDumper;
use PhpPgAdmin\Database\Postgres;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

class SequenceDumperTest extends TestCase
{
    public function testSequenceDumperSkipsStructureForDataOnlyExports(): void
    {
        $connection = $this->createConnectionMock();
        $dumper = new SequenceDumper($connection);

        $output = $this->captureOutput(function ($stream) use ($dumper): void {
            $dumper->setOutputStream($stream);
            $dumper->dump('sequence', [
                'sequence' => 'ticket_messages_id_seq',
                'schema' => 'support',
            ], [
                'data_only' => true,
                'no_privileges' => true,
            ]);
        });

        $this->assertStringContainsString(
            "SELECT pg_catalog.setval('\"support\".\"ticket_messages_id_seq\"', 142, true);",
            $output
        );
        $this->assertStringNotContainsString('CREATE SEQUENCE', $output);
        $this->assertStringNotContainsString('ALTER SEQUENCE', $output);
    }

    public function testSequenceDumperSkipsSequenceValueForStructureOnlyExports(): void
    {
        $connection = $this->createConnectionMock();
        $dumper = new SequenceDumper($connection);

        $output = $this->captureOutput(function ($stream) use ($dumper): void {
            $dumper->setOutputStream($stream);
            $dumper->dump('sequence', [
                'sequence' => 'ticket_messages_id_seq',
                'schema' => 'support',
            ], [
                'structure_only' => true,
                'no_privileges' => true,
            ]);
        });

        $this->assertStringContainsString('CREATE SEQUENCE "support"."ticket_messages_id_seq"', $output);
        $this->assertStringNotContainsString('SELECT pg_catalog.setval(', $output);
    }

    public function testSchemaDumperStillWritesSequenceStateForDataOnlyExports(): void
    {
        $connection = $this->createConnectionMock();
        $dumper = new SchemaDumperProbe($connection);

        $output = $this->captureOutput(function ($stream) use ($dumper): void {
            $dumper->setOutputStream($stream);
            $dumper->dumpSequencesPublic('support', [
                'data_only' => true,
                'no_privileges' => true,
            ]);
        });

        $this->assertStringContainsString('-- Sequences in schema "support"', $output);
        $this->assertStringContainsString(
            "SELECT pg_catalog.setval('\"support\".\"ticket_messages_id_seq\"', 142, true);",
            $output
        );
        $this->assertStringNotContainsString('CREATE SEQUENCE', $output);
    }

    private function captureOutput(callable $writer): string
    {
        $stream = fopen('php://temp', 'w+');
        $writer($stream);
        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        return $output === false ? '' : $output;
    }

    private function createConnectionMock(): Postgres
    {
        $sequenceName = 'ticket_messages_id_seq';
        $sequenceRow = [
            'seqname' => $sequenceName,
            'last_value' => 142,
            'log_cnt' => 0,
            'is_called' => 't',
            'start_value' => 1,
            'increment_by' => 1,
            'max_value' => 9223372036854775807,
            'min_value' => 1,
            'cache_value' => 1,
            'is_cycled' => 'f',
            'seqcomment' => null,
            'seqowner' => 'postgres',
            'nspname' => 'support',
            'owned_table' => 'ticket_messages',
            'owned_column' => 'id',
        ];

        $connection = $this->getMockBuilder(Postgres::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clean', 'fieldClean', 'quoteIdentifier', 'phpBool', 'selectField', 'selectSet'])
            ->getMock();

        $connection->major_version = 16;
        $connection->_schema = 'support';
        $connection->conn = (object) ['database' => 'testdb'];

        $connection->method('clean')->willReturnCallback(function (&$value) {
            return $value;
        });

        $connection->method('fieldClean')->willReturnCallback(function (&$value) {
            return $value;
        });

        $connection->method('quoteIdentifier')->willReturnCallback(function ($identifier) {
            return '"' . str_replace('"', '""', $identifier) . '"';
        });

        $connection->method('phpBool')->willReturnCallback(function ($value) {
            return in_array($value, [true, 1, '1', 't', 'true'], true);
        });

        $connection->method('selectField')->willReturn('t');

        $connection->method('selectSet')->willReturnCallback(function (string $sql) use ($sequenceName, $sequenceRow) {
            if (strpos($sql, 'c.relname AS seqname') !== false) {
                return new FakeResultSet([$sequenceRow]);
            }

            if (strpos($sql, "SELECT c.relname\n                FROM pg_catalog.pg_class c") !== false) {
                return new FakeResultSet([['relname' => $sequenceName]]);
            }

            throw new RuntimeException('Unexpected SQL: ' . $sql);
        });

        return $connection;
    }
}

final class SchemaDumperProbe extends SchemaDumper
{
    public function dumpSequencesPublic(string $schema, array $options = []): void
    {
        $this->setDumpOptions($options);
        $this->setPrivateProperty('schemaEscaped', $schema);
        $this->setPrivateProperty('schemaQuoted', '"' . $schema . '"');
        $this->setPrivateProperty('selectedObjects', []);
        $this->setPrivateProperty('hasObjectSelection', false);

        $this->dumpSequences($schema, $options);
    }

    private function setPrivateProperty(string $name, $value): void
    {
        $property = new ReflectionProperty(SchemaDumper::class, $name);
        $property->setAccessible(true);
        $property->setValue($this, $value);
    }
}

final class FakeResultSet
{
    public $EOF = false;
    public $fields = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private $rows;

    private $index = 0;

    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
        $this->sync();
    }

    public function moveNext(): void
    {
        $this->index++;
        $this->sync();
    }

    private function sync(): void
    {
        if (!isset($this->rows[$this->index])) {
            $this->EOF = true;
            $this->fields = [];
            return;
        }

        $this->EOF = false;
        $this->fields = $this->rows[$this->index];
    }
}
