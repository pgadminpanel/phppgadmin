<?php

namespace PhpPgAdmin\Database\Cursor;

use PhpPgAdmin\Database\Export\OutputFormatter;
use PhpPgAdmin\Database\Postgres;

/**
 * Reads PostgreSQL table/query results using server-side cursors
 * for memory-efficient streaming of large datasets.
 * 
 * Uses pg_* functions directly with DECLARE CURSOR / FETCH / CLOSE.
 * Returns numeric rows (PGSQL_NUM) with field metadata stored separately.
 */
class CursorReader
{
    /**
     * @var Postgres Database connection
     */
    protected $connection;

    /**
     * @var resource|object Native PostgreSQL connection resource (resource in PHP < 8.1, PgSql\Connection in PHP >= 8.1)
     */
    protected $pgResource;

    /**
     * @var string Unique cursor name
     */
    protected $cursorName;

    /**
     * @var string SQL query to execute
     */
    protected $sql;

    /**
     * @var int Rows per FETCH operation
     */
    protected $chunkSize;

    /**
     * @var string|null Relation kind (table, view, etc.)
     */
    protected $relationKind;

    /**
     * @var bool Whether we're in a transaction
     */
    protected $inTransaction = false;

    /**
     * @var bool Whether cursor is currently open
     */
    protected $isOpen = false;

    /**
     * @var array|null Field metadata (cached after first fetch)
     */
    protected $fields = null;

    /**
     * @var int Total rows processed
     */
    protected $totalRows = 0;

    /**
     * @var int Current chunk number
     */
    protected $chunkNumber = 0;

    /**
     * @var bool Whether we've reached end of data
     */
    protected $eof = false;

    /**
     * @var string|null Table name for chunk size calculation
     */
    protected $tableName = null;

    /**
     * @var string|null Schema name for chunk size calculation
     */
    protected $schemaName = null;

    /**
     * @var bool Whether to use adaptive chunk sizing
     */
    protected $adaptiveChunking = true;

    /**
     * Constructor
     * 
     * @param Postgres $connection Database connection
     * @param string $sql SELECT query to stream
     * @param int|null $chunkSize Rows per fetch (auto-calculated if null)
     * @param string|null $tableName Optional table name for size estimation
     * @param string|null $schemaName Optional schema name for size estimation
     */
    public function __construct(
        Postgres $connection,
        string $sql,
        ?int $chunkSize = null,
        ?string $tableName = null,
        ?string $schemaName = null,
        ?string $relationKind = null
    ) {
        $this->connection = $connection;
        $this->sql = trim($sql);
        $this->tableName = $tableName;
        $this->schemaName = $schemaName ?? $connection->_schema;
        $this->relationKind = $relationKind;

        // Get native pg connection resource from ADODB
        $this->pgResource = $connection->conn->_connectionID;

        // Generate unique cursor name
        $this->cursorName = 'cursor_' . uniqid(true);

        // Calculate or set chunk size
        if ($chunkSize !== null) {
            $this->chunkSize = max(50, min(50000, $chunkSize));
            $this->adaptiveChunking = false;
        } elseif ($tableName) {
            // Calculate based on table statistics
            $this->chunkSize = $this->calculateChunkSizeForTable();
        } else {
            // Start with 100 rows for queries (will adapt)
            $this->chunkSize = 100;
            $this->adaptiveChunking = true;
        }
    }

    /**
     * Open cursor and begin transaction
     * 
     * @return bool Success
     * @throws \RuntimeException On failure
     */
    public function open(): bool
    {
        if ($this->isOpen) {
            throw new \RuntimeException('Cursor already open');
        }

        try {
            // Begin transaction
            $result = pg_query($this->pgResource, 'BEGIN');
            if (!$result) {
                throw new \RuntimeException('Failed to BEGIN transaction: ' . pg_last_error($this->pgResource));
            }
            $this->inTransaction = true;

            // Declare cursor
            $declareSql = sprintf(
                'DECLARE %s NO SCROLL CURSOR FOR %s',
                pg_escape_identifier($this->pgResource, $this->cursorName),
                $this->sql
            );

            $result = pg_query($this->pgResource, $declareSql);
            if (!$result) {
                throw new \RuntimeException('Failed to DECLARE cursor: ' . pg_last_error($this->pgResource));
            }

            $this->isOpen = true;
            $this->eof = false;
            $this->totalRows = 0;
            $this->chunkNumber = 0;

            return true;
        } catch (\Exception $e) {
            $this->cleanup();
            throw $e;
        }
    }

