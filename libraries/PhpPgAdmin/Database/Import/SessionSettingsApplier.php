<?php

namespace PhpPgAdmin\Database\Import;

/**
 * Tracks and reapplies session-level SET statements across reconnects.
 * Filters out commands not supported by the target PostgreSQL version.
 */
class SessionSettingsApplier
{
    /** @var LogCollector */
    private $logs;
    /** @var array */
    private $cachedSettings = [];
    /** @var array */
    private $seenSettings = [];
    /** @var float PostgreSQL version (e.g., 9.0, 9.5, 11, 18) */
    private $pgVersion;

    /** @var array Patterns for known session-level SET commands */
    private $allowPatterns = [
        '/^SET\s+session_replication_role\s*=\s*/i',
        '/^SET\s+statement_timeout\s*=\s*/i',
        '/^SET\s+lock_timeout\s*=\s*/i',
        '/^SET\s+idle_in_transaction_session_timeout\s*=\s*/i',
        '/^SET\s+transaction_timeout\s*=\s*/i',
        '/^SET\s+client_encoding\s*=\s*/i',
        '/^SET\s+standard_conforming_strings\s*/i',
        '/^SET\s+search_path\s+/i',
        '/^SET\s+check_function_bodies\s*=\s*/i',
        '/^SET\s+xmloption\s*=\s*/i',
        '/^SET\s+client_min_messages\s*=\s*/i',
        '/^SET\s+row_security\s*=\s*/i',
        '/^SELECT\s+pg_catalog\.set_config\(\s*\'search_path\'/i',
    ];

    /**
     * @param LogCollector $logs
     * @param float $pgVersion PostgreSQL version (e.g., 9.0, 11, 18)
     */
    public function __construct(LogCollector $logs, float $pgVersion)
    {
        $this->logs = $logs;
        $this->pgVersion = $pgVersion;
    }

    public function collectFromStatements(array $statements, array &$state): void
    {
        foreach ($statements as $stmt) {
            $this->collectFromStatement($stmt, $state);
        }
    }

    /**
     * Check if a SET command is supported by the PostgreSQL version.
     * @param string $stmt SQL statement to check
     * @return bool True if supported, false if unsupported
     */
    private function isCommandSupported(string $stmt): bool
    {
        $stmtLower = strtolower(trim($stmt));

        // Check version-specific commands
        if (preg_match('/^set\s+lock_timeout\s*=\s*/i', $stmtLower)) {
            // lock_timeout introduced in PostgreSQL 9.3
            return $this->pgVersion >= 9.3;
        }

        if (preg_match('/^set\s+idle_in_transaction_session_timeout\s*=\s*/i', $stmtLower)) {
            // idle_in_transaction_session_timeout introduced in PostgreSQL 9.6
            return $this->pgVersion >= 9.6;
        }

        if (preg_match('/^set\s+transaction_timeout\s*=\s*/i', $stmtLower)) {
            // transaction_timeout introduced in PostgreSQL 14
            return $this->pgVersion >= 14;
        }

        if (preg_match('/^set\s+row_security\s*=\s*/i', $stmtLower)) {
            // row_security introduced in PostgreSQL 9.5
            return $this->pgVersion >= 9.5;
        }

        // All other known commands are supported in 9.0+
        return true;
    }

    /**
     * Collect a single statement for session settings tracking.
     * @param string $stmt SQL statement to collect
     * @param array|null $state Optional state array to update with side effects
     * @return bool True if statement was collected and should be executed, false if skipped
     */
    public function collectFromStatement(string $stmt, ?array &$state = null): bool
    {
        $stmtTrim = trim($stmt);
        if ($stmtTrim === '') {
            return true; // Empty statement, allow it through
        }

        // Check if this is a SET command at all
        $isSet = $this->isSetCommand($stmtTrim);

        if (!$isSet) {
            // Not a SET command - allow execution without caching
            return true;
        }

        // This is a SET command - check if it's a known/allowed one
        if (!$this->isAllowedSet($stmtTrim)) {
            // Unknown SET command - skip with warning
            $preview = substr($stmtTrim, 0, 80);
            $this->logs->addWarning('Skipping unknown SET command: ' . $preview);
            return false;
        }

        // Known SET command - check if supported by PostgreSQL version
        if (!$this->isCommandSupported($stmtTrim)) {
            $preview = substr($stmtTrim, 0, 80);
            $this->logs->addInfo('Skipping unsupported SET command for PostgreSQL ' . $this->pgVersion . ': ' . $preview);
            return false;
        }

        if (substr($stmtTrim, -1) !== ';') {
            $stmtTrim .= ';';
        }

        // Track search_path and encoding side effects if state is provided
        if ($state !== null) {
            if (preg_match('/^SET\s+search_path\s+TO\s+(.+);?$/i', $stmtTrim, $m)) {
                $first = trim(explode(',', $m[1])[0]);
                $state['current_schema'] = trim($first, " \"'{}");
            } elseif (preg_match('/set_config\(\s*\'search_path\'\s*,\s*\'([^\']*)\'/i', $stmtTrim, $m)) {
                $parts = explode(',', $m[1]);
                $state['current_schema'] = trim($parts[0], " \"'{}");
            }

            if (preg_match('/^SET\s+client_encoding\s*=\s*\'?([A-Za-z0-9_-]+)\'?/i', $stmtTrim, $m)) {
                $state['encoding'] = $m[1];
            }
        }

        $norm = strtolower(preg_replace('/\s+/', ' ', $stmtTrim));
        if (!isset($this->seenSettings[$norm])) {
            $this->cachedSettings[] = $stmtTrim;
            $this->seenSettings[$norm] = true;
        }

        // Persist cached settings back to shared state if provided
        if ($state !== null) {
            $state['cached_settings'] = $this->cachedSettings;
        }

        return true; // Statement collected successfully, should be executed
    }

    public function applySettings($pg): int
    {
        $errorCount = 0;
        foreach ($this->cachedSettings as $sql) {
            $sqlTrim = trim($sql);
            if ($sqlTrim === '') {
                continue;
            }

            try {
                $res = $pg->execute($sqlTrim);
                if ($res !== 0) {
                    $this->logs->addError('Session setting failed: ' . $sqlTrim);
                    $errorCount++;
                } else {
                    $this->logs->addInfo('Session setting applied: ' . substr($sqlTrim, 0, 120));
                }
            } catch (\Throwable $e) {
                $this->logs->addError('Session setting exception: ' . $sqlTrim . ' detail=' . $e->getMessage());
                $errorCount++;
            }
        }
        return $errorCount;
    }

    public function getCachedSettings(): array
    {
        return $this->cachedSettings;
    }

    public function setCachedSettings(array $settings): void
    {
        $this->cachedSettings = $settings;
        $this->seenSettings = [];
        foreach ($settings as $s) {
            $norm = strtolower(preg_replace('/\s+/', ' ', trim($s)));
            $this->seenSettings[$norm] = true;
        }
    }

    private function isAllowedSet(string $stmt): bool
    {
        foreach ($this->allowPatterns as $pattern) {
            if (preg_match($pattern, $stmt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a statement looks like a SET command
     * @param string $stmt SQL statement to check
     * @return bool True if it looks like a SET command
     */
    private function isSetCommand(string $stmt): bool
    {
        $stmt = ltrim($stmt);
        return strlen($stmt) > 4 && strncasecmp($stmt, 'SET', 3) === 0 && ctype_space($stmt[3]);
    }

}
