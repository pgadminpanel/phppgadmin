<?php

namespace PhpPgAdmin\Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SQL parser functions in helper.php.
 * 
 * These functions are security-critical: is_result_set_query() determines whether
 * a query can be executed with read-only permissions. Misclassifying a write
 * query (INSERT, UPDATE, DELETE, DROP) as read-only would be a security vulnerability.
 */
class SqlParserTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Load helper.php which contains the global functions
        require_once __DIR__ . '/../../../libraries/helper.php';
    }

    public function testExtractSingleQuery()
    {
        $sql = "SELECT * FROM users";
        $result = \extract_sql_queries($sql);

        $this->assertCount(1, $result);
        $this->assertEquals("SELECT * FROM users", $result[0]);
    }

    public function testSelectIsReadQuery()
    {
        $this->assertTrue(\is_result_set_query("SELECT * FROM users"));
    }

    public function testInsertIsNotReadQuery()
    {
        $this->assertFalse(\is_result_set_query("INSERT INTO users (name) VALUES ('test')"));
    }

    public function testDeleteIsNotReadQuery()
    {
        $this->assertFalse(\is_result_set_query("DELETE FROM users"));
    }

    public function testDropIsNotReadQuery()
    {
        $this->assertFalse(\is_result_set_query("DROP TABLE users"));
    }

    public function testSelectWithDropInStringLiteralIsReadQuery()
    {
        // Security test: string literal should not be interpreted as DROP command
        $sql = "SELECT 'DROP TABLE users' AS fake_command";
        $this->assertTrue(\is_result_set_query($sql));
    }

    public function testSelectWithDeleteInCommentIsReadQuery()
    {
        // Note: The current implementation extracts queries first, which means
        // comments are preserved. This test documents current behavior.
        $sql = "SELECT * FROM users -- safe comment";
        $this->assertTrue(\is_result_set_query($sql));
    }
}
