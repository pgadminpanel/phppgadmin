<?php

namespace PhpPgAdmin\Database\Cursor;

use PhpPgAdmin\Database\Postgres;

/**
 * Calculates optimal chunk sizes for cursor-based reading
 * based on available memory and estimated row sizes.
 */
class ChunkCalculator
{
    /**
     * Minimum chunk size (safety lower bound)
     */
    const MIN_CHUNK_SIZE = 50;

    /**
     * Maximum chunk size (safety upper bound)
     */
    const MAX_CHUNK_SIZE = 50000;

    /**
     * Target memory per chunk (5-10 MB)
     */
    const TARGET_MEMORY_MIN = 5 * 1024 * 1024;
    const TARGET_MEMORY_MAX = 10 * 1024 * 1024;

    /**
     * Memory safety margin (use 50-60% of available memory)
     */
    const MEMORY_SAFETY_FACTOR = 0.55;

    /**
     * Calculate optimal chunk size based on table statistics and available memory
     * 
     * @param Postgres $connection Database connection
     * @param string $tableName Table name
     * @param string|null $schemaName Schema name (uses current schema if null)
     * @param int|null $memoryLimitBytes Override memory limit (null = auto-detect)
     * @return array ['chunk_size' => int, 'max_row_bytes' => int, 'memory_available' => int, 'row_count' => int]
     * @throws \RuntimeException On calculation error
     */
    public static function calculate(
        Postgres $connection,
        string $tableName,
        ?string $schemaName = null,
        ?int $memoryLimitBytes = null,
        ?string $relationKind = null
    ): array {
        $schemaName = $schemaName ?? $connection->_schema;

        // Get memory limit
        if ($memoryLimitBytes === null) {
            $memoryLimitBytes = self::parseMemoryLimit(ini_get('memory_limit'));
        }

        // Calculate available memory for chunking
        $currentUsage = memory_get_usage(true);
        $availableMemory = $memoryLimitBytes > 0
            ? (int) (($memoryLimitBytes - $currentUsage) * self::MEMORY_SAFETY_FACTOR)
            : self::TARGET_MEMORY_MAX;

        // Ensure we have at least some memory to work with
        $availableMemory = max(self::TARGET_MEMORY_MIN, $availableMemory);

        // Get table statistics
        //$stats = RowSizeEstimator::getTableStats($connection, $tableName, $schemaName);

        // Estimate maximum row size
        $maxRowBytes = RowSizeEstimator::estimateMaxRowSize(
            $connection,
            $tableName,
            $schemaName,
            1000,
            $relationKind
        );

        // Calculate chunk size based on available memory and row size
        if ($maxRowBytes > 0) {
            // Use target memory divided by estimated row size
            $chunkSize = (int) floor($availableMemory / $maxRowBytes);
        } else {
            // Fallback if we can't estimate size
            $chunkSize = 100;
        }

        // Apply hard limits
        $chunkSize = max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $chunkSize));

        return [
            'chunk_size' => $chunkSize,
            'max_row_bytes' => $maxRowBytes,
            'memory_available' => $availableMemory,
            //'row_count' => $stats['row_count'] ?? 0,
            //'table_size_bytes' => $stats['total_bytes'] ?? 0,
        ];
    }

    /**
     * Parse PHP memory_limit ini setting to bytes
     * 
     * @param string $memoryLimit e.g., "128M", "512M", "1G", "-1"
     * @return int Bytes, or PHP_INT_MAX if unlimited (-1)
     */
    public static function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);

        // Unlimited memory
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        // Parse with unit suffix
        $unit = strtoupper(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;

        switch ($unit) {
            case 'G':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'M':
                $value *= 1024 * 1024;
                break;
            case 'K':
                $value *= 1024;
                break;
            default:
                // No unit or already in bytes
                if (!is_numeric($unit)) {
                    $value = (int) $memoryLimit;
                }
                break;
        }

        return $value;
    }

    /**
     * Adaptive chunk size adjustment based on actual memory usage
     * 
     * Used for dynamic adjustment after fetching chunks.
     * 
     * @param int $currentChunkSize Current chunk size
     * @param int $bytesUsed Actual bytes consumed by last chunk
     * @param int $targetBytes Target memory per chunk (default: 7.5MB midpoint)
     * @return int Adjusted chunk size
     */
    public static function adaptChunkSize(
        int $currentChunkSize,
        int $bytesUsed,
        ?int $targetBytes = null
    ): int {
        if ($targetBytes === null) {
            // Use midpoint between min and max target
            $targetBytes = (self::TARGET_MEMORY_MIN + self::TARGET_MEMORY_MAX) / 2;
        }

        // Calculate adjustment factor based on actual vs target
        if ($bytesUsed > self::TARGET_MEMORY_MAX) {
            // Too much memory, decrease by 30%
            $newSize = (int) ceil($currentChunkSize * 0.7);
        } elseif ($bytesUsed < self::TARGET_MEMORY_MIN && $currentChunkSize < 10000) {
            // Too little memory, increase by 30%
            $newSize = (int) ceil($currentChunkSize * 1.3);
        } else {
            // Within acceptable range, no change
            $newSize = $currentChunkSize;
        }

        // Apply hard limits
        return max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $newSize));
    }

    /**
     * Check if current memory usage is approaching the limit
     * 
     * @param float $threshold Threshold as fraction (default: 0.8 = 80%)
     * @return bool True if approaching limit
     */
    public static function isApproachingMemoryLimit(float $threshold = 0.8): bool
    {
        $current = memory_get_usage(true);
        $limit = self::parseMemoryLimit(ini_get('memory_limit'));

        if ($limit === PHP_INT_MAX) {
            return false; // Unlimited memory
        }

        return $current > ($limit * $threshold);
    }

    /**
     * Get memory usage statistics
     * 
     * @return array ['current' => int, 'peak' => int, 'limit' => int, 'percent' => float]
     */
    public static function getMemoryStats(): array
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = self::parseMemoryLimit(ini_get('memory_limit'));

        $percent = ($limit > 0 && $limit !== PHP_INT_MAX)
            ? ($current / $limit) * 100
            : 0;

        return [
            'current' => $current,
            'peak' => $peak,
            'limit' => $limit,
            'percent' => $percent,
            'current_mb' => round($current / 1024 / 1024, 2),
            'peak_mb' => round($peak / 1024 / 1024, 2),
            'limit_mb' => ($limit === PHP_INT_MAX) ? 'unlimited' : round($limit / 1024 / 1024, 2),
        ];
    }
}