    /**
     * Fetch next chunk of rows - returns pg result resource for zero-copy streaming
     * 
     * Caller must iterate the result with pg_fetch_row() and free it with pg_free_result().
     * Or use eachRow() for automatic handling.
     * 
     * @return resource|object|false PostgreSQL result resource, or false if EOF
     * @throws \RuntimeException On fetch error
     */
    public function fetchChunk()
    {
        if (!$this->isOpen) {
            throw new \RuntimeException('Cursor not open. Call open() first.');
        }

        if ($this->eof) {
            return false;
        }

        // Fetch rows
        $fetchSql = sprintf(
            'FETCH FORWARD %d FROM %s',
            $this->chunkSize,
            pg_escape_identifier($this->pgResource, $this->cursorName)
        );

        $result = pg_query($this->pgResource, $fetchSql);
        if (!$result) {
            throw new \RuntimeException('Failed to FETCH: ' . pg_last_error($this->pgResource));
        }

        $numRows = pg_num_rows($result);

        // Check for EOF
        if ($numRows === 0) {
            $this->eof = true;
            pg_free_result($result);
            return false;
        }

        // Extract field metadata on first fetch (lazy loading)
        if ($this->fields === null) {
            $this->fields = $this->extractFieldMetadata($result);
        }

        $this->chunkNumber++;

        // Return the result resource directly (caller must pg_free_result)
        return $result;
    }

    /**
     * Close cursor and commit transaction
     * 
     * @return bool Success
     */
    public function close(): bool
    {
        if (!$this->isOpen) {
            return true;
        }

        try {
            // Close cursor
            $closeSql = sprintf(
                'CLOSE %s',
                pg_escape_identifier($this->pgResource, $this->cursorName)
            );

            $result = pg_query($this->pgResource, $closeSql);
            if (!$result) {
                // Log error but continue to commit
                error_log('Failed to CLOSE cursor: ' . pg_last_error($this->pgResource));
            }

            // Commit transaction
            if ($this->inTransaction) {
                $result = pg_query($this->pgResource, 'COMMIT');
                if (!$result) {
                    error_log('Failed to COMMIT transaction: ' . pg_last_error($this->pgResource));
                }
                $this->inTransaction = false;
            }

            $this->isOpen = false;
            return true;
        } catch (\Exception $e) {
            error_log('Exception during close: ' . $e->getMessage());
            $this->cleanup();
            return false;
        }
    }

    /**
     * Iterator pattern: Process all rows via callback (streaming, no accumulation)
     * 
     * Processes each row immediately as it's fetched from the cursor,
     * without accumulating rows in memory.
     * 
     * Adaptive chunk sizing works because CursorReader controls the iteration loop.
     * Memory is measured between chunks to adjust chunk size dynamically.
     * 
     * @param callable $processRow function(array $row, int $rowNumber, array $fields): void
     * @param ?callable $processHeader function(array $fields): void
     * @return int Total rows processed
     * @throws \RuntimeException On error
     */
    public function eachRow(callable $processRow, ?callable $processHeader = null, $metadata = null): int
    {
        if (!$this->isOpen) {
            $this->open();
        }

        try {
            $rowNumber = 0;
            $memBefore = memory_get_usage(true);
            $rowsInChunk = 0;

            while (($result = $this->fetchChunk()) !== false) {
                $chunkStartRow = $rowNumber;

                if ($rowNumber === 0 && $processHeader !== null) {
                    // First chunk, send header metadata
                    $processHeader($this->fields, $metadata);
                }

                // Process each row as we fetch it (no accumulation)
                while ($row = pg_fetch_row($result)) {
                    $rowNumber++;
                    $processRow($row, $this->fields);
                    $this->totalRows++;
                    $rowsInChunk++;

                    // Safety check every 100 rows
                    /*
                    if ($rowNumber % 100 === 0) {
                        $this->checkMemoryLimit();
                    }
                    */
                }

                // Free result immediately after processing
                pg_free_result($result);

                // Adaptive chunk sizing for queries
                $memUsed = 0;
                $oldChunkSize = $this->chunkSize;

                if ($this->adaptiveChunking && $this->chunkNumber >= 1) {
                    $memAfter = memory_get_usage(true);
                    $memUsed = $memAfter - $memBefore;
                    $this->adjustChunkSize($memUsed);
                    $memBefore = $memAfter;
                }

                // Notify callback about chunk completion (for monitoring)
                /*
                if ($onChunkComplete !== null) {
                    $onChunkComplete(
                        $this->chunkNumber,
                        $rowsInChunk,
                        $memUsed,
                        $this->chunkSize
                    );
                }
                */

                $rowsInChunk = 0;
            }

            return $rowNumber;
        } finally {
            $this->close();
        }
    }

