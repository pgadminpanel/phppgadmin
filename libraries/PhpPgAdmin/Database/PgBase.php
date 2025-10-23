<?php


namespace PhpPgAdmin\Database;



use ADOConnection;
use ADORecordSet;
use PhpPgAdmin\Core\AppContext;

abstract class PgBase extends AppContext
{

	/**
	 * @var ADOConnection
	 */
	var $conn;

	// The backend platform.  Set to UNKNOWN by default.
	var $platform = 'UNKNOWN';

	/**
	 * Current schema name.
	 * @var string|null
	 */
	var $_schema = null;

	/**
	 * Base constructor
	 * @param $conn ADOConnection The connection object
	 */
	function __construct(ADOConnection $conn)
	{
		$this->conn = $conn;
		//$conn->LogSQL(true);
	}

	/**
	 * Turns on or off query debugging
	 * @param $debug True to turn on debugging, false otherwise
	 */
	function setDebug($debug)
	{
		$this->conn->debug = $debug;
	}

	/**
	 * Cleans (escapes) a string
	 * @param string|null $str The string to clean, by reference
	 * @return string The cleaned string
	 */
	function clean(&$str)
	{
		if ($str === null)
			return null;
		$str = str_replace("\r\n", "\n", $str);
		$str = pg_escape_string($this->conn->_connectionID, $str);
		return $str;
	}

	/**
	 * Cleans (escapes) an object name (eg. table, field)
	 * @param string|null $str The string to clean, by reference
	 * @return string The cleaned string
	 */
	function fieldClean(&$str)
	{
		if ($str === null)
			return null;
		$str = str_replace('"', '""', $str);
		return $str;
	}

	/**
	 * Cleans (escapes) an array of field names
	 * @param array $arr The array to clean, by reference
	 * @return array The cleaned array
	 */
	function fieldArrayClean(&$arr)
	{
		foreach ($arr as $k => $v) {
			if ($v === null)
				continue;
			$arr[$k] = str_replace('"', '""', $v);
		}
		return $arr;
	}

	/**
	 * Cleans (escapes) an array
	 * @param array $arr The array to clean, by reference
	 * @return array The cleaned array
	 */
	function arrayClean(&$arr)
	{
		foreach ($arr as $k => $v) {
			if ($v === null)
				continue;
			$arr[$k] = pg_escape_string($this->conn->_connectionID, $v);
		}
		return $arr;
	}

	/**
	 * Cleans (escapes) a string
	 * @param string|null $str The string to clean, by value
	 * @return string The cleaned string
	 */
	function cleanVal($str)
	{
		if ($str === null)
			return null;
		$str = str_replace("\r\n", "\n", $str);
		return pg_escape_string($this->conn->_connectionID, $str);
	}
	/**
	 * Cleans (escapes) an object name (eg. table, field)
	 * @param string|null $str The string to clean, by value
	 * @return string The cleaned string
	 */
	function cleanField($str)
	{
		if ($str === null)
			return null;
		return str_replace('"', '""', $str);
	}

