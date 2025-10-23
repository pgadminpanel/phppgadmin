<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Postgres;

/**
 * Base class for all dumpers providing shared utilities.
 */
abstract class ExportDumper extends AppContext
{
    /**
     * @var Postgres
     */
    protected $connection;

    /**
     * @var resource|null
     */
    protected $outputStream = null;

    /**
     * @var ExportDumper|null Parent dumper (for accessing deferred collections)
     */
    protected $parentDumper = null;

    /**
     * @var array Dump options (no_owner, no_privileges, etc.)
     */
    protected $dumpOptions = [];

    public function __construct(?Postgres $connection = null)
    {
        $this->connection = $connection ?? AppContainer::getPostgres();
    }

    /**
     * Sets the output stream for the dump.
     * 
     * @param resource $stream
     */
    public function setOutputStream($stream)
    {
        $this->outputStream = $stream;
    }

    /**
     * Sets the dump options (no_owner, no_privileges, etc.)
     * Should be called at the beginning of dump() method.
     * 
     * @param array $options
     */
    protected function setDumpOptions(array $options)
    {
        $this->dumpOptions = $options;
    }

    /**
     * Helper to create a sub-dumper with the same output stream
     * 
     * @param string $subject
     * @param Postgres $connection
     * @return ExportDumper
     */
    protected function createSubDumper($subject, $connection = null)
    {
        $dumper = DumpFactory::create($subject, $connection ?? $this->connection);

        if ($this->outputStream) {
            $dumper->setOutputStream($this->outputStream);
        }
        // Set parent reference so sub-dumpers can access parent's deferred collections
        $dumper->parentDumper = $this;
        // Propagate dump options to sub-dumper
        $dumper->dumpOptions = $this->dumpOptions;
        return $dumper;
    }

    /**
     * Writes a string to the output stream or echoes it.
     * 
     * @param string $string
     */
    protected function write($string)
    {
        if ($this->outputStream) {
            fwrite($this->outputStream, $string);
        } else {
            echo $string;
        }
    }

    private static $headerLevel = 0;

    /**
     * Generates a header for the dump.
     */
    protected function writeHeader($title)
    {
        if (self::$headerLevel++ > 0) {
            return;
        }
        $name = AppContainer::getAppName();
        $version = AppContainer::getAppVersion();
        $this->write("--\n");
        $this->write("-- $name $version PostgreSQL dump\n");
        $this->write("-- Subject: {$title}\n");
        $this->write("-- Date: " . date('Y-m-d H:i:s') . "\n");
        $this->write("--\n\n");
    }

    protected function writeFooter()
    {
        if (self::$headerLevel-- > 1) {
            return;
        }
        $this->write("-- Dump completed on " . date('Y-m-d H:i:s') . "\n");
    }

    protected function writeConnectHeader(?string $database = null)
    {
        if (!isset($database)) {
            $database = $this->connection->conn->database;
        }
        if (self::$headerLevel++ > 1) {
            return;
        }
        $this->write("\\connect " . $this->connection->quoteIdentifier($database) . "\n");
        $this->write("\\encoding UTF8\n");
        $this->write("SET client_encoding = 'UTF8';\n");
        // pg_dump session settings for reliable restores
        $this->write("SET statement_timeout = 0;\n");
        $this->write("SET lock_timeout = 0;\n");
        $this->write("SET idle_in_transaction_session_timeout = 0;\n");
        $this->write("SET transaction_timeout = 0;\n");
        $this->write("SET standard_conforming_strings = on;\n");
        // Remove search_path to avoid issues with functions that set it internally
        $this->write("SELECT pg_catalog.set_config('search_path', '', false);\n");
        $this->write("SET check_function_bodies = false;\n");
        $this->write("SET xmloption = content;\n");
        $this->write("SET client_min_messages = warning;\n");
        $this->write("SET row_security = off;\n");
        // Set session_replication_role to replica for the whole DB restore
        $this->write("SET session_replication_role = 'replica';\n\n");
    }

    protected function writeConnectFooter()
    {
        if (self::$headerLevel-- > 2) {
            return;
        }
        // After dumping this database, reset session_replication_role to origin
        $this->write("\nSET session_replication_role = 'origin';\n\n");
    }

    /**
     * Generates ALTER ... OWNER TO statement for an object.
     * Skipped if no_owner option is set.
     *
     * @param string $objectName Quoted object name (may include schema)
     * @param string $objectType SQL object type (TABLE, VIEW, FUNCTION, etc.)
     * @param string $owner Owner role name (unquoted)
     */
    protected function writeOwner($objectName, $objectType, $owner)
    {
        // Skip owner statements when explicitly requested or when performing
        // a data-only dump since owner changes are part of structure, not data.
        if (!empty($this->dumpOptions['no_owner']) || !empty($this->dumpOptions['data_only'])) {
            return;
        }

        if (empty($owner)) {
            return;
        }

        $ownerQuoted = $this->connection->quoteIdentifier($owner);
        $this->write("\nALTER {$objectType} {$objectName} OWNER TO {$ownerQuoted};\n");
    }

