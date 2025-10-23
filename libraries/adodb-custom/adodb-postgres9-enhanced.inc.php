<?php
/**
 * ADOdb PostgreSQL 9+ Enhanced Driver with Field Metadata Caching
 *
 * Extends the standard ADODB PostgreSQL 9 driver to cache field metadata
 * (table OID, attribute number) from query result sets using native
 * PostgreSQL functions pg_field_table() and pg_field_num().
 *
 * @package ADOdb
 * @author phpPgAdmin Project
 * @license BSD-3-Clause
 */

// Security - ensure this file is only included from ADODB
if (!defined('ADODB_DIR')) {
    die('ADOdb not initialized');
}

// Load the base postgres9 driver
require_once ADODB_DIR . "/drivers/adodb-postgres9.inc.php";

/**
 * Enhanced PostgreSQL 9+ Driver
 *
 * Extends ADODB_postgres9 with metadata caching capabilities
 */
class ADODB_postgres9_enhanced extends ADODB_postgres9
{
    public $databaseType = 'postgres9_enhanced';
}

/**
 * Enhanced PostgreSQL 9+ RecordSet with Field Metadata Caching
 *
 * Provides cached access to field metadata including:
 * - Table OID (pg_field_table)
 * - Attribute number (pg_field_num)
 * - Field type (pg_field_type)
 *
 * This allows applications to determine the source table and column
 * attribution directly from query results without separate metadata queries.
 */
class ADORecordSet_postgres9_enhanced extends ADORecordSet_postgres9
{
    //public $databaseType = "postgres9_enhanced";

    /**
     * @var bool|mixed Native PostgreSQL result resource
     */
    private $_pgResult = false;

    /**
     * @var array Cache of field metadata indexed by field number
     * Structure: [
     *     $i => [
     *         'name'      => string (field name),
     *         'type'      => string (PostgreSQL type name),
     *         'table_oid' => int|false (table OID or false if not from table),
     *         'attnum'    => int|false (attribute number or false if not from table),
     *     ],
     *     ...
     * ]
     */
    private $_fieldMeta = [];

    /**
     * Initialize the recordset
     *
     * Stores the native PostgreSQL result resource for later metadata introspection
     */
    function _initRS()
    {
        // Store the native result resource for metadata caching
        $this->_pgResult = $this->_queryID;

        // Call parent initialization (handles blob detection, row count, field count)
        parent::_initRS();
    }

    /**
     * Get the table OID for a specific field
     *
     * @param int $i Field index (0-based)
     * @return int|false Table OID if field belongs to a table, false otherwise
     */
    function FieldTableOID($i)
    {
        $this->_loadFieldMeta($i);
        return $this->_fieldMeta[$i]['table_oid'] ?? false;
    }

    /**
     * Get the attribute number for a specific field
     *
     * @param int $i Field index (0-based)
     * @return int|false Attribute number (column ordinal in source table), false if not from table
     */
    function FieldAttnum($i)
    {
        $this->_loadFieldMeta($i);
        return $this->_fieldMeta[$i]['attnum'] ?? false;
    }

    /**
     * Get the PostgreSQL type name for a specific field
     *
     * @param int $i Field index (0-based)
     * @return string PostgreSQL type name (e.g., 'integer', 'varchar', 'bytea')
     */
    function FieldType($i)
    {
        $this->_loadFieldMeta($i);
        return $this->_fieldMeta[$i]['type'] ?? '';
    }

