<?php

namespace PhpPgAdmin\Database\Import;

/**
 * Collects execution logs and aggregates streaming stats.
 */
class LogCollector
{
    /** @var array */
    private $logs = [];
    /** @var int */
    private $maxLogs;
    /** @var bool */
    private $streamingMode;
    /** @var array */
    private $streamingStats = [
        'executed' => 0,
        'skipped' => 0,
        'deferred' => 0,
        'queued_ownership' => 0,
        'queued_rights' => 0,
        'blocked' => 0,
        'truncated' => 0,
        'errors' => 0,
    ];

    public function __construct(bool $streamingMode = false, int $maxLogs = 200)
    {
        $this->streamingMode = $streamingMode;
        $this->maxLogs = $maxLogs;
    }

    public function append(ExecutionLog $log): void
    {
        // Always update stats, regardless of whether we keep detailed logs.
        $this->updateStreamingStats($log);

        // In streaming mode, keep only non-success logs to avoid per-statement noise,
        // but still preserve important events (errors, warnings, truncates, etc).
        if ($this->streamingMode) {
            $type = $log->toArray()['type'] ?? null;
            if ($type === 'success') {
                return;
            }
        }

        $this->logs[] = $log;
        if (count($this->logs) > $this->maxLogs) {
            array_shift($this->logs);
        }
    }

    public function addSuccess(string $message, ?string $statement = null): void
    {
        $this->append(ExecutionLog::success($message, $statement));
    }

    public function addFatal(string $message, ?string $statement = null, ?int $errorCode = null): void
    {
        $this->append(ExecutionLog::fatal($message, $statement, $errorCode));
    }

    public function addError(string $message, ?string $statement = null, ?int $errorCode = null): void
    {
        $this->append(ExecutionLog::error($message, $statement, $errorCode));
    }

    public function addInfo(string $message): void
    {
        $this->append(ExecutionLog::info($message));
    }

    public function addWarning(string $message): void
    {
        $this->append(ExecutionLog::warning($message));
    }

    public function addSkipped(string $message, ?string $statement = null, ?string $reason = null, ?string $category = null): void
    {
        $this->append(ExecutionLog::skipped($message, $statement, $reason, $category));
    }

    public function addDeferred(string $message, ?string $statement = null): void
    {
        $this->append(ExecutionLog::deferred($message, $statement));
    }

    public function addQueuedOwnership(string $message, ?string $statement = null): void
    {
        $this->append(ExecutionLog::queuedOwnership($message, $statement));
    }

    public function addQueuedRights(string $message, ?string $statement = null): void
    {
        $this->append(ExecutionLog::queuedRights($message, $statement));
    }

    public function addBlocked(string $message, ?string $statement = null, ?string $reason = null): void
    {
        $this->append(ExecutionLog::blocked($message, $statement, $reason));
    }

    public function addTruncated(string $message, string $table): void
    {
        $this->append(ExecutionLog::truncated($message, $table));
    }

    public function getLogs(): array
    {
        return array_map(function (ExecutionLog $log) {
            return $log->toArray();
        }, $this->logs);
    }

    public function getLogsWithSummary(): array
    {
        $logs = $this->getLogs();
        if ($this->streamingMode && array_sum($this->streamingStats) > 0) {
            $logs[] = ExecutionLog::streamingSummary($this->streamingStats)->toArray();
        }
        return $logs;
    }

    public function getStreamingStats(): array
    {
        return $this->streamingStats;
    }

    public function getErrorCount(): int
    {
        return $this->streamingStats['errors'];
    }

    private function updateStreamingStats(ExecutionLog $log): void
    {
        $type = $log->toArray()['type'] ?? null;
        switch ($type) {
            case 'success':
                $this->streamingStats['executed']++;
                break;
            case 'skipped':
                $this->streamingStats['skipped']++;
                break;
            case 'deferred':
                $this->streamingStats['deferred']++;
                break;
            case 'queued_ownership':
                $this->streamingStats['queued_ownership']++;
                break;
            case 'queued_rights':
                $this->streamingStats['queued_rights']++;
                break;
            case 'blocked':
                $this->streamingStats['blocked']++;
                break;
            case 'truncated':
                $this->streamingStats['truncated']++;
                break;
            case 'error':
                $this->streamingStats['errors']++;
                break;
        }
    }
}
