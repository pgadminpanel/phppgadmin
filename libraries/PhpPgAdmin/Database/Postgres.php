<?php

namespace PhpPgAdmin\Database;

use ADORecordSet;

class Postgres extends PgBase
{
	/**
	 * Server major version
	 * @var float
	 */
	public $major_version = 0.0;
	public $platform = 'PostgreSQL';

	// Max object name length
	public $_maxNameLen = 63;

	// Store the current schema
	public $_schema;

	// PostgreSQL type mapping
	public $codemap = [
		'UNICODE' => 'UTF-8',
		'UTF8' => 'UTF-8',
		'LATIN1' => 'ISO-8859-1',
		// ... etc
	];

	public $defaultprops = ['', '', ''];
	public $fkactions = ['NO ACTION', 'RESTRICT', 'CASCADE', 'SET NULL', 'SET DEFAULT'];
	public $fkdeferrable = ['NOT DEFERRABLE', 'DEFERRABLE'];
	public $fkinitial = ['INITIALLY IMMEDIATE', 'INITIALLY DEFERRED'];
	public $fkmatches = ['MATCH SIMPLE', 'MATCH FULL'];


	public $id = 'oid';
	public $joinOps = [
		'INNER JOIN' => 'INNER JOIN',
		'LEFT JOIN' => 'LEFT JOIN',
		'RIGHT JOIN' => 'RIGHT JOIN',
		'FULL JOIN' => 'FULL JOIN'
	];

	public $extraTypes = ['serial', 'bigserial'];

	public $predefinedSizeTypes = [
		'abstime',
		'aclitem',
		'bigserial',
		'boolean',
		'bytea',
		'cid',
		'cidr',
		'circle',
		'date',
		'float4',
		'float8',
		'gtsvector',
		'inet',
		'int2',
		'int4',
		'int8',
		'macaddr',
		'money',
		'oid',
		'path',
		'polygon',
		'refcursor',
		'regclass',
		'regoper',
		'regoperator',
		'regproc',
		'regprocedure',
		'regtype',
		'reltime',
		'serial',
		'smgr',
		'text',
		'tid',
		'tinterval',
		'tsquery',
		'tsvector',
		'varbit',
		'void',
		'xid'
	];

	public $typAligns = ['char', 'int2', 'int4', 'double'];
	public $typAlignDef = 'int4';
	public $typIndexDef = 'BTREE';
	public $typIndexes = ['BTREE', 'RTREE', 'GIST', 'GIN', 'HASH'];
	public $typStorages = ['plain', 'external', 'extended', 'main'];
	public $typStorageDef = 'plain';

	// Select operators
	public $selectOps = [
		'=' => 'i',
		'!=' => 'i',
		'<' => 'i',
		'>' => 'i',
		'<=' => 'i',
		'>=' => 'i',
		'<<' => 'i',
		'>>' => 'i',
		'<<=' => 'i',
		'>>=' => 'i',
		'LIKE' => 'i',
		'NOT LIKE' => 'i',
		'ILIKE' => 'i',
		'NOT ILIKE' => 'i',
		'SIMILAR TO' => 'i',
		'NOT SIMILAR TO' => 'i',
		'~' => 'i',
		'!~' => 'i',
		'~*' => 'i',
		'!~*' => 'i',
		'IS NULL' => 'p',
		'IS NOT NULL' => 'p',
		'IN' => 'x',
		'NOT IN' => 'x',
		'@@' => 'i',
		'@@@' => 'i',
		'@>' => 'i',
		'<@' => 'i',
		'@@ to_tsquery' => 't',
		'@@@ to_tsquery' => 't',
		'@> to_tsquery' => 't',
		'<@ to_tsquery' => 't',
		'@@ plainto_tsquery' => 't',
		'@@@ plainto_tsquery' => 't',
		'@> plainto_tsquery' => 't',
		'<@ plainto_tsquery' => 't'
	];

	/**
	 * Postgres constructor.
	 * @param \ADOConnection $conn
	 */
	public function __construct($conn, $majorVersion)
	{
		parent::__construct($conn);
		$this->major_version = $majorVersion;
	}

