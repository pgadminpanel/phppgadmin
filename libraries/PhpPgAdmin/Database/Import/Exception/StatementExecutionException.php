<?php

namespace PhpPgAdmin\Database\Import\Exception;

use Exception;

/**
 * Thrown when a statement execution fails.
 */
class StatementExecutionException extends ImportException
{
    /** @var string */
    private $statement;
    /** @var int */
    private $errorCode;
    /** @var string */
    private $errorMessage;

    public function __construct(
        string $statement,
        int $errorCode,
        string $errorMessage = '',
        ?Exception $previous = null
    ) {
        $this->statement = $statement;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;

        $msg = 'Statement execution failed with error ' . $errorCode;
        if ($errorMessage !== '') {
            $msg .= ': ' . $errorMessage;
        }
        $msg .= ' Statement: ' . substr($statement, 0, 200);

        parent::__construct($msg, $errorCode, $previous);
    }

    public function getStatement(): string
    {
        return $this->statement;
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
