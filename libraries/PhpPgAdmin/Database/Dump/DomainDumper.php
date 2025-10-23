<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\DomainActions;

/**
 * Dumper for PostgreSQL domains.
 */
class DomainDumper extends ExportDumper
{
    private $domainOid;
    private $deferredConstraints = [];

    /**
     * @var \PhpPgAdmin\Database\Dump\DependencyGraph\DependencyGraph|null
     */
    private $dependencyGraph = null;

    public function dump($subject, array $params, array $options = [])
    {
        $domainName = $params['domain'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$domainName) {
            return;
        }

        // Reset deferred constraints
        $this->deferredConstraints = [];

        // Accept dependency graph if passed in options
        $this->dependencyGraph = $options['dependency_graph'] ?? null;

        $domainActions = new DomainActions($this->connection);
        $rs = $domainActions->getDomain($domainName);

        if (!$rs || $rs->EOF) {
            return;
        }

        $this->domainOid = $rs->fields['oid'];

        $schemaQuoted = $this->connection->quoteIdentifier($schema);
        $domainQuoted = $this->connection->quoteIdentifier($domainName);

        $this->write("\n-- Domain: $schemaQuoted.$domainQuoted\n");
        $this->writeDrop('DOMAIN', "$schemaQuoted.$domainQuoted", $options);

        $this->write("CREATE DOMAIN $schemaQuoted.$domainQuoted AS {$rs->fields['domtype']}");

        if (isset($rs->fields['domdef'])) {
            $this->write("\n    DEFAULT {$rs->fields['domdef']}");
        }

        $notNull = false;
        if ($this->connection->phpBool($rs->fields['domnotnull'])) {
            $this->write("\n    NOT NULL");
            $notNull = true;
        }

        // Constraints - analyze for deferral
        $this->analyzeConstraints($rs->fields['oid'], $notNull);

        $this->write(";\n");

        if ($this->shouldIncludeComments($options) && !empty($rs->fields['domcomment'])) {
            $comment = $this->connection->escapeString($rs->fields['domcomment']);
            $this->write(
                "\nCOMMENT ON DOMAIN $schemaQuoted.$domainQuoted IS '{$comment}';\n"
            );
        }

        $this->writeOwner(
            "$schemaQuoted.$domainQuoted",
            'DOMAIN',
            $rs->fields['domowner']
        );
        $this->writePrivileges(
            $domainName,
            'type',
            $rs->fields['domowner']
        );
    }

    /**
     * Get deferred constraints collected during the last dump() call.
     *
     * These must be applied after all functions are created, so SchemaDumper
     * collects them and emits them in its deferred section.
     *
     * @return array
     */
    public function getDeferredConstraints(): array
    {
        return $this->deferredConstraints;
    }

    /**
     * Analyze constraints and determine which should be deferred.
     *
     * @param string $domainOid Domain OID
     * @param bool $notNull Whether NOT NULL is already handled
     */
    protected function analyzeConstraints($domainOid, $notNull)
    {
        $sql = "SELECT conname, pg_catalog.pg_get_constraintdef(oid, true) AS consrc
                FROM pg_catalog.pg_constraint
                WHERE contypid = '{$domainOid}'::oid";

        $rs = $this->connection->selectSet($sql);
        if (!$rs) {
            return;
        }

        while (!$rs->EOF) {
            $src = $rs->fields['consrc'];
            $conname = $rs->fields['conname'];

            // Skip NOT NULL constraint if already handled
            if ($notNull && stripos($src, 'NOT NULL') !== false) {
                $rs->moveNext();
                continue;
            }

            // Check if constraint should be deferred
            if ($this->shouldDeferConstraint($src)) {
                // Store for later application
                $this->deferredConstraints[] = [
                    'name' => $conname,
                    'definition' => $src,
                ];
            } else {
                // Write inline
                $connameQuoted = $this->connection->escapeIdentifier($conname);
                $this->write("\n    CONSTRAINT $connameQuoted $src");
            }

            $rs->moveNext();
        }
    }

