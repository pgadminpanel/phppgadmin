<?php

/**
 * PHPPgAdmin v6.0+
 * @package PhpPgAdmin\Database\Actions
 */

namespace PhpPgAdmin\Database\Actions;



/**
 * Tablespace action class - handles tablespace management
 */
class TablespaceActions extends ActionsBase
{
	// Base constructor inherited from Actions

	/**
	 * Retrieves all tablespace information.
	 * @param bool $all Include all tablespaces (necessary when moving objects back to the default space)
	 * @return \ADORecordSet A recordset
	 */
	function getTablespaces($all = false)
	{
		$conf = $this->conf();

		if ($this->connection->major_version >= 9.2) {
			$spclocation = "pg_tablespace_location(oid) AS spclocation";
		} else {
			$spclocation = "spclocation";
		}
		$sql = "SELECT spcname, spcacl, pg_catalog.pg_get_userbyid(spcowner) AS spcowner, $spclocation,
                    (SELECT description FROM pg_catalog.pg_shdescription pd WHERE pg_tablespace.oid=pd.objoid AND pd.classoid='pg_tablespace'::regclass) AS spccomment
				FROM pg_catalog.pg_tablespace";

		if (!$conf['show_system'] && !$all) {
			$sql .= ' WHERE spcname NOT LIKE $$pg\_%$$';
		}

		$sql .= " ORDER BY spcname";

		return $this->connection->selectSet($sql);
	}

	/**
	 * Retrieves a specific tablespace's information.
	 * @param string $spcname Tablespace name
	 * @return \ADORecordSet A recordset
	 */
	function getTablespace($spcname)
	{
		$this->connection->clean($spcname);

		if ($this->connection->major_version >= 9.2) {
			$spclocation = "pg_tablespace_location(oid) AS spclocation";
		} else {
			$spclocation = "spclocation";
		}
		$sql = "SELECT spcname, pg_catalog.pg_get_userbyid(spcowner) AS spcowner, $spclocation,
                    (SELECT description FROM pg_catalog.pg_shdescription pd WHERE pg_tablespace.oid=pd.objoid AND pd.classoid='pg_tablespace'::regclass) AS spccomment
				FROM pg_catalog.pg_tablespace WHERE spcname='{$spcname}'";

		return $this->connection->selectSet($sql);
	}

	/**
	 * Creates a tablespace
	 * @param string $spcname The name of the tablespace to create
	 * @param string $spcowner The owner of the tablespace. '' for current
	 * @param string $spcloc The directory in which to create the tablespace
	 * @param string $comment (optional) The tablespace comment
	 * @return 0 success
	 * @return -1 creation error
	 * @return -2 comment error
	 */
	public function createTablespace($spcname, $spcowner, $spcloc, $comment = '')
	{
		$this->connection->fieldClean($spcname);
		$this->connection->clean($spcloc);

		$sql = "CREATE TABLESPACE \"{$spcname}\"";

		if ($spcowner != '') {
			$this->connection->fieldClean($spcowner);
			$sql .= " OWNER \"{$spcowner}\"";
		}

		$sql .= " LOCATION '{$spcloc}'";

		$status = $this->connection->execute($sql);
		if ($status != 0)
			return -1;

		if ($comment != '' && $this->connection->hasSharedComments()) {
			$status = $this->connection->setComment('TABLESPACE', $spcname, '', $comment);
			if ($status != 0)
				return -2;
		}

		return 0;
	}

	/**
	 * Alters a tablespace
	 * @param string $spcname The name of the tablespace
	 * @param string $name The new name for the tablespace
	 * @param string $owner The new owner for the tablespace
	 * @param string $comment (optional) The tablespace comment
	 * @return 0 success
	 * @return -1 transaction error
	 * @return -2 owner error
	 * @return -3 rename error
	 * @return -4 comment error
	 */
	public function alterTablespace($spcname, $name, $owner, $comment = '')
	{
		$this->connection->fieldClean($spcname);
		$this->connection->fieldClean($name);
		$this->connection->fieldClean($owner);

		// Begin transaction
		$status = $this->connection->beginTransaction();
		if ($status != 0)
			return -1;

		// Owner
		$sql = "ALTER TABLESPACE \"{$spcname}\" OWNER TO \"{$owner}\"";
		$status = $this->connection->execute($sql);
		if ($status != 0) {
			$this->connection->rollbackTransaction();
			return -2;
		}

		// Rename (only if name has changed)
		if ($name != $spcname) {
			$sql = "ALTER TABLESPACE \"{$spcname}\" RENAME TO \"{$name}\"";
			$status = $this->connection->execute($sql);
			if ($status != 0) {
				$this->connection->rollbackTransaction();
				return -3;
			}

			$spcname = $name;
		}

		// Set comment if it has changed
		if (trim($comment) != '' && $this->connection->hasSharedComments()) {
			$status = $this->connection->setComment('TABLESPACE', $spcname, '', $comment);
			if ($status != 0) {
				$this->connection->rollbackTransaction();
				return -4;
			}
		}

		return $this->connection->endTransaction();
	}

	/**
	 * Drops a tablespace
	 * @param string $spcname The name of the tablespace to drop
	 * @return 0 success
	 */
	public function dropTablespace($spcname)
	{
		$this->connection->fieldClean($spcname);

		$sql = "DROP TABLESPACE \"{$spcname}\"";

		return $this->connection->execute($sql);
	}
}
