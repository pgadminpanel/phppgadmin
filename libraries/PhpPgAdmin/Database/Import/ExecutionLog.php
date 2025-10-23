<?php

namespace PhpPgAdmin\Database\Import;

/**
 * Immutable execution log entry value object.
 */
class ExecutionLog
{
    /** @var int */
    private $timestamp;
    /** @var string */
    private $type;
    /** @var string */
    private $message;
    /** @var string|null */
    private $statement;
    /** @var string|null */
    private $category;
    /** @var string|null */
    private $reason;
    /** @var int|null */
    private $errorCode;
    /** @var string|null */
    private $table;
    /** @var array|null */
    private $stats;

    private function __construct(
        string $type,
        string $message,
        ?string $statement = null,
        ?string $category = null,
        ?string $reason = null,
        ?int $errorCode = null,
        ?string $table = null,
        ?array $stats = null
    ) {
        $this->timestamp = (int) (microtime(true) * 1000);
        $this->type = $type;
        $this->message = $message;
        $this->statement = $statement !== null ? substr($statement, 0, 200) : null;
        $this->category = $category;
        $this->reason = $reason;
        $this->errorCode = $errorCode;
        $this->table = $table;
        $this->stats = $stats;
    }

    public static function success(string $message, ?string $statement = null): self
    {
        return new self('success', $message, $statement);
    }

    public static function fatal(string $message, ?string $statement = null, ?int $errorCode = null): self
    {
        return new self('fatal', $message, $statement, null, null, $errorCode);
    }

    public static function error(string $message, ?string $statement = null, ?int $errorCode = null): self
    {
        return new self('error', $message, $statement, null, null, $errorCode);
    }

    public static function info(string $message): self
    {
        return new self('info', $message);
    }

    public static function warning(string $message): self
    {
        return new self('warning', $message);
    }

    public static function skipped(string $message, ?string $statement = null, ?string $reason = null, ?string $category = null): self
    {
        return new self('skipped', $message, $statement, $category, $reason);
    }

    public static function deferred(string $message, ?string $statement = null): self
    {
        return new self('deferred', $message, $statement);
    }

    public static function queuedOwnership(string $message, ?string $statement = null): self
    {
        return new self('queued_ownership', $message, $statement);
    }

    public static function queuedRights(string $message, ?string $statement = null): self
    {
        return new self('queued_rights', $message, $statement);
    }

    public static function blocked(string $message, ?string $statement = null, ?string $reason = null): self
    {
        return new self('blocked', $message, $statement, null, $reason);
    }

    public static function truncated(string $message, string $table): self
    {
        return new self('truncated', $message, null, null, null, null, $table);
    }

    public static function streamingSummary(array $stats): self
    {
        return new self('streaming_summary', 'Streaming batch summary', null, null, null, null, null, $stats);
    }

    public function toArray(): array
    {
        $result = [
            'time' => $this->timestamp,
            'type' => $this->type,
            'message' => $this->message,
        ];

        if ($this->statement !== null) {
            $result['statement'] = $this->statement;
        }
        if ($this->category !== null) {
            $result['category'] = $this->category;
        }
        if ($this->reason !== null) {
            $result['reason'] = $this->reason;
        }
        if ($this->errorCode !== null) {
            $result['error_code'] = $this->errorCode;
        }
        if ($this->table !== null) {
            $result['table'] = $this->table;
        }
        if ($this->stats !== null) {
            $result = array_merge($result, $this->stats);
        }

        return $result;
    }
}