    /**
     * Check if a constraint should be deferred based on function dependencies.
     *
     * @param string $constraintDef Constraint definition
     * @return bool True if should be deferred
     */
    protected function shouldDeferConstraint($constraintDef)
    {
        // If no dependency graph, defer if contains functions
        if (!$this->dependencyGraph || !$this->domainOid) {
            return $this->containsFunctionCall($constraintDef);
        }

        // If no function call, don't defer
        if (!$this->containsFunctionCall($constraintDef)) {
            return false;
        }

        // Extract function OIDs and check if any come after domain
        $functionOids = $this->extractFunctionOids($constraintDef);

        foreach ($functionOids as $funcOid) {
            if ($this->dependencyGraph->shouldDefer($this->domainOid, $funcOid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply deferred constraints.
     *
     * @param string $schemaQuoted Quoted schema name
     * @param string $domainQuoted Quoted domain name
     */
    protected function applyDeferredConstraints($schemaQuoted, $domainQuoted)
    {
        if (empty($this->deferredConstraints)) {
            return;
        }

        $this->write("\n-- Deferred domain constraints\n\n");

        $needsValidation = [];

        foreach ($this->deferredConstraints as $constraint) {
            $connameQuoted = $this->connection->escapeIdentifier($constraint['name']);
            $this->write("ALTER DOMAIN $schemaQuoted.$domainQuoted ");
            $this->write("ADD CONSTRAINT $connameQuoted {$constraint['definition']} NOT VALID;\n");
            $needsValidation[] = $connameQuoted;
        }

        // Validate constraints
        if (!empty($needsValidation)) {
            $this->write("\n-- Validate deferred domain constraints\n\n");
            foreach ($needsValidation as $constraintName) {
                $this->write("ALTER DOMAIN $schemaQuoted.$domainQuoted ");
                $this->write("VALIDATE CONSTRAINT $constraintName;\n");
            }
        }
    }

    /**
     * Check if constraint definition contains a function call.
     *
     * @param string $constraintDef Constraint definition
     * @return bool True if contains function call
     */
    protected function containsFunctionCall($constraintDef)
    {
        return preg_match('/\w+\s*\(/i', $constraintDef) === 1;
    }

    /**
     * Extract function OIDs from constraint definition.
     *
     * @param string $constraintDef Constraint definition
     * @return array Array of function OIDs
     */
    protected function extractFunctionOids($constraintDef)
    {
        $oids = [];

        if (preg_match_all('/(?:(\w+)\.)?(\w+)\s*\(/i', $constraintDef, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $schema = $match[1] ?: null;
                $funcName = $match[2];

                if (self::isBuiltinFunction($funcName)) {
                    continue;
                }

                $funcOid = $this->resolveFunctionOid($funcName, $schema);
                if ($funcOid) {
                    $oids[] = $funcOid;
                }
            }
        }

        return $oids;
    }

    /**
     * Resolve function name to OID.
     *
     * @param string $funcName Function name
     * @param string|null $schema Optional schema
     * @return string|null Function OID or null
     */
    protected function resolveFunctionOid($funcName, $schema = null)
    {
        $funcName = $this->connection->escapeString($funcName);

        if ($schema) {
            $schema = $this->connection->escapeString($schema);
            $sql = "SELECT p.oid
                    FROM pg_catalog.pg_proc p
                    JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                    WHERE p.proname = '$funcName'
                    AND n.nspname = '$schema'
                    AND p.prokind = 'f'
                    LIMIT 1";
        } else {
            $sql = "SELECT p.oid
                    FROM pg_catalog.pg_proc p
                    WHERE p.proname = '$funcName'
                    AND p.prokind = 'f'
                    LIMIT 1";
        }

        $result = $this->connection->selectSet($sql);

        if ($result && !$result->EOF) {
            return $result->fields['oid'];
        }

        return null;
    }

    /**
     * @deprecated Use analyzeConstraints instead
     */
    protected function dumpConstraints($domainOid, $notNull)
    {
        $sql = "SELECT conname, pg_catalog.pg_get_constraintdef(oid, true) AS consrc
                FROM pg_catalog.pg_constraint
                WHERE contypid = '{$domainOid}'::oid";

        $rs = $this->connection->selectSet($sql);
        if (!$rs) {
            return;
        }
        while (!$rs->EOF) {
            $src = $rs->fields['consrc'];
            // Skip NOT NULL constraint if already handled
            if ($notNull && stripos($src, 'NOT NULL') !== false) {
                $rs->moveNext();
                continue;
            }
            $conname = $this->connection->escapeIdentifier($rs->fields['conname']);
            $this->write("\n    CONSTRAINT $conname {$rs->fields['consrc']}");
            $rs->moveNext();
        }
    }
}