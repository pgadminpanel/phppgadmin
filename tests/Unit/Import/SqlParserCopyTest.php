<?php

namespace PhpPgAdmin\Tests\Unit\Import;

use PhpPgAdmin\Database\Import\SqlParser;
use PHPUnit\Framework\TestCase;

class SqlParserCopyTest extends TestCase
{
    public function testSingleChunkCopyIsReturnedAsStatementItem(): void
    {
        $sql = "COPY public.t (id) FROM stdin;\n1\n\\.\n";
        $result = SqlParser::parseFromString($sql);

        $this->assertSame('', $result['remainder']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('statement', $result['items'][0]['type']);
        $this->assertSame($sql, $result['items'][0]['content']);
    }

    public function testCopyWithoutTerminatorStaysInRemainder(): void
    {
        $sql = "COPY public.t (id) FROM stdin;\n1\n";
        $result = SqlParser::parseFromString($sql);

        $this->assertCount(0, $result['items']);
        $this->assertSame($sql, $result['remainder']);
    }

    public function testMultipleCopyBlocksInOneChunk(): void
    {
        $sql = "COPY a FROM stdin;\n1\n\\.\nCOPY b FROM stdin;\n2\n\\.\n";
        $result = SqlParser::parseFromString($sql);

        $this->assertSame('', $result['remainder']);
        $this->assertCount(2, $result['items']);
        $this->assertSame($sql, $result['items'][0]['content'] . $result['items'][1]['content']);
    }

    public function testCopyAfterSqlStatementInSameChunk(): void
    {
        $sql = "CREATE TABLE t(id int);\nCOPY t (id) FROM stdin;\n1\n\\.\n";
        $result = SqlParser::parseFromString($sql);

        $this->assertSame('', $result['remainder']);
        $this->assertCount(2, $result['items']);
        $this->assertSame('statement', $result['items'][0]['type']);
        $this->assertSame("CREATE TABLE t(id int);", trim($result['items'][0]['content']));
        $this->assertSame('statement', $result['items'][1]['type']);
        $this->assertSame("COPY t (id) FROM stdin;\n1\n\\.\n", $result['items'][1]['content']);
    }
}