    /**
     * Iterator pattern: Process all rows via OutputFormatter
     * 
     * Adaptive chunk sizing works because CursorReader controls the iteration loop.
     * Memory is measured between chunks to adjust chunk size dynamically.
     * 
     * @param OutputFormatter $outputFormatter Output formatter to write rows to
     * @param array $metadata Optional metadata for header
     * @return int Total rows processed
     * @throws \RuntimeException On error
     */
    public function processRows(OutputFormatter $outputFormatter, $metadata = []): int
    {
        if (!$this->isOpen) {
            $this->open();
        }

        try {
            $rowNumber = 0;
            $memBefore = memory_get_usage(true);

            while (($result = $this->fetchChunk()) !== false) {

                if ($rowNumber === 0) {
                    // First chunk, send header metadata
                    $outputFormatter->writeHeader($this->fields, $metadata);
                }

                // Process each row as we fetch it (no accumulation)
                while ($row = pg_fetch_row($result)) {
                    $rowNumber++;
                    $this->totalRows++;
                    $outputFormatter->writeRow($row);

                    // Safety check every 100 rows
                    /*
                    if ($rowNumber % 100 === 0) {
                        $this->checkMemoryLimit();
                    }
                    */
                }

                // Free result immediately after processing
                pg_free_result($result);

                // Adaptive chunk sizing for queries
                if ($this->adaptiveChunking && $this->chunkNumber >= 1) {
                    $memAfter = memory_get_usage(true);
                    $memUsed = $memAfter - $memBefore;
                    $this->adjustChunkSize($memUsed);
                    $memBefore = $memAfter;
                }
            }

            if ($rowNumber > 0) {
                $outputFormatter->writeFooter();
            }

            return $rowNumber;
        } finally {
            $this->close();
        }
    }

    /**
     * Manual iteration mode: Get next chunk as result resource
     * 
     * Note: Adaptive chunk sizing does NOT work in manual mode because
     * CursorReader cannot measure memory usage during your pg_fetch_row() loop.
     * If you need adaptive sizing, use eachRow() instead.
     * 
     * Example:
     *   $reader->open();
     *   while (($result = $reader->fetchChunk()) !== false) {
     *       while ($row = pg_fetch_row($result)) {
     *           // Process $row
     *       }
     *       pg_free_result($result);
     *   }
     *   $reader->close();
     * 
     * For streaming with adaptive sizing, use eachRow() instead.
     * 
     * @return resource|object|false Result resource or false if EOF
     */
    public function nextChunk()
    {
        return $this->fetchChunk();
    }

    /**
     * Get field metadata
     * 
     * @return array|null Field metadata array or null if not fetched yet
     */
    public function getFields(): ?array
    {
        return $this->fields;
    }

    /**
     * Get total rows processed so far
     * 
     * @return int Total rows
     */
    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    /**
     * Get current chunk size
     * 
     * @return int Chunk size
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * Set chunk size (if not already open)
     * 
     * @param int $size New chunk size (50-50000)
     * @return void
     * @throws \RuntimeException If cursor already open
     */
    public function setChunkSize(int $size): void
    {
        if ($this->isOpen) {
            throw new \RuntimeException('Cannot change chunk size while cursor is open');
        }

        $this->chunkSize = max(50, min(50000, $size));
    }

