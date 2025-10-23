<?php

namespace PhpPgAdmin\Tests\Integration;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Connector;
use PhpPgAdmin\Database\Dump\DependencyGraph\DependencyAnalyzer;
use PhpPgAdmin\Database\Postgres;
use PhpPgAdmin\Misc;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that need a live PostgreSQL connection.
 *
 * Connection settings come from environment variables:
 *   PPAD_TEST_HOST  (default: localhost)
 *   PPAD_TEST_PORT  (default: 5432)
 *   PPAD_TEST_DB    (default: web_test)
 *   PPAD_TEST_USER  (default: web_test)
 *   PPAD_TEST_PASS  (required — tests are skipped if not set)
 *
 * Each test uses its own schema (PPAD_TEST_SCHEMA, default deps_test) so
 * that fixtures never touch the public schema.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static $rawConn = null;
    protected static $conn = null;
    protected static string $schema = 'deps_test';
    protected static string $dbname = 'web_test';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PPAD_TEST_HOST') ?: 'localhost';
        $port = (int) (getenv('PPAD_TEST_PORT') ?: 5432);
        self::$dbname = getenv('PPAD_TEST_DB') ?: 'web_test';
        $user = getenv('PPAD_TEST_USER') ?: 'web_test';
        $pass = getenv('PPAD_TEST_PASS');
        self::$schema = getenv('PPAD_TEST_SCHEMA') ?: 'deps_test';

        if ($pass === false || $pass === '') {
            self::markTestSkipped('PPAD_TEST_PASS environment variable not set');
        }

        // Raw connection for DDL (pg_query supports multi-statement SQL)
        self::$rawConn = @pg_connect(
            "host=$host port=$port dbname=" . self::$dbname . " user=$user password=$pass"
        );
        if (self::$rawConn === false) {
            self::markTestSkipped('Cannot connect to PostgreSQL: ' . pg_last_error());
        }

        // AppContainer must be initialized before Connector/Postgres can be used
        AppContainer::setAppName('phpPgAdmin');
        AppContainer::setAppVersion('8.0.4');
        AppContainer::setPgServerMinVersion('9.0');
        $conf = ['theme' => 'bootstrap'];
        AppContainer::setConf($conf);
        $lang = ['applocale' => 'en_US', 'applangdir' => 'ltr'];
        AppContainer::setLang($lang);
        $serverId = "$host:$port:prefer";
        $_REQUEST['server'] = $serverId;
        $_SESSION['webdbLogin'] = [
            $serverId => [
                'host' => $host,
                'port' => $port,
                'sslmode' => 'prefer',
                'username' => $user,
                'password' => $pass,
                'defaultdb' => self::$dbname,
                'desc' => 'Test',
            ],
        ];

        try {
            AppContainer::setMisc(new Misc());
        } catch (\Throwable $e) {
            self::markTestSkipped('Cannot initialize Misc: ' . $e->getMessage());
        }

        try {
            $connector = new Connector($host, $port, 'prefer', $user, $pass, self::$dbname);
            $driver = $connector->getDriver($platform, $version, $majorVersion);
            self::$conn = new Postgres($connector->conn, $majorVersion);
            self::$conn->_schema = self::$schema;
        } catch (\Throwable $e) {
            self::markTestSkipped('Cannot initialize Postgres wrapper: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        // Leave the schema in place; next setUp resets it.
    }

    protected function resetSchema(): void
    {
        $schema = self::$schema;
        pg_query(self::$rawConn, "DROP SCHEMA IF EXISTS \"$schema\" CASCADE");
        pg_query(self::$rawConn, "CREATE SCHEMA \"$schema\"");
        pg_query(self::$rawConn, "SET search_path TO \"$schema\"");
    }

    /**
     * Load a fixture SQL file into the test schema.
     *
     * Uses the raw pg connection because fixtures contain multiple
     * statements and ADOdb's execute() only runs one.
     */
    protected function loadFixture(string $file): void
    {
        $path = __DIR__ . '/../fixtures/' . $file;
        if (!is_file($path)) {
            self::fail("Fixture not found: $path");
        }
        $sql = file_get_contents($path);
        $r = pg_query(self::$rawConn, $sql);
        if ($r === false) {
            self::fail("Failed to load fixture $file: " . pg_last_error(self::$rawConn));
        }
    }

    /**
     * Build the dependency graph for the test schema and return it.
     */
    protected function buildGraph(): \PhpPgAdmin\Database\Dump\DependencyGraph\DependencyGraph
    {
        $analyzer = new DependencyAnalyzer(self::$conn, [self::$schema]);
        return $analyzer->buildGraph();
    }

    /**
     * Return object names in topological order.
     *
     * @return string[]
     */
    protected function sortedNames(): array
    {
        return array_map(fn($n) => $n->name, $this->buildGraph()->getSortedNodes());
    }

    /**
     * Assert that $first appears before $second in the sorted order.
     */
    protected function assertBefore(string $first, string $second, array $names): void
    {
        $iFirst = array_search($first, $names, true);
        $iSecond = array_search($second, $names, true);
        self::assertNotFalse($iFirst, "$first should be in the graph");
        self::assertNotFalse($iSecond, "$second should be in the graph");
        self::assertLessThan(
            $iSecond,
            $iFirst,
            "$first should come before $second (dependency order)"
        );
    }
}