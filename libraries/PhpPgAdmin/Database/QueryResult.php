<?php

namespace PhpPgAdmin\Database;

use ADORecordSet;
use ADORecordSet_empty;

/**
 * Unified wrapper for query results from both ADODB and raw pg_* calls
 */
class QueryResult
{
    public $isSuccess = true;
    public $isCopy = false;
    public $errorMsg = '';
    private $adoRecordSet = null;
    private $pgResult = null;

    /**
     * Create wrapper from ADODB recordset
     */
    public static function fromADORecordSet($recordSet, $errorMsg = '')
    {
        $result = new self();
        $result->adoRecordSet = $recordSet ?: new ADORecordSet_empty();
        $result->isSuccess = $recordSet !== false;
        $result->errorMsg = $errorMsg;
        return $result;
    }

    /**
     * Create wrapper from raw pg_* result resource
     */
    public static function fromPgResult($pgResult, $errorMsg = '')
    {
        $result = new self();
        $result->pgResult = $pgResult;
        $result->isSuccess = $pgResult !== false;
        $result->errorMsg = $errorMsg;
        if ($pgResult !== false) {
            $result->isCopy = (pg_result_status($pgResult) == 4); // 4 == PGSQL_COPY_FROM
        }
        return $result;
    }

    /**
     * Get total record count
     */
    public function recordCount()
    {
        if ($this->adoRecordSet !== null && is_object($this->adoRecordSet)) {
            return $this->adoRecordSet->recordCount();
        }
        if ($this->pgResult !== false) {
            return pg_num_rows($this->pgResult);
        }
        return 0;
    }

    /**
     * Get number of affected rows
     */
    public function affectedRows()
    {
        if ($this->adoRecordSet !== null && is_object($this->adoRecordSet)) {
            if (is_callable([$this->adoRecordSet, 'Affected_Rows'])) {
                return $this->adoRecordSet->Affected_Rows();
            }
            return 0;
        }
        if ($this->pgResult !== false) {
            return pg_affected_rows($this->pgResult);
        }
        return 0;
    }

    /**
     * Check if result has tuples (SELECT results)
     */
    public function hasTuples()
    {
        if ($this->adoRecordSet !== null && is_object($this->adoRecordSet)) {
            return $this->adoRecordSet->recordCount() > 0;
        }
        if ($this->pgResult !== false) {
            return pg_result_status($this->pgResult) == PGSQL_TUPLES_OK;
        }
        return false;
    }

    /**
     * Get raw ADODB recordset (for iteration)
     */
    public function getADORecordSet()
    {
        return $this->adoRecordSet;
    }

    /**
     * Get raw pg_* result resource
     */
    public function getPgResult()
    {
        return $this->pgResult;
    }

    /**
     * Get ADODB-compatible adapter for pg_* results
     * Returns the ADODB recordset directly if available,
     * otherwise wraps pg_* result in PostgresResultAdapter
     * @return ADORecordSet|null The ADODB-compatible recordset or null if no results
     */
    public function getAdapterForResults()
    {
        if ($this->adoRecordSet !== null && is_object($this->adoRecordSet)) {
            return $this->adoRecordSet;
        }
        if ($this->pgResult !== false) {
            return new PostgresResultAdapter($this->pgResult);
        }
        return null;
    }
}