	/**
	 * Escapes bytea data for display on the screen
	 * @param string $data The bytea data
	 * @return string Data formatted for on-screen display
	 */
	function escapeByteaHtml($data)
	{
		return htmlentities($data, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Escape a identifier for insertion into a text field
	 * It escapes a identifier (e.g. table, field names) for querying the
	 * database.
	 * It returns an escaped identifier string for PostgreSQL server.
	 * It adds double quotes before and after data.
	 * @param string $id The identifier to escape
	 * @return string The escaped identifier
	 */
	public function escapeIdentifier($id = ''): string
	{
		return pg_escape_identifier($this->conn->_connectionID, $id);
	}

	/**
	 * Quotes an identifier (table, field, etc.)
	 * @param string $id The identifier to quote
	 * @return string The quoted identifier
	 */
	public function quoteIdentifier($id = ''): string
	{
		return pg_escape_identifier($this->conn->_connectionID, $id);
	}

	/**
	 * Escapes a literal value
	 * @param string $literal The literal to escape
	 * @return string The escaped literal
	 */
	public function escapeLiteral($literal = ''): string
	{
		return pg_escape_literal($this->conn->_connectionID, $literal);
	}

	/**
	 * Escapes a string value
	 * @param string $string The string to escape
	 * @return string The escaped string
	 */
	public function escapeString($string = ''): string
	{
		return pg_escape_string($this->conn->_connectionID, $string);
	}

	/**
	 * Escapes bytea data for storage in the database
	 * @param string $str The bytea data
	 * @return string The escaped bytea data
	 */
	public function escapeBytea($str = ''): string
	{
		return pg_escape_bytea($this->conn->_connectionID, $str);
	}

	public $lastQueryTime = null;

	/**
	 * Executes a query on the underlying connection
	 * @param string $sql The SQL query to execute
	 * @return int error number
	 */
	function execute($sql)
	{
		$start = microtime(true);

		// Execute the statement
		$this->conn->Execute($sql);

		$this->lastQueryTime = microtime(true) - $start;

		// Return error code
		return $this->conn->ErrorNo();
	}

	function affectedRows()
	{
		return $this->conn->Affected_Rows();
	}

	/**
	 * Closes the connection the database class
	 * relies on.
	 */
	function close()
	{
		$this->conn->close();
	}

	/**
	 * Retrieves a ResultSet from a query
	 * @param string $sql The SQL statement to be executed
	 * @return ADORecordSet|int A recordset
	 */
	function selectSet($sql)
	{

		$start = microtime(true);

		// Execute the statement
		$rs = $this->conn->Execute($sql);

		$this->lastQueryTime = microtime(true) - $start;

		if (!$rs)
			return $this->conn->ErrorNo();

		return $rs;
	}

	/**
	 * Retrieves a single value from a query
	 *
	 * @@ assumes that the query will return only one row - returns field value in the first row
	 *
	 * @param string $sql The SQL statement to be executed
	 * @param string $field The field name to be returned
	 * @return mixed A single field value
	 * @return -1 No rows were found
	 */
	function selectField($sql, $field)
	{
		$start = microtime(true);

		// Execute the statement
		$rs = $this->conn->Execute($sql);

		$this->lastQueryTime = microtime(true) - $start;

		// If failure, or no rows returned, return error value
		if (!$rs)
			return $this->conn->ErrorNo();
		elseif ($rs->RecordCount() == 0)
			return -1;

		return $rs->fields[$field];
	}

	/**
	 * Delete from the database
	 * @param string $table The name of the table
	 * @param array $conditions (array) A map of field names to conditions
	 * @param string $schema (optional) The table's schema
	 * @return int 0 success
	 * @return int -1 on referential integrity violation
	 * @return int -2 on no rows deleted
	 */
	function delete($table, $conditions, $schema = '')
	{
		$this->fieldClean($table);

		if (!empty($schema)) {
			$this->fieldClean($schema);
			$schema = "\"{$schema}\".";
		}

		// Build clause
		$sql = '';
		foreach ($conditions as $key => $value) {
			$this->clean($key);
			$this->clean($value);
			if ($sql)
				$sql .= " AND \"{$key}\"='{$value}'";
			else
				$sql = "DELETE FROM {$schema}\"{$table}\" WHERE \"{$key}\"='{$value}'";
		}

		// Check for failures
		if (!$this->conn->Execute($sql)) {
			// Check for referential integrity failure
			if (stristr($this->conn->ErrorMsg(), 'referential'))
				return -1;
		}

		// Check for no rows modified
		if ($this->conn->Affected_Rows() == 0)
			return -2;

		return $this->conn->ErrorNo();
	}

	/**
	 * Insert a set of values into the database
	 * @param string $table The table to insert into
	 * @param array $vars (array) A mapping of the field names to the values to be inserted
	 * @return int 0 success
	 * @return int -1 if a unique constraint is violated
	 * @return int -2 if a referential constraint is violated
	 */
	function insert($table, $vars)
	{
		$this->fieldClean($table);

		// Build clause
		if (sizeof($vars) > 0) {
			$fields = '';
			$values = '';
			foreach ($vars as $key => $value) {
				$this->clean($key);
				$this->clean($value);

				if ($fields)
					$fields .= ", \"{$key}\"";
				else
					$fields = "INSERT INTO \"{$table}\" (\"{$key}\"";

				if ($values)
					$values .= ", '{$value}'";
				else
					$values = ") VALUES ('{$value}'";
			}
			$sql = $fields . $values . ')';
		}

		// Check for failures
		if (!$this->conn->Execute($sql)) {
			// Check for unique constraint failure
			if (stristr($this->conn->ErrorMsg(), 'unique'))
				return -1;
			// Check for referential integrity failure
			elseif (stristr($this->conn->ErrorMsg(), 'referential'))
				return -2;
		}

		return $this->conn->ErrorNo();
	}

	/**
	 * Update a row in the database
	 * @param string $table The table that is to be updated
	 * @param array $vars (array) A mapping of the field names to the values to be updated
	 * @param array $where (array) A mapping of field names to values for the where clause
	 * @param array $nulls (array, optional) An array of fields to be set null
	 * @return int 0 success
	 * @return int -1 if a unique constraint is violated
	 * @return int -2 if a referential constraint is violated
	 * @return int -3 on no rows deleted
	 */
	function update($table, $vars, $where, $nulls = [])
	{
		$this->fieldClean($table);

		$setClause = '';
		$whereClause = '';

		// Populate the syntax arrays
		foreach ($vars as $key => $value) {
			$this->fieldClean($key);
			$this->clean($value);
			if ($setClause)
				$setClause .= ", \"{$key}\"='{$value}'";
			else
				$setClause = "UPDATE \"{$table}\" SET \"{$key}\"='{$value}'";
		}

		foreach ($nulls as $value) {
			$this->fieldClean($value);
			if ($setClause)
				$setClause .= ", \"{$value}\"=NULL";
			else
				$setClause = "UPDATE \"{$table}\" SET \"{$value}\"=NULL";
		}

		foreach ($where as $key => $value) {
			$this->fieldClean($key);
			$this->clean($value);
			if ($whereClause)
				$whereClause .= " AND \"{$key}\"='{$value}'";
			else
				$whereClause = " WHERE \"{$key}\"='{$value}'";
		}

		// Check for failures
		if (!$this->conn->Execute($setClause . $whereClause)) {
			// Check for unique constraint failure
			if (stristr($this->conn->ErrorMsg(), 'unique'))
				return -1;
			// Check for referential integrity failure
			elseif (stristr($this->conn->ErrorMsg(), 'referential'))
				return -2;
		}

		// Check for no rows modified
		if ($this->conn->Affected_Rows() == 0)
			return -3;

		return $this->conn->ErrorNo();
	}

	/**
	 * Begin a transaction
	 * @return int 0 success
	 */
	function beginTransaction()
	{
		return $this->conn->BeginTrans() ? 0 : -1;
	}

	/**
	 * End a transaction
	 * @return int 0 success
	 */
	function endTransaction()
	{
		return $this->conn->CommitTrans() ? 0 : -1;
	}
	/**
	 * Commit a transaction
	 * @return int 0 success
	 */
	function commitTransaction()
	{
		return $this->conn->CommitTrans() ? 0 : -1;
	}

	/**
	 * Roll back a transaction
	 * @return int 0 success
	 */
	function rollbackTransaction()
	{
		return $this->conn->RollbackTrans() ? 0 : -1;
	}

	/**
	 * Get the backend platform
	 * @return string The backend platform
	 */
	function getPlatform()
	{
		return $this->platform;
		//return "UNKNOWN";
	}

	// Type conversion routines

	/**
	 * Change the value of a parameter to 't' or 'f' depending on whether it evaluates to true or false
	 * @param $parameter the parameter
	 */
	function dbBool(&$parameter)
	{
		if ($parameter)
			$parameter = 't';
		else
			$parameter = 'f';

		return $parameter;
	}

	/**
	 * Change a parameter from 't' or 'f' to a boolean, (others evaluate to false)
	 * @param string $parameter the parameter
	 * @return bool
	 */
	function phpBool($parameter)
	{
		$parameter = ($parameter == 't');
		return $parameter;
	}

	/**
	 * Change a db array into a PHP array
	 * @param string $dbarr String representing the DB array
	 * @return array A PHP array
	 */
	function phpArray($dbarr)
	{

		if (empty($dbarr)) {
			return [];
		}

		// Take off the first and last characters (the braces)
		$arr = substr($dbarr, 1, strlen($dbarr) - 2);

		// Pick out array entries by carefully parsing.  This is necessary in order
		// to cope with double quotes and commas, etc.
		$elements = [];
		$i = $j = 0;
		$in_quotes = false;
		while ($i < strlen($arr)) {
			// If current char is a double quote and it's not escaped, then
			// enter quoted bit
			$char = substr($arr, $i, 1);
			if ($char == '"' && ($i == 0 || substr($arr, $i - 1, 1) != '\\'))
				$in_quotes = !$in_quotes;
			elseif ($char == ',' && !$in_quotes) {
				// Add text so far to the array
				$elements[] = substr($arr, $j, $i - $j);
				$j = $i + 1;
			}
			$i++;
		}
		// Add final text to the array
		$elements[] = substr($arr, $j);

		// Do one further loop over the elements array to remote double quoting
		// and escaping of double quotes and backslashes
		for ($i = 0; $i < sizeof($elements); $i++) {
			$v = $elements[$i];
			if (strpos($v, '"') === 0) {
				$v = substr($v, 1, strlen($v) - 2);
				$v = str_replace('\\"', '"', $v);
				$v = str_replace('\\\\', '\\', $v);
				$elements[$i] = $v;
			}
		}

		return $elements;
	}

}
