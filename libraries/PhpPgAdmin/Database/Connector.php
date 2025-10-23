<?php


namespace PhpPgAdmin\Database;


use ADOConnection;
use PhpPgAdmin\Core\AppContainer;

/**
 * Do we still need this class?
 */
class Connector
{

	/**
	 * @var ADOConnection
	 */
	var $conn;

	// The backend platform.  Set to UNKNOWN by default.
	var $platform = 'UNKNOWN';

	/**
	 * Creates a new connection.  Will actually make a database connection.
	 * @param $fetchMode int Defaults to associative.  Override for different behaviour
	 */
	function __construct(
		$host,
		$port,
		$sslmode,
		$user,
		$password,
		$database,
		$fetchMode = ADODB_FETCH_ASSOC
	) {
		//$this->conn = ADONewConnection('postgres9_enhanced');
		$this->conn = ADONewConnection('postgres9');
		$this->conn->setFetchMode($fetchMode);

		$pghost = self::getHostPortString($host, $port, $sslmode);

		@$this->conn->connect($pghost, $user, $password, $database);
	}

	public static function getHostPortString($host, $port, $sslmode)
	{
		// Ignore host if null
		if ($host === null || $host == '')
			if ($port !== null && $port != '')
				$pghost = ':' . $port;
			else
				$pghost = '';
		else
			$pghost = "{$host}:{$port}";

		// Add sslmode to $pghost as needed
		if (($sslmode == 'disable') || ($sslmode == 'allow') || ($sslmode == 'prefer') || ($sslmode == 'require')) {
			$pghost .= ':' . $sslmode;
		} elseif ($sslmode == 'legacy') {
			$pghost .= ' requiressl=1';
		}

		return $pghost;
	}

	/**
	 * Gets the name of the correct database driver to use.  As a side effect,
	 * sets the platform.
	 * @param string (return-by-ref) $description A description of the database and version
	 * @return string The class name of the driver eg. Postgres90
	 * @return null if version is < 9
	 * @return -3 Database-specific failure
	 */
	function getDriver(&$description, &$version, &$majorVersion)
	{
		$v = pg_version($this->conn->_connectionID);
		$version = $v['server'];

		$majorVersion = (float) substr($version, 0, 3);

		if ($majorVersion < AppContainer::getPgServerMinVersion())
			return null;

		$description = "PostgreSQL {$version}";

		// All versions now use the single unified Postgres class
		// Version-specific logic is handled internally with version checks
		return 'Postgres';
	}

	/**
	 * Get the last error in the connection
	 * @return string Error string
	 */
	function getLastError()
	{
		return pg_last_error($this->conn->_connectionID);
	}
}
