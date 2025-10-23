<?php

namespace PhpPgAdmin\Database\Export;

use PhpPgAdmin\Core\AppContainer;

/**
 * Manages database dump operations and executable detection.
 * Handles pg_dump availability checking, format validation, and strategy selection.
 */
class DumpManager
{
    /**
     * Get the dump executable path, with automatic detection if needed
     * @param bool $all True for pg_dumpall, false for pg_dump
     * @return string|null Path to executable or null if not found
     */
    public static function getDumpExecutable($all = false)
    {
        $misc = AppContainer::getMisc();
        $info = $misc->getServerInfo();
        $exec_type = $all ? 'pg_dumpall' : 'pg_dump';
        $cache_key = "dump_executable_{$exec_type}";

        // Check session cache first
        if (isset($_SESSION[$cache_key])) {
            // skip for debugging purposes
            return $_SESSION[$cache_key];
        }


        $configured_path = $info[$all ? 'pg_dumpall_path' : 'pg_dump_path'] ?? null;

        // If path is configured, use it (even if empty string means "not found")
        if (isset($info[$all ? 'pg_dumpall_path' : 'pg_dump_path'])) {
            if (!empty($configured_path) && self::_checkExecutable($configured_path)) {
                $_SESSION[$cache_key] = $configured_path;
                return $configured_path;
            }
            // Configured path exists but doesn't work - don't search further
            if (!empty($configured_path)) {
                $_SESSION[$cache_key] = false;
                return null;
            }
        }

        // Try common default paths
        $default_paths = self::_getDefaultDumpPaths($exec_type);
        foreach ($default_paths as $path) {
            if (self::_checkExecutable($path)) {
                $_SESSION[$cache_key] = $path;
                return $path;
            }
        }

        // Fall back to searching PATH environment variable
        $found_path = self::_searchPath($exec_type);
        if ($found_path) {
            $_SESSION[$cache_key] = $found_path;
            return $found_path;
        }

        // Not found - cache the negative result
        $_SESSION[$cache_key] = false;
        return null;
    }

    /**
     * Get common default paths for dump executables based on OS
     * Delegates to _getDefaultExecutablePaths for unified handling
     * @param string $exec_type 'pg_dump' or 'pg_dumpall'
     * @return array List of paths to check
     */
    private static function _getDefaultDumpPaths($exec_type)
    {
        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        $paths = [];

        if ($is_windows) {
            // Check Program Files directories for PostgreSQL installations
            // Prioritize newest versions first
            $prog_files = [
                getenv('ProgramFiles'),
                getenv('ProgramFiles(x86)'),
            ];

            foreach ($prog_files as $base) {
                if (empty($base)) {
                    continue;
                }

                // Try to find PostgreSQL directories, sorted by version (newest first)
                $glob_pattern = $base . '\\PostgreSQL\\*\\bin\\' . $exec_type . '.exe';
                $found = glob($glob_pattern);
                if ($found) {
                    // Sort descending to prioritize newer versions
                    rsort($found);
                    $paths = array_merge($paths, $found);
                }
            }
        } else {
            // Unix-like systems - check common install locations
            $paths = [
                '/usr/local/bin/' . $exec_type,
                '/usr/bin/' . $exec_type,
                '/opt/postgresql/bin/' . $exec_type,
            ];
        }

        return $paths;
    }

    /**
     * Search for executable in system PATH environment variable
     * @param string $exec_type 'pg_dump' or 'pg_dumpall'
     * @return string|null Path to executable if found
     */
    private static function _searchPath($exec_type)
    {
        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        if ($is_windows) {
            // Use 'where' command on Windows
            $exec_name = $exec_type . '.exe';
            @exec('where ' . escapeshellarg($exec_name), $output, $return_var);
            if ($return_var === 0 && !empty($output)) {
                return trim($output[0]);
            }
        } else {
            // Use 'which' command on Unix-like systems
            @exec('which ' . escapeshellarg($exec_type), $output, $return_var);
            if ($return_var === 0 && !empty($output)) {
                return trim($output[0]);
            }
        }

        return null;
    }

    /**
     * Check if an executable file exists and is executable
     * @param string $path Path to check
     * @return bool True if executable exists and is readable
     */
    private static function _checkExecutable($path)
    {
        return file_exists($path) && is_file($path) && is_readable($path);
    }

    /**
     * Clear cached dump and utility executable paths (for testing or manual refresh)
     */
    public static function clearExecutableCache()
    {
        unset($_SESSION['dump_executable_pg_dump']);
        unset($_SESSION['dump_executable_pg_dumpall']);
        unset($_SESSION['dump_executable_psql']);
    }
}