    /**
     * Generates full GRANT/REVOKE SQL for an object, including:
     * - REVOKE ALL FROM PUBLIC (falls nötig)
     * - GRANT ... 
     * - GRANT ... WITH GRANT OPTION
     * - SET/RESET SESSION AUTHORIZATION für Grantor ≠ Owner
     * Skipped if no_privileges option is set.
     *
     * @param string $objectName
     * @param string $objectType
     * @param string $owner
     */
    protected function writePrivileges($objectName, $objectType, $owner, $acl = null)
    {
        if (!empty($this->dumpOptions['no_privileges'])) {
            return;
        }

        $aclActions = new AclActions($this->connection);
        if (isset($acl)) {
            $privileges = $aclActions->parseAcl($acl);
        } else {
            $privileges = $aclActions->getPrivileges($objectName, $objectType);
        }
        $nameQuoted = $this->connection->quoteIdentifier($objectName);

        if (!is_array($privileges) || empty($privileges)) {
            return;
        }

        $this->write("\n-- Privileges for {$objectType} {$nameQuoted}\n");

        // ---------------------------------------------------------
        // 1) REVOKE ALL FROM PUBLIC (only if PUBLIC is explicitly in the ACL)
        // ---------------------------------------------------------
        foreach ($privileges as $priv) {
            if ($priv['entity'] === '' && empty($priv['privileges'])) {
                // PUBLIC explicitly has NO rights → REVOKE needed
                $this->write("REVOKE ALL ON {$objectType} {$nameQuoted} FROM PUBLIC;\n");
                break;
            }
        }

        // ---------------------------------------------------------
        // 2) GRANT / GRANT OPTION for each role
        // ---------------------------------------------------------
        foreach ($privileges as $priv) {

            $entity = $priv['entity'];
            $grantee = ($entity === '') ? 'PUBLIC' : $this->connection->quoteIdentifier($entity);

            $normalPrivs = array_diff($priv['privileges'], $priv['grantable']);
            $grantablePrivs = $priv['grantable'];

            // Skip: no privileges and no GRANT OPTION → nothing to do
            if (empty($normalPrivs) && empty($grantablePrivs)) {
                continue;
            }

            // Skip: Owner gets no GRANTs (pg_dump does the same)
            if ($entity !== '' && $entity === $owner) {
                continue;
            }

            // ---------------------------------------------------------
            // 2a) GRANT WITHOUT GRANT OPTION
            // ---------------------------------------------------------
            if (!empty($normalPrivs)) {

                // Session Authorization falls Grantor ≠ Owner
                if ($priv['grantor'] !== $owner) {
                    $grantor = $priv['grantor'];
                    $this->connection->clean($grantor);
                    $this->write("SET SESSION AUTHORIZATION '{$grantor}';\n");
                }

                $privList = implode(', ', $normalPrivs);
                $this->write("GRANT {$privList} ON {$objectType} {$nameQuoted} TO {$grantee};\n");

                if ($priv['grantor'] !== $owner) {
                    $this->write("RESET SESSION AUTHORIZATION;\n");
                }
            }

            // ---------------------------------------------------------
            // 2b) GRANT WITH GRANT OPTION
            // ---------------------------------------------------------
            if (!empty($grantablePrivs)) {

                if ($priv['grantor'] !== $owner) {
                    $grantor = $priv['grantor'];
                    $this->connection->clean($grantor);
                    $this->write("SET SESSION AUTHORIZATION '{$grantor}';\n");
                }

                $privList = implode(', ', $grantablePrivs);
                $this->write("GRANT {$privList} ON {$objectType} {$nameQuoted} TO {$grantee} WITH GRANT OPTION;\n");

                if ($priv['grantor'] !== $owner) {
                    $this->write("RESET SESSION AUTHORIZATION;\n");
                }
            }
        }
    }

    /**
     * Helper to generate DROP statement if requested.
     */
    protected function writeDrop($type, $name, $options)
    {
        if (!empty($options['drop_objects'])) {
            $this->write("DROP {$type} IF EXISTS {$name} CASCADE;\n\n");
        }
    }

    /**
     * Check if comments should be included in the dump.
     */
    protected function shouldIncludeComments($options)
    {
        // Default to true (include comments) unless explicitly disabled
        return $options['include_comments'] ?? true;
    }

    /**
     * Helper to generate IF NOT EXISTS clause.
     */
    protected function getIfNotExists($options)
    {
        return (!empty($options['if_not_exists'])) ? "IF NOT EXISTS " : "";
    }

    /**
     * Performs the traditional dump - outputs complete SQL structure + data.
     * Used for full database/schema/table exports with complete control.
     * Output is written to output stream (if set) or echoed directly.
     * 
     * @param string $subject The subject to dump (e.g., 'table', 'schema', 'database')
     * @param array $params Parameters for the dump (e.g., ['table' => 'my_table', 'schema' => 'public'])
     * @param array $options Options for the dump (e.g., ['clean' => true, 'if_not_exists' => true, 'data_only' => false])
     * @return void
     */
    public function dump($subject, array $params, array $options = [])
    {
        // Default: not supported by this dumper type
        throw new \Exception("Dump method not implemented in " . get_class($this));
    }


    /**
     * Check if a function name is a common built-in.
     *
     * @param string $funcName Function name
     * @return bool True if built-in
     */
    public static function isBuiltinFunction($funcName)
    {
        static $builtins = null;
        if ($builtins === null) {
            $builtins = array_flip([
                'now',
                'current_timestamp',
                'current_date',
                'current_time',
                'nextval',
                'currval',
                'setval',
                'random',
                'uuid_generate_v4',
                'lower',
                'upper',
                'length',
                'trim',
                'count',
                'sum',
                'avg',
                'min',
                'max',
                'abs',
                'round',
                'floor',
                'ceil',
                'coalesce',
                'nullif',
                'greatest',
                'least',
            ]);
        }

        return isset($builtins[strtolower($funcName)]);
    }
}