	/**
	 * Sets the comment for an object in the database.
	 * @pre All parameters must already be cleaned
	 * @param string $obj_type
	 * @param string $obj_name
	 * @param string $table
	 * @param string $comment
	 * @param string|null $basetype
	 * @return int 0 on success, -1 on error
	 */
	public function setComment($obj_type, $obj_name, $table, $comment, $basetype = null)
	{
		$sql = "COMMENT ON {$obj_type} ";
		$f_schema = $this->_schema;
		$this->fieldClean($f_schema);
		$this->clean($comment);

		switch ($obj_type) {
			case 'CAST':
				// $obj_name = source type, $table = target type
				$sql .= "({$obj_name} AS {$table}) IS ";
				break;
			case 'DOMAIN':
				$sql .= "\"{$f_schema}\".\"{$obj_name}\" IS ";
				break;
			case 'TABLE':
				$sql .= "\"{$f_schema}\".\"{$table}\" IS ";
				break;
			case 'COLUMN':
				$sql .= "\"{$f_schema}\".\"{$table}\".\"{$obj_name}\" IS ";
				break;
			case 'SEQUENCE':
			case 'VIEW':
			case 'TEXT SEARCH CONFIGURATION':
			case 'TEXT SEARCH DICTIONARY':
			case 'TEXT SEARCH TEMPLATE':
			case 'TEXT SEARCH PARSER':
			case 'TYPE':
				$sql .= "\"{$f_schema}\".";
			case 'DATABASE':
			case 'ROLE':
			case 'SCHEMA':
			case 'TABLESPACE':
				$sql .= "\"{$obj_name}\" IS ";
				break;
			case 'FUNCTION':
				$sql .= "\"{$f_schema}\".{$obj_name} IS ";
				break;
			case 'OPERATOR':
				// $obj_name = operator name, $table = left arg type, $basetype = right arg type
				$left = $table;
				$right = $basetype;
				// support NONE for arguments; types should be passed as type names (unquoted)
				if ($left === null || $left === '') {
					$leftsql = 'NONE';
				} else {
					$this->clean($left);
					$leftsql = $left;
				}
				if ($right === null || $right === '') {
					$rightsql = 'NONE';
				} else {
					$this->clean($right);
					$rightsql = $right;
				}
				$sql .= "{$obj_name} ({$leftsql}, {$rightsql}) IS ";
				break;
			case 'AGGREGATE':
				// Support NONE for argument type; types should be passed as type names (unquoted)
				if ($basetype === null || $basetype === '') {
					$basitypesql = 'NONE';
				} else {
					$this->clean($basetype);
					$basitypesql = $basetype;
				}
				$sql .= "\"{$f_schema}\".\"{$obj_name}\" ({$basitypesql}) IS ";
				break;
			default:
				return -1;
		}

		if ($comment != '') {
			$sql .= "'{$comment}';";
		} else {
			$sql .= 'NULL;';
		}

		return $this->execute($sql);
	}

	/**
	 * Searches all system catalogs to find objects that match a name.
	 */
	public function findObject($term, $filter)
	{
		$conf = $this->conf();

		$this->clean($term);
		$this->clean($filter);
		$term = str_replace('_', '\\_', $term);
		$term = str_replace('%', '\\%', $term);

		if (!$conf['show_system']) {
			$where = " AND pn.nspname NOT LIKE \$_PATTERN_\$pg\_%\$_PATTERN_\$ AND pn.nspname != 'information_schema'";
			$lan_where = "AND pl.lanispl";
		} else {
			$where = '';
			$lan_where = '';
		}

		$sql = '';
		if ($filter != '') {
			$sql = "SELECT * FROM (";
		}

		$term = "\$_PATTERN_\$%{$term}%\$_PATTERN_\$";

		// Determine how to filter functions/aggregates based on PostgreSQL version
		if ($this->major_version >= 11) {
			$func_filter = "NOT (pp.prokind = 'a')";
			$agg_filter = "p.prokind = 'a'";
		} else {
			$func_filter = "NOT pp.proisagg";
			$agg_filter = "p.proisagg";
		}

		$sql .= "
            SELECT 'SCHEMA' AS type, oid, NULL AS schemaname, NULL AS relname, nspname AS name
                FROM pg_catalog.pg_namespace pn WHERE nspname ILIKE {$term} {$where}
            UNION ALL
            SELECT CASE WHEN relkind='r' THEN 'TABLE' WHEN relkind='v' THEN 'VIEW' WHEN relkind='S' THEN 'SEQUENCE' END, pc.oid,
                pn.nspname, NULL, pc.relname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn
                WHERE pc.relnamespace=pn.oid AND relkind IN ('r', 'v', 'S') AND relname ILIKE {$term} {$where}
            UNION ALL
            SELECT CASE WHEN pc.relkind='r' THEN 'COLUMNTABLE' ELSE 'COLUMNVIEW' END, NULL, pn.nspname, pc.relname, pa.attname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn,
                pg_catalog.pg_attribute pa WHERE pc.relnamespace=pn.oid AND pc.oid=pa.attrelid
                AND pa.attname ILIKE {$term} AND pa.attnum > 0 AND NOT pa.attisdropped AND pc.relkind IN ('r', 'v') {$where}
            UNION ALL
            SELECT 'FUNCTION', pp.oid, pn.nspname, NULL, pp.proname || '(' || pg_catalog.oidvectortypes(pp.proargtypes) || ')' FROM pg_catalog.pg_proc pp, pg_catalog.pg_namespace pn
                WHERE pp.pronamespace=pn.oid AND {$func_filter} AND pp.proname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'INDEX', NULL, pn.nspname, pc.relname, pc2.relname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn,
                pg_catalog.pg_index pi, pg_catalog.pg_class pc2 WHERE pc.relnamespace=pn.oid AND pc.oid=pi.indrelid
                AND pi.indexrelid=pc2.oid
                AND NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_constraint c
                    ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                    WHERE d.classid = pc2.tableoid AND d.objid = pc2.oid AND d.deptype = 'i' AND c.contype IN ('u', 'p')
                )
                AND pc2.relname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'CONSTRAINTTABLE', NULL, pn.nspname, pc.relname, pc2.conname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn,
                pg_catalog.pg_constraint pc2 WHERE pc.relnamespace=pn.oid AND pc.oid=pc2.conrelid AND pc2.conrelid != 0
                AND CASE WHEN pc2.contype IN ('f', 'c') THEN TRUE ELSE NOT EXISTS (
                    SELECT 1 FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_constraint c
                    ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                    WHERE d.classid = pc2.tableoid AND d.objid = pc2.oid AND d.deptype = 'i' AND c.contype IN ('u', 'p')
                ) END
                AND pc2.conname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'CONSTRAINTDOMAIN', pt.oid, pn.nspname, pt.typname, pc.conname FROM pg_catalog.pg_type pt, pg_catalog.pg_namespace pn,
                pg_catalog.pg_constraint pc WHERE pt.typnamespace=pn.oid AND pt.oid=pc.contypid AND pc.contypid != 0
                AND pc.conname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'TRIGGER', NULL, pn.nspname, pc.relname, pt.tgname FROM pg_catalog.pg_class pc, pg_catalog.pg_namespace pn,
                pg_catalog.pg_trigger pt WHERE pc.relnamespace=pn.oid AND pc.oid=pt.tgrelid
                    AND ( pt.tgconstraint = 0 OR NOT EXISTS
                    (SELECT 1 FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_constraint c
                    ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                    WHERE d.classid = pt.tableoid AND d.objid = pt.oid AND d.deptype = 'i' AND c.contype = 'f'))
                AND pt.tgname ILIKE {$term} {$where}
            UNION ALL
            SELECT 'RULETABLE', NULL, pn.nspname AS schemaname, c.relname AS tablename, r.rulename FROM pg_catalog.pg_rewrite r
                JOIN pg_catalog.pg_class c ON c.oid = r.ev_class
                LEFT JOIN pg_catalog.pg_namespace pn ON pn.oid = c.relnamespace
                WHERE c.relkind='r' AND r.rulename != '_RETURN' AND r.rulename ILIKE {$term} {$where}
            UNION ALL
            SELECT 'RULEVIEW', NULL, pn.nspname AS schemaname, c.relname AS tablename, r.rulename FROM pg_catalog.pg_rewrite r
                JOIN pg_catalog.pg_class c ON c.oid = r.ev_class
                LEFT JOIN pg_catalog.pg_namespace pn ON pn.oid = c.relnamespace
                WHERE c.relkind='v' AND r.rulename != '_RETURN' AND r.rulename ILIKE {$term} {$where}
        ";

		if ($conf['show_advanced']) {
			$sql .= "
                UNION ALL
                SELECT CASE WHEN pt.typtype='d' THEN 'DOMAIN' ELSE 'TYPE' END, pt.oid, pn.nspname, NULL,
                    pt.typname FROM pg_catalog.pg_type pt, pg_catalog.pg_namespace pn
                    WHERE pt.typnamespace=pn.oid AND typname ILIKE {$term}
                    AND (pt.typrelid = 0 OR (SELECT c.relkind = 'c' FROM pg_catalog.pg_class c WHERE c.oid = pt.typrelid))
                    {$where}
                 UNION ALL
                SELECT 'OPERATOR', po.oid, pn.nspname, NULL, po.oprname FROM pg_catalog.pg_operator po, pg_catalog.pg_namespace pn
                    WHERE po.oprnamespace=pn.oid AND oprname ILIKE {$term} {$where}
                UNION ALL
                SELECT 'CONVERSION', pc.oid, pn.nspname, NULL, pc.conname FROM pg_catalog.pg_conversion pc,
                    pg_catalog.pg_namespace pn WHERE pc.connamespace=pn.oid AND conname ILIKE {$term} {$where}
                UNION ALL
                SELECT 'LANGUAGE', pl.oid, NULL, NULL, pl.lanname FROM pg_catalog.pg_language pl
                    WHERE lanname ILIKE {$term} {$lan_where}
                UNION ALL
                SELECT DISTINCT ON (p.proname) 'AGGREGATE', p.oid, pn.nspname, NULL, p.proname FROM pg_catalog.pg_proc p
                    LEFT JOIN pg_catalog.pg_namespace pn ON p.pronamespace=pn.oid
                    WHERE {$agg_filter} AND p.proname ILIKE {$term} {$where}
                UNION ALL
                SELECT DISTINCT ON (po.opcname) 'OPCLASS', po.oid, pn.nspname, NULL, po.opcname FROM pg_catalog.pg_opclass po,
                    pg_catalog.pg_namespace pn WHERE po.opcnamespace=pn.oid
                    AND po.opcname ILIKE {$term} {$where}
            ";
		} else {
			$sql .= "
                UNION ALL
                SELECT 'DOMAIN', pt.oid, pn.nspname, NULL,
                    pt.typname FROM pg_catalog.pg_type pt, pg_catalog.pg_namespace pn
                    WHERE pt.typnamespace=pn.oid AND pt.typtype='d' AND typname ILIKE {$term}
                    AND (pt.typrelid = 0 OR (SELECT c.relkind = 'c' FROM pg_catalog.pg_class c WHERE c.oid = pt.typrelid))
                    {$where}
            ";
		}

		if ($filter != '') {
			$sql .= ") AS sub WHERE type LIKE '{$filter}%' ";
		}

		$sql .= "ORDER BY type, schemaname, relname, name";

		return $this->selectSet($sql);
	}

	/**
	 * Returns prepared transactions information.
	 */
	public function getPreparedXacts($database = null)
	{
		if ($database === null) {
			$sql = "SELECT * FROM pg_prepared_xacts";
		} else {
			$this->clean($database);
			$sql = "SELECT transaction, gid, prepared, owner FROM pg_prepared_xacts
                WHERE database='{$database}' ORDER BY owner";
		}

		return $this->selectSet($sql);
	}


	/**
	 * Returns all available variable information.
	 */
	public function getVariables()
	{
		$sql = "SHOW ALL";
		return $this->selectSet($sql);
	}


	/**
	 * Generates the SQL for the 'select' function
	 * @param $table string The table from which to select
	 * @param $show array An array of columns to show.  Empty array means all columns.
	 * @param $values array An array mapping columns to values
	 * @param $ops array An array of the operators to use
	 * @param $orderby array (optional) An array of column numbers or names (one based)
	 *        mapped to sort direction (asc or desc or '' or null) to order by
	 * @return string The SQL query
	 */
	function getSelectSQL($table, $show, $values, $ops, $orderby = [])
	{
		$this->fieldArrayClean($show);

		// If an empty array is passed in, then show all columns
		if (sizeof($show) == 0) {
			if ($this->hasObjectID($table))
				$sql = "SELECT \"{$this->id}\", * FROM ";
			else
				$sql = "SELECT * FROM ";
		} else {
			// Add oid column automatically to results for editing purposes
			if (!in_array($this->id, $show) && $this->hasObjectID($table))
				$sql = "SELECT \"{$this->id}\", \"";
			else
				$sql = "SELECT \"";

			$sql .= join('","', $show) . "\" FROM ";
		}

		$this->fieldClean($table);

		if (isset($_REQUEST['schema'])) {
			$f_schema = $_REQUEST['schema'];
			$this->fieldClean($f_schema);
			$sql .= "\"{$f_schema}\".";
		}
		$sql .= "\"{$table}\"";

		// If we have values specified, add them to the WHERE clause
		$first = true;
		if (is_array($values) && sizeof($values) > 0) {
			foreach ($values as $k => $v) {
				if ($v != '' || $this->selectOps[$ops[$k]] == 'p') {
					$this->fieldClean($k);
					if ($first) {
						$sql .= " WHERE ";
						$first = false;
					} else {
						$sql .= " AND ";
					}
					// Different query format depending on operator type
					switch ($this->selectOps[$ops[$k]]) {
						case 'i':
							// Only clean the field for the inline case
							// this is because (x), subqueries need to
							// to allow 'a','b' as input.
							$this->clean($v);
							$sql .= "\"{$k}\" {$ops[$k]} '{$v}'";
							break;
						case 'p':
							$sql .= "\"{$k}\" {$ops[$k]}";
							break;
						case 'x':
							$sql .= "\"{$k}\" {$ops[$k]} ({$v})";
							break;
						case 't':
							$sql .= "\"{$k}\" {$ops[$k]}('{$v}')";
							break;
						default:
						// Shouldn't happen
					}
				}
			}
		}

		// ORDER BY
		if (is_array($orderby) && sizeof($orderby) > 0) {
			$sql .= " ORDER BY ";
			$sep = "";
			foreach ($orderby as $k => $v) {
				$sql .= $sep;
				$sep = ", ";
				if (ctype_digit($k)) {
					$sql .= $k;
				} else {
					$sql .= '"' . $this->fieldClean($k) . '"';
				}
				if (strtoupper($v) == 'DESC')
					$sql .= " DESC";
			}
		}

		return $sql;
	}

	/**
	 * Checks to see whether or not a table has a unique id column
	 * @param string $table The table name
	 * @return bool|null True if it has a unique id, false otherwise or null on error
	 **/
	function hasObjectID($table)
	{
		if ($this->major_version > 11) {
			return false;
		}
		$c_schema = $this->_schema;
		$this->clean($c_schema);
		$this->clean($table);

		$sql = "SELECT relhasoids FROM pg_catalog.pg_class WHERE relname='{$table}'
			AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}')";

		$rs = $this->selectSet($sql);
		if ($rs->recordCount() != 1)
			return null;
		else {
			return $this->phpBool($rs->fields['relhasoids']);
		}
	}

	/**
	 * Formats a type correctly for display.  Postgres 7.0 had no 'format_type'
	 * built-in function, and hence we need to do it manually.
	 * @param string $typname The name of the type
	 * @param string $typmod The contents of the typmod field
	 * @deprecated replace by SQL: format_type(atttypid, atttypmod)
	 */
	public function formatType($typname, $typmod)
	{
		// This is a specific constant in the 7.0 source
		$varhdrsz = 4;

		// TODO: Can be replaced by:
		// SELECT format_type(atttypid, atttypmod)
		// FROM pg_attribute
		// WHERE attrelid = 'mytable'::regclass;

		// If the first character is an underscore, it's an array type
		$is_array = false;
		if (substr($typname, 0, 1) == '_') {
			$is_array = true;
			$typname = substr($typname, 1);
		}

		// Show lengths on bpchar and varchar
		if ($typname == 'bpchar') {
			$len = $typmod - $varhdrsz;
			$temp = 'character';
			if ($len > 1)
				$temp .= "({$len})";
		} elseif ($typname == 'varchar') {
			$temp = 'character varying';
			if ($typmod != -1)
				$temp .= "(" . ($typmod - $varhdrsz) . ")";
		} elseif ($typname == 'numeric') {
			$temp = 'numeric';
			if ($typmod != -1) {
				$tmp_typmod = $typmod - $varhdrsz;
				$precision = ($tmp_typmod >> 16) & 0xffff;
				$scale = $tmp_typmod & 0xffff;
				$temp .= "({$precision}, {$scale})";
			}
		} else
			$temp = $typname;

		// Add array qualifier if it's an array
		if ($is_array)
			$temp .= '[]';

		return $temp;
	}

	/**
	 * Get the last error in the connection
	 * @return string Error string
	 */
	function getLastError()
	{
		return pg_last_error($this->conn->_connectionID);
	}

	/**
	 * Sets up the data object for a dump.  eg. Starts the appropriate
	 * transaction, sets variables, etc.
	 * @return 0 success
	 */
	function beginDump()
	{
		// Begin serializable transaction (to dump consistent data)
		$status = $this->beginTransaction();
		if ($status != 0)
			return -1;

		// Set serializable
		$sql = "SET TRANSACTION ISOLATION LEVEL SERIALIZABLE";
		$status = $this->execute($sql);
		if ($status != 0) {
			$this->rollbackTransaction();
			return -1;
		}

		// Set datestyle to ISO
		$sql = "SET DATESTYLE = ISO";
		$status = $this->execute($sql);
		if ($status != 0) {
			$this->rollbackTransaction();
			return -1;
		}

		// Set extra_float_digits to 2
		$sql = "SET extra_float_digits TO 2";
		$status = $this->execute($sql);
		if ($status != 0) {
			$this->rollbackTransaction();
			return -1;
		}

		return 0;
	}

	/**
	 * Ends the data object for a dump.
	 * @return 0 success
	 */
	function endDump()
	{
		return $this->endTransaction();
	}

	public function getMajorVersion(): float
	{
		return $this->major_version;
	}

	// Capabilities

	function hasAlterSequenceSchema()
	{
		return true;
	}

	function hasAlterSequenceStart()
	{
		return true;
	}

	function hasAlterTableSchema()
	{
		return true;
	}

	function hasAutovacuum()
	{
		return true;
	}

	function hasCreateTableLike()
	{
		return true;
	}

	function hasCreateTableLikeWithConstraints()
	{
		return true;
	}

	function hasCreateTableLikeWithIndexes()
	{
		return true;
	}

	function hasCreateFieldWithConstraints()
	{
		return true;
	}

	function hasDisableTriggers()
	{
		return true;
	}

	function hasAlterDomains()
	{
		return true;
	}

	function hasDomainConstraints()
	{
		return true;
	}

	/*
	 * moved to TypeActions...
	 *
	function hasEnumTypes()
	{
		return true;
	}
	*/

	function hasFTS()
	{
		return true;
	}

	function hasGrantOption()
	{
		return true;
	}

	function hasNamedParams()
	{
		return true;
	}

	function hasPreparedXacts()
	{
		return true;
	}

	function hasReadOnlyQueries()
	{
		return true;
	}

	function hasRoles()
	{
		return true;
	}

	function hasServerAdminFuncs()
	{
		return true;
	}

	function hasSharedComments()
	{
		return true;
	}

	function hasQueryCancel()
	{
		return true;
	}

	function hasTablespaces()
	{
		return true;
	}

	function hasUserRename()
	{
		return true;
	}

	function hasUserSignals()
	{
		// PostgreSQL versions 9.0-9.4 do not have user signals capability
		return $this->major_version >= 9.5;
	}

	function hasVirtualTransactionId()
	{
		return true;
	}

	function hasAlterDatabase()
	{
		return true;
	}

	function hasDatabaseCollation()
	{
		return true;
	}

	function hasQueryKill()
	{
		return true;
	}

	function hasConcurrentIndexBuild()
	{
		return true;
	}

	function hasForceReindex()
	{
		return false;
	}

	function hasByteaHexDefault()
	{
		return true;
	}

	function hasServerOids()
	{
		// Server OIDs are available only until PostgreSQL 11
		return $this->major_version <= 11;
	}

	function hasAlterColumnType()
	{
		return true;
	}

	function hasMatViews()
	{
		// Materialized views were introduced in PostgreSQL 9.3
		return $this->major_version >= 9.3;
	}

	function hasGeneratedColumns()
	{
		// Generated columns were introduced in PostgreSQL 12
		return $this->major_version >= 12;
	}

	function hasEditableGeneratedColumns()
	{
		// ALTER COLUMN ... SET EXPRESSION AS was introduced in PostgreSQL 17
		return $this->major_version >= 17;
	}

	function hasDropGeneratedExpression()
	{
		// ALTER COLUMN ... DROP EXPRESSION was introduced in PostgreSQL 13
		return $this->major_version >= 13;
	}
}
