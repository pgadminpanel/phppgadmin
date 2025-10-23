<?php

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Postgres;

/**
 * Class to manage reports.  Note how this class is designed to use
 * the functions provided by the database driver exclusively, and hence
 * will work with any database without modification.
 *
 * $Id: Reports.php,v 1.18 2007/04/16 11:02:35 mr-russ Exp $
 */

class Reports extends AppContext
{

	/**
	 * Database driver
	 * @var Postgres
	 */
	private $driver;
	/**
	 * Configuration array
	 * @var array
	 */
	private $conf;

	/* Constructor */
	function __construct(&$conf, &$status)
	{
		$this->conf = $conf;
		$pg = $this->postgres();
		$databaseActions = new DatabaseActions($pg);

		// Check to see if the reports database exists
		$rs = $databaseActions->getDatabase($this->conf['reports_db']);
		if ($rs->recordCount() != 1) {
			$status = -1;
			return;
		}

		// Create a new database access object.
		$this->driver = $this->misc()->getDatabaseAccessor($this->conf['reports_db']);
		// Reports database should have been created in public schema
		$schemaActions = new SchemaActions($this->driver);
		$schemaActions->setSchema($this->conf['reports_schema']);
		$status = 0;
	}

	/**
	 * Finds all reports
	 * @return ADORecordSet A recordset
	 */
	function getReports()
	{
		// Filter for owned reports if necessary
		if ($this->conf['owned_reports_only']) {
			$server_info = $this->misc()->getServerInfo();
			$filter['created_by'] = $server_info['username'];
			$ops = ['created_by' => '='];
		} else {
			$filter = $ops = [];
		}

		$sql = $this->driver->getSelectSQL(
			$this->conf['reports_table'],
			['report_id', 'report_name', 'db_name', 'date_created', 'created_by', 'descr', 'report_sql', 'paginate'],
			$filter,
			$ops,
			['db_name' => 'asc', 'report_name' => 'asc']
		);

		return $this->driver->selectSet($sql);
	}

	/**
	 * Finds a particular report
	 * @param $report_id The ID of the report to find
	 * @return ADORecordSet A recordset
	 */
	function getReport($report_id)
	{
		$sql = $this->driver->getSelectSQL(
			$this->conf['reports_table'],
			['report_id', 'report_name', 'db_name', 'date_created', 'created_by', 'descr', 'report_sql', 'paginate'],
			['report_id' => $report_id],
			['report_id' => '='],
			[]
		);

		return $this->driver->selectSet($sql);
	}

	/**
	 * Creates a report
	 * @param string $report_name The name of the report
	 * @param string $db_name The name of the database
	 * @param string $descr The comment on the report
	 * @param string $report_sql The SQL for the report
	 * @param bool $paginate The report should be paginated
	 * @return int 0 success
	 */
	function createReport($report_name, $db_name, $descr, $report_sql, $paginate)
	{
		$server_info = $this->misc()->getServerInfo();
		$temp = [
			'report_name' => $report_name,
			'db_name' => $db_name,
			'created_by' => $server_info['username'],
			'report_sql' => $report_sql,
			'paginate' => $paginate ? 'true' : 'false',
		];
		if ($descr != '')
			$temp['descr'] = $descr;

		return $this->driver->insert($this->conf['reports_table'], $temp);
	}

	/**
	 * Alters a report
	 * @param $report_id The ID of the report
	 * @param $report_name The name of the report
	 * @param $db_name The name of the database
	 * @param $descr The comment on the report
	 * @param $report_sql The SQL for the report
	 * @param $paginate The report should be paginated
	 * @return 0 success
	 */
	function alterReport($report_id, $report_name, $db_name, $descr, $report_sql, $paginate)
	{
		$server_info = $this->misc()->getServerInfo();
		$temp = [
			'report_name' => $report_name,
			'db_name' => $db_name,
			'created_by' => $server_info['username'],
			'report_sql' => $report_sql,
			'paginate' => $paginate ? 'true' : 'false',
			'descr' => $descr
		];

		return $this->driver->update(
			$this->conf['reports_table'],
			$temp,
			['report_id' => $report_id]
		);
	}

	/**
	 * Drops a report
	 * @param $report_id The ID of the report to drop
	 * @return 0 success
	 */
	function dropReport($report_id)
	{
		return $this->driver->delete($this->conf['reports_table'], ['report_id' => $report_id]);
	}
}