    /**
     * Lazy-load field metadata for a specific field index
     *
     * Metadata is cached in _fieldMeta array to avoid repeated calls
     * to PostgreSQL native functions.
     *
     * @param int $i Field index (0-based)
     */
    private function _loadFieldMeta($i)
    {
        // Skip if already cached
        if (isset($this->_fieldMeta[$i])) {
            return;
        }

        // Validate field index
        if ($i < 0 || $i >= $this->_numOfFields || !$this->_pgResult) {
            $this->_fieldMeta[$i] = [
                'name' => '',
                'type' => '',
                'table_oid' => false,
                'attnum' => false,
            ];
            return;
        }

        // Cache metadata from native PostgreSQL functions
        $this->_fieldMeta[$i] = [
            'name' => @pg_field_name($this->_pgResult, $i) ?: '',
            'type' => @pg_field_type($this->_pgResult, $i) ?: '',
            'table_oid' => @pg_field_table($this->_pgResult, $i) ?: false,
            'attnum' => @pg_field_num($this->_pgResult, $i) ?: false,
        ];
    }
}

/**
 * Enhanced PostgreSQL 9+ Associative RecordSet with Field Metadata Caching
 *
 * Provides the same field metadata caching as ADORecordSet_postgres9Enhanced,
 * but returns associative arrays instead of numeric arrays.
 */
class ADORecordSet_assoc_postgres9_enhanced extends ADORecordSet_assoc_postgres9
{
    //public $databaseType = "postgres9_enhanced";

    /**
     * @var bool|mixed Native PostgreSQL result resource
     */
    private $_pgResult = false;

    /**
     * @var array Cache of field metadata indexed by field number
     * Structure: [
     *     $i => [
     *         'name'      => string (field name),
     *         'type'      => string (PostgreSQL type name),
     *         'table_oid' => int|false (table OID or false if not from table),
     *         'attnum'    => int|false (attribute number or false if not from table),
     *     ],
     *     ...
     * ]
     */
    private $_fieldMeta = [];

    /**
     * Initialize the recordset
     *
     * Stores the native PostgreSQL result resource for later metadata introspection
     */
    function _initRS()
    {
        // Store the native result resource for metadata caching
        $this->_pgResult = $this->_queryID;

        // Call parent initialization (handles blob detection, row count, field count)
        parent::_initRS();
    }

    /**
     * Get the table OID for a specific field
     *
     * @param int $i Field index (0-based)
     * @return int|false Table OID if field belongs to a table, false otherwise
     */
    function FieldTableOID($i)
    {
        $this->_loadFieldMeta($i);
        return $this->_fieldMeta[$i]['table_oid'] ?? false;
    }

    /**
     * Get the attribute number for a specific field
     *
     * @param int $i Field index (0-based)
     * @return int|false Attribute number (column ordinal in source table), false if not from table
     */
    function FieldAttnum($i)
    {
        $this->_loadFieldMeta($i);
        return $this->_fieldMeta[$i]['attnum'] ?? false;
    }

    /**
     * Get the PostgreSQL type name for a specific field
     *
     * @param int $i Field index (0-based)
     * @return string PostgreSQL type name (e.g., 'integer', 'varchar', 'bytea')
     */
    function FieldType($i)
    {
        $this->_loadFieldMeta($i);
        return $this->_fieldMeta[$i]['type'] ?? '';
    }

    /**
     * Lazy-load field metadata for a specific field index
     *
     * Metadata is cached in _fieldMeta array to avoid repeated calls
     * to PostgreSQL native functions.
     *
     * @param int $i Field index (0-based)
     */
    private function _loadFieldMeta($i)
    {
        // Skip if already cached
        if (isset($this->_fieldMeta[$i])) {
            return;
        }

        // Validate field index
        if ($i < 0 || $i >= $this->_numOfFields || !$this->_pgResult) {
            $this->_fieldMeta[$i] = [
                'name' => '',
                'type' => '',
                'table_oid' => false,
                'attnum' => false,
            ];
            return;
        }

        // Cache metadata from native PostgreSQL functions
        $this->_fieldMeta[$i] = [
            'name' => @pg_field_name($this->_pgResult, $i) ?: '',
            'type' => @pg_field_type($this->_pgResult, $i) ?: '',
            'table_oid' => @pg_field_table($this->_pgResult, $i) ?: false,
            'attnum' => @pg_field_num($this->_pgResult, $i) ?: false,
        ];
    }
}