    /**
     * Extract field metadata from result resource
     * 
     * @param resource|object $result PostgreSQL result resource (resource in PHP < 8.1, PgSql\Result in PHP >= 8.1)
     * @return array Field metadata
     */
    protected function extractFieldMetadata($result): array
    {
        $numFields = pg_num_fields($result);
        $fields = [];

        for ($i = 0; $i < $numFields; $i++) {
            $fields[] = [
                'name' => pg_field_name($result, $i),
                'type' => pg_field_type($result, $i),
                'type_oid' => pg_field_type_oid($result, $i),
                'size' => pg_field_size($result, $i),
                'num' => $i,
            ];
        }

        return $fields;
    }

    /**
     * Calculate optimal chunk size for table
     * 
     * @return int Calculated chunk size
     */
    protected function calculateChunkSizeForTable(): int
    {
        if (!$this->tableName) {
            return 100;
        }

        try {
            $result = ChunkCalculator::calculate(
                $this->connection,
                $this->tableName,
                $this->schemaName,
                null,
                $this->relationKind
            );

            error_log(sprintf(
                'Calculated chunk size: %d (estimated: %.2f MB)',
                $result['chunk_size'],
                $result['chunk_size'] * $result['max_row_bytes'] / 1024 / 1024
            ));

            return $result['chunk_size'];
        } catch (\Exception $e) {
            error_log('Failed to calculate chunk size: ' . $e->getMessage());
            return 100;
        }
    }

    /**
     * Adjust chunk size based on actual memory usage (adaptive sizing)
     * 
     * @param int $memUsed Bytes used by last chunk
     * @return void
     */
    protected function adjustChunkSize(int $memUsed): void
    {
        // Target: 5-10 MB per chunk
        $targetMin = 5 * 1024 * 1024;
        $targetMax = 10 * 1024 * 1024;

        $oldSize = $this->chunkSize;

        if ($memUsed > $targetMax) {
            // Too much memory, decrease by 30%
            $this->chunkSize = (int) ceil($this->chunkSize * 0.7);
        } elseif ($memUsed < $targetMin && $this->chunkSize < 10000) {
            // Too little memory, increase by 30%
            $this->chunkSize = (int) ceil($this->chunkSize * 1.3);
        }

        // Apply hard limits
        $this->chunkSize = max(50, min(50000, $this->chunkSize));

        // Log adjustment (optional, for debugging)
        if ($oldSize !== $this->chunkSize) {
            error_log(sprintf(
                'Adjusted chunk size: %d -> %d (mem used: %.2f MB)',
                $oldSize,
                $this->chunkSize,
                $memUsed / 1024 / 1024
            ));
        }
    }

    /**
     * Check if approaching memory limit and throw exception if unsafe
     * 
     * @return void
     * @throws \RuntimeException If approaching memory limit
     */
    protected function checkMemoryLimit(): void
    {
        $current = memory_get_usage(true);
        $limit = ChunkCalculator::parseMemoryLimit(ini_get('memory_limit'));

        if ($limit > 0 && $current > $limit * 0.8) {
            throw new \RuntimeException(sprintf(
                'Approaching memory limit: %d MB / %d MB (%.1f%%)',
                $current / 1024 / 1024,
                $limit / 1024 / 1024,
                ($current / $limit) * 100
            ));
        }
    }

    /**
     * Cleanup on error (rollback transaction, close cursor)
     * 
     * @return void
     */
    protected function cleanup(): void
    {
        if ($this->inTransaction) {
            try {
                pg_query($this->pgResource, 'ROLLBACK');
            } catch (\Exception $e) {
                error_log('Failed to ROLLBACK: ' . $e->getMessage());
            }
            $this->inTransaction = false;
        }

        $this->isOpen = false;
    }

    /**
     * Destructor - ensure cleanup
     */
    public function __destruct()
    {
        if ($this->isOpen) {
            $this->close();
        }
    }
}
