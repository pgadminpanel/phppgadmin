<?php

namespace PhpPgAdmin\Database;

use ADORecordSet;

/**
 * Adapter to present raw pg_* results with ADODB-compatible interface
 * This allows unified rendering logic for both result types
 */
class PostgresResultAdapter extends ADORecordSet
{
    private $pgResult;
    private $currentRow = null;
    private $currentRowIndex = -1;
    private $numRows;
    public $fields = array();
    public $EOF = true;

    public function __construct($pgResult)
    {
        $this->pgResult = $pgResult;
        $this->numRows = pg_num_rows($pgResult);
        // Fetch first row if available
        if ($this->numRows > 0) {
            $this->currentRow = pg_fetch_row($pgResult, 0);
            $this->currentRowIndex = 0;
            $this->fields = $this->currentRow;
            $this->EOF = false;
        }
    }

    /**
     * Get field information similar to ADODB recordset
     */
    public function fetchField($fieldIndex)
    {
        $obj = new \stdClass();
        $obj->name = pg_field_name($this->pgResult, $fieldIndex);
        $obj->type = pg_field_type($this->pgResult, $fieldIndex);
        return $obj;
    }

    /**
     * Move to the next row
     */
    public function moveNext(): bool
    {
        $nextIndex = $this->currentRowIndex + 1;
        if ($nextIndex < $this->numRows) {
            $this->currentRow = pg_fetch_row($this->pgResult, $nextIndex);
            $this->currentRowIndex = $nextIndex;
            $this->fields = $this->currentRow;
            $this->EOF = false;
            return true;
        } else {
            $this->EOF = true;
            return false;
        }
    }
}
