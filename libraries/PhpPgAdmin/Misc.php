<?php

namespace PhpPgAdmin;

use ADORecordSet;
use Connection;
use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Core\UrlBuilder;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\ArrayRecordSet;
use PhpPgAdmin\Database\Connector;
use PhpPgAdmin\Database\Postgres;
use PhpPgAdmin\Gui\ConnectionSelector;
use PhpPgAdmin\Gui\LayoutRenderer;
use PhpPgAdmin\Gui\NavLinksRenderer;
use PhpPgAdmin\Gui\TableRenderer;
use PhpPgAdmin\Gui\TabsRenderer;
use PhpPgAdmin\Gui\TopbarRenderer;
use PhpPgAdmin\Security\CredentialEncryption;
use PhpPgAdmin\Gui\TrailRenderer;

/**
 * Namespaced facade for the legacy Misc class.
 */
class Misc extends AppContext
{
	// Tracking string to include in HREFs
	var $href;
	// Tracking string to include in forms
	var $form;

	// GUI renderer delegates
	private $tabsRenderer = null;
	private $trailRenderer = null;
	private $topbarRenderer = null;
	private $navLinksRenderer = null;
	private $tableRenderer = null;
	private $connectionSelector = null;
	private $urlBuilder = null;
	private $layoutRenderer = null;

	/**
	 * Checks if dumps are available.
	 * Always returns true - export options are always shown to the user.
	 * At export time, DumpManager determines which formats are actually available
	 * based on whether pg_dump is auto-detected.
	 * This matches phpMyAdmin behavior where no path configuration is required.
	 * 
	 * @param bool $all (optional) Ignored - kept for backwards compatibility
	 * @return bool Always true
	 */
	function isDumpEnabled($all = false)
	{
		// Always show export options. Actual format availability is determined
		// at export time based on whether pg_dump is auto-detected.
		return true;
	}

	/**
	 * Sets the href tracking variable
	 */
	function setHREF()
	{
		$this->href = $this->getUrlBuilder()->getHREF();
	}

	/**
	 * Get a href query string, excluding objects below the given object type (inclusive)
	 */
	function getHREF($exclude_from = null)
	{
		return $this->getUrlBuilder()->getHREF($exclude_from);
	}

	/**
	 * Get the subject parameters as an associative array
	 */
	function getSubjectParams($subject)
	{
		return $this->getUrlBuilder()->getSubjectParams($subject);
	}

	/**
	 * Get the subject string for HREFs
	 */
	function getHREFSubject($subject)
	{
		return $this->getUrlBuilder()->getHREFSubject($subject);
	}

	/**
	 * Sets the form tracking variable
	 */
	function setForm()
	{
		$this->form = $this->getUrlBuilder()->buildHiddenFormInputs();
	}

	/**
	 * Get the URL for a particular action
	 * @param array $action An associative array defining the action.
	 *                      See getActionUrl in UrlBuilder for details.
	 * @param array $fields The data from which to get the variable values
	 * @return string The URL
	 */
	function getActionUrl($action, $fields)
	{
		return $this->getUrlBuilder()->getActionUrl($action, $fields);
	}

	/**
	 * Get the request variables as an associative array
	 * @param string $subject The subject to get the variables for
	 * @return array The request variables
	 */
	function getRequestVars($subject = '')
	{
		return $this->getUrlBuilder()->getRequestVars($subject);
	}

	/**
	 * Print URL variables as hidden form inputs
	 * @param array $vars The variables to print
	 * @param array $fields The data from which to get the variable values
	 */
	function printUrlVars($vars, $fields)
	{
		return $this->getUrlBuilder()->printUrlVars($vars, $fields);
	}

	/**
	 * Format a value for display in a table
	 * @param string $str The string to format
	 * @param string $type The type of the field
	 * @param array $params Additional parameters for formatting
	 * @return string The formatted value
	 */
	function formatVal($str, $type = null, $params = [])
	{
		return $this->getLayoutRenderer()->formatVal($str, $type, $params);
	}

	/**
	 * Print out the page heading and help link
	 * @param string $title Title, already escaped
	 * @param ?string $help (optional) The identifier for the help link
	 */
	function printTitle($title, $help = null)
	{
		$this->getLayoutRenderer()->printTitle($title, $help);
	}

	/**
	 * Print out a message
	 * @param $msg The message to print
	 */
	function printMsg($msg)
	{
		if ($msg != '')
			echo "<p class=\"message\">{$msg}</p>\n";
	}

	/**
	 * Creates a database accessor
	 * @return Postgres
	 */
	function getDatabaseAccessor($database, $server_id = null)
	{
		$lang = $this->lang();
		$conf = $this->conf();

		$server_info = $this->getServerInfo($server_id);

		// Perform extra security checks if this config option is set
		if ($conf['extra_login_security']) {
			// Disallowed logins if extra_login_security is enabled.
			// These must be lowercase.
			$bad_usernames = ['pgsql', 'postgres', 'root', 'administrator'];

			$username = strtolower($server_info['username']);

			if ($server_info['password'] == '' || in_array($username, $bad_usernames)) {
				unset($_SESSION['webdbLogin'][$_REQUEST['server']]);
				$msg = $lang['strlogindisallowed'];
				include('./login.php');
				exit;
			}
		}

		// Create the connection object and make the connection
		$connector = new Connector(
			$server_info['host'] ?? null,
			$server_info['port'] ?? null,
			$server_info['sslmode'],
			$server_info['username'],
			$server_info['password'],
			$database
		);

		// Get the name of the database driver we need to use.
		// The description of the server is returned in $platform.
		$driverName = $connector->getDriver($platform, $version, $majorVersion);
		if ($driverName === null) {
			printf($lang['strpostgresqlversionnotsupported'], AppContainer::getPgServerMinVersion());
			exit;
		}
		$this->setServerInfo('platform', $platform, $server_id);
		$this->setServerInfo('pgVersion', $version, $server_id);

		// Create a database wrapper class for easy manipulation of the
		// connection.
		//$className = "\\PhpPgAdmin\\Database\\$driverName";
		$postgres = new Postgres($connector->conn, $majorVersion);
		$postgres->platform = $connector->platform;

		// we work on UTF-8 only encoding
		$queries = "SET client_encoding TO 'UTF-8';";

		// Enable standard conforming strings for versions < 9.1
		// Since 9.1, this is already the default
		if ($majorVersion < 9.1) {
			$queries .= " SET standard_conforming_strings = on;";
		}

		//if ($postgres->hasByteaHexDefault()) {
		//	$queries .= " SET bytea_output TO escape;";
		//}

		$locale = $lang['applocale'] . '.UTF-8';
		$queries .= " SET lc_messages = '$locale';";

		AppContainer::set('quiet_sql_error_handling', true);
		$postgres->execute($queries);
		AppContainer::set('quiet_sql_error_handling', false);

		return $postgres;
	}

	/**
	 * Prints the page header.  If AppContainer::isSkipHtmlFrame() is true,
	 * then no HTML frame is printed.
	 * @param string $title The title of the page
	 * @param string $scripts script tags
	 */
	function printHeader($title = '', $scripts = '')
	{
		return $this->getLayoutRenderer()->printHeader($title, $scripts);
	}


	/**
	 * Prints the page body.
	 */
	function printBody()
	{
		return $this->getLayoutRenderer()->printBody();
	}

	/**
	 * Prints the page footer
	 */
	function printFooter()
	{
		return $this->getLayoutRenderer()->printFooter();
	}

	function printBrowser()
	{
		return $this->getLayoutRenderer()->printBrowser();
	}

	/**
	 * @param string $location
	 */
	function redirect(string $location)
	{
		header("Location: $location");
		exit;
	}

	/**
	 * Display a single link
	 * @param array $link An associative array defining the link. See printLinksList for details.
	 */
	function printLink($link)
	{
		$this->getLayoutRenderer()->printLink($link);
	}

	/**
	 * Display a list of links
	 * @param $links An array of associative arrays defining the links:
	 *         $links = array(
	 *            link_id => array(
	 *               'title' => Link title,
	 *               'url'   => Link URL,
	 *               'class' => CSS class for the link,
	 *               'id'    => HTML id for the link,
	 *               'icon'  => Icon name for the link,
	 *               'help'  => Help page for the link,
	 *            ), ...
	 *         );
	 * @param string $class CSS class to apply to the list container
	 */
	function printLinksList($links, $class = '')
	{
		$this->getLayoutRenderer()->printLinksList($links, $class);
	}

	/**
	 * Print the tab bar
	 * @param array|string $tabs An array defining the tabs. See TabsRenderer::printTabs for details.
	 * @param string $activetab The id of the active tab
	 */
	function printTabs($tabs, $activetab)
	{
		return $this->getTabsRenderer()->printTabs($tabs, $activetab);
	}

	/**
	 * Get the tabs for a particular section
	 */
	function getNavTabs($section)
	{
		return $this->getTabsRenderer()->getNavTabs($section);
	}

	/**
	 * Get the URL for the last active tab of a particular tab bar.
	 */
	function getLastTabURL($section)
	{
		return $this->getTabsRenderer()->getLastTabURL($section);
	}

	/**
	 * Display the top bar with server connection info and quick links
	 */
	function printTopbar()
	{
		return $this->getTopbarRenderer()->printTopbar();
	}

	/**
	 * Display a bread crumb trail.
	 */
	function printTrail($trail = [])
	{
		$this->printTopbar();
		return $this->getTrailRenderer()->printTrail($trail);
	}

	/**
	 * Create a bread crumb trail of the object hierarchy.
	 * @param string $subject The type of object at the end of the trail.
	 */
	function getTrail($subject = null)
	{
		return $this->getTrailRenderer()->getTrail($subject);
	}

	/**
	 * Display the navlinks
	 *
	 * @param array $navlinks - An array with the the attributes and values that
	 * will be shown. See printLinksList for array format.
	 * @param string $place - Place where the $navlinks are displayed.
	 * Like 'display-browse', where 'display' is the file (display.php)
	 * and 'browse' is the place inside that code (doBrowse).
	 * @param array $env - Associative array of defined variables in the scope
	 * of the caller. Allows to give some environment details to plugins.
	 */
	function printNavLinks($navlinks, $place, $env = [])
	{
		return $this->getNavLinksRenderer()->printNavLinks($navlinks, $place, $env);
	}


	/**
	 * Print page navigation links
	 * @param int $page Current page number
	 * @param int $pages Total number of pages
	 * @param array $gets Associative array of URL variables to include in links
	 * @param string $script (optional) The script to link to (default current script)
	 */
	function printPageNavigation($page, $pages, $gets, $script = '')
	{
		$this->getLayoutRenderer()->printPageNavigation($page, $pages, $gets, $script);
	}

	/**
	 * Displays link to the context help.
	 * @param string $str - the string that the context help is related to (already escaped)
	 * @param string $help - help section identifier
	 */
	function printHelp($str, $help)
	{
		$this->getLayoutRenderer()->printHelp($str, $help);
	}

	/**
	 * Outputs JavaScript to set default focus
	 * @param string $object eg. forms[0].username
	 */
	function setFocus($object)
	{
		echo "<script type=\"text/javascript\">\n";
		echo "document.{$object}.focus();\n";
		echo "</script>\n";
	}

	private function getTabsRenderer()
	{
		if ($this->tabsRenderer === null) {
			$this->tabsRenderer = new TabsRenderer();
		}

		return $this->tabsRenderer;
	}

	private function getTrailRenderer()
	{
		if ($this->trailRenderer === null) {
			$this->trailRenderer = new TrailRenderer();
		}

		return $this->trailRenderer;
	}

	private function getTopbarRenderer()
	{
		if ($this->topbarRenderer === null) {
			$this->topbarRenderer = new TopbarRenderer();
		}

		return $this->topbarRenderer;
	}

	private function getNavLinksRenderer()
	{
		if ($this->navLinksRenderer === null) {
			$this->navLinksRenderer = new NavLinksRenderer();
		}

		return $this->navLinksRenderer;
	}

	private function getTableRenderer()
	{
		if ($this->tableRenderer === null) {
			$this->tableRenderer = new TableRenderer();
		}

		return $this->tableRenderer;
	}

	private function getConnectionSelector()
	{
		if ($this->connectionSelector === null) {
			$this->connectionSelector = new ConnectionSelector();
		}

		return $this->connectionSelector;
	}

	private function getLayoutRenderer()
	{
		if ($this->layoutRenderer === null) {
			$this->layoutRenderer = new LayoutRenderer($this);
		}

		return $this->layoutRenderer;
	}

	private function getUrlBuilder()
	{
		if ($this->urlBuilder === null) {
			$this->urlBuilder = new UrlBuilder();
		}

		return $this->urlBuilder;
	}

	/**
	 * Outputs JavaScript to set the name of the browser window.
	 * @param string $name the window name
	 * @param bool $addServer if true (default) then the server id is
	 *        attached to the name.
	 */
	function setWindowName($name, $addServer = true)
	{
		echo "<script type=\"text/javascript\">\n";
		echo "window.name = '{$name}", ($addServer ? ':' . htmlspecialchars($_REQUEST['server']) : ''), "';\n";
		echo "</script>\n";
	}

	/**
	 * Converts a PHP.INI size variable to bytes.  Taken from publicly available
	 * function by Chris DeRose, here: http://www.php.net/manual/en/configuration.directives.php#ini.file-uploads
	 * @param string $strIniSize The PHP.INI variable
	 * @return int size in bytes, false on failure
	 */
	function inisizeToBytes($strIniSize)
	{
		// This function will take the string value of an ini 'size' parameter,
		// and return a double (64-bit float) representing the number of bytes
		// that the parameter represents. Or false if $strIniSize is unparsable.
		$a_IniParts = [];

		if (!is_string($strIniSize))
			return false;

		if (!preg_match('/^(\d+)([bkm]*)$/i', $strIniSize, $a_IniParts))
			return false;

		$nSize = (float) $a_IniParts[1];
		$strUnit = strtolower($a_IniParts[2]);

		switch ($strUnit) {
			case 'm':
				return ($nSize * (float) 1048576);
			case 'k':
				return ($nSize * (float) 1024);
			case 'b':
			default:
				return $nSize;
		}
	}


	/** Print a table
	 * @param \ADORecordSet|ArrayRecordSet $tabledata A set of records to populate the table.
	 * @param array $columns An associative array defining the columns to display.
	 *        The array keys are the field names, and the values are arrays with
	 *        the following possible keys:
	 *        'title' - The column title
	 *        'field' - The field name to use (if different from the array key)
	 *        'type'  - The type of the field (for formatting)
	 *        'params' - Additional parameters for formatting
	 *        'orderby' - The ORDER BY clause to use when sorting by this column
	 *        'align' - The alignment of the column ('left', 'right', 'center')
	 * @param array $actions An associative array defining the actions to display.
	 *        The array keys are action identifiers, and the values are arrays with
	 *        the following possible keys:
	 *        'content' - The link text
	 *        'attr'    - Additional attributes for the link
	 *        'url'     - The URL for the action (can include %s placeholders for fields)
	 *        'multiaction' - True if this action applies to multiple selected rows
	 *        'confirmation' - Confirmation message to display when action is clicked
	 * @param string $place The place where the table is displayed (for plugins)
	 * @param string $nodata Message to display when there is no data
	 * @param callable $pre_fn Function to call before rendering each row.
	 *        The function will be passed the row data as an associative array.
	 */
	function printTable($tabledata, $columns, $actions, $place, $nodata = null, $pre_fn = null, $footerrow = null)
	{
		return $this->getTableRenderer()->printTable($tabledata, $columns, $actions, $place, $nodata, $pre_fn, $footerrow);
	}

	/** Produce XML data for the browser tree
	 * @param \ADORecordSet $_treedata A set of records to populate the tree.
	 * @param array $attrs Attributes for tree items
	 *        'text' - the text for the tree node
	 *        'icon' - an icon for node
	 *        'openIcon' - an alternative icon when the node is expanded
	 *        'toolTip' - tool tip text for the node
	 *        'action' - URL to visit when single clicking the node
	 *        'iconAction' - URL to visit when single clicking the icon node
	 *        'branch' - URL for child nodes (tree XML)
	 *        'expand' - the action to return XML for the subtree
	 *        'nodata' - message to display when node has no children
	 * @param mixed $section The section where the branch is linked in the tree
	 */
	function printTree($_treedata, &$attrs, $section)
	{
		$plugin_manager = $this->pluginManager();

		$treedata = [];

		if ($_treedata->recordCount() > 0) {
			while (!$_treedata->EOF) {
				$treedata[] = $_treedata->fields;
				$_treedata->moveNext();
			}
		}

		$tree_params = [
			'treedata' => &$treedata,
			'attrs' => &$attrs,
			'section' => $section
		];

		$plugin_manager->do_hook('tree', $tree_params);

		$this->printTreeXML($treedata, $attrs, $section);
	}

	/** Generate a semantic ID for a tree node based on its text content
	 * This ensures stable node identification across page reloads
	 * @param string $text The node text to base the ID on
	 * @param string $prefix Optional prefix for the semantic ID
	 * @return string A sanitized semantic ID
	 */
	function generateSemanticTreeId($text, $prefix = '')
	{
		// Sanitize: lowercase, remove special chars, limit length
		$id = strtolower($text);
		$id = preg_replace('/[^a-z0-9_-]/', '_', $id);
		$id = preg_replace('/_+/', '_', $id);
		$id = substr($id, 0, 50);

		if ($prefix) {
			$id = $prefix . '_' . $id;
		}

		return $id;
	}

	/** Produce XML data for the browser tree
	 * @param array $treedata A set of records to populate the tree.
	 * @param array $attrs Attributes for tree items
	 *        'text' - the text for the tree node
	 *        'icon' - an icon for node
	 *        'openIcon' - an alternative icon when the node is expanded
	 *        'toolTip' - tool tip text for the node
	 *        'action' - URL to visit when single clicking the node
	 *        'iconAction' - URL to visit when single clicking the icon node
	 *        'branch' - URL for child nodes (tree XML)
	 *        'expand' - the action to return XML for the subtree
	 *        'nodata' - message to display when node has no children
	 * @param string $section Optional section context for better semantic IDs
	 */
	function printTreeXML($treedata, $attrs, $section = null)
	{
		//$conf = $this->conf();
		$lang = $this->lang();

		header("Content-Type: text/xml; charset=UTF-8");
		header("Cache-Control: no-cache");

		echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";

		echo "<tree>\n";

		if (count($treedata) > 0) {
			foreach ($treedata as $rec) {

				echo "<tree";
				echo value_xml_attr('text', $attrs['text'], $rec);
				echo value_xml_attr('action', $attrs['action'], $rec);
				echo value_xml_attr('src', $attrs['branch'], $rec);

				$icon = $this->icon(value($attrs['icon'], $rec));
				echo value_xml_attr('icon', $icon, $rec);
				echo value_xml_attr('iconaction', $attrs['iconAction'], $rec);

				if (!empty($attrs['openicon'])) {
					$icon = $this->icon(value($attrs['openIcon'], $rec));
				}
				echo value_xml_attr('openicon', $icon, $rec);

				echo value_xml_attr('tooltip', $attrs['toolTip'], $rec);

				// Generate and add a stable semantic ID for better tree persistence
				$nodeText = value($attrs['text'], $rec);
				if ($nodeText) {
					// Use tabkey if available (for navigation tabs with specific section names)
					// Otherwise use the provided section parameter
					$semanticPrefix = $section;
					if (!empty($rec['tabkey'])) {
						$semanticPrefix = $rec['tabkey'];
					}
					$semanticId = $this->generateSemanticTreeId($nodeText, $semanticPrefix);
					echo value_xml_attr('semanticid', $semanticId, $rec);
				}

				echo " />\n";
			}
		} else {
			$msg = $attrs['nodata'] ?? $lang['strnoobjects'];
			echo "<tree text=\"{$msg}\" onaction=\"tree.getSelected().getParent().reload()\" icon=\"", $this->icon('ObjectNotFound'), "\" />\n";
		}

		echo "</tree>\n";
	}

	/**
	 * @param array $tabs
	 * @return ArrayRecordSet
	 */
	function adjustTabsForTree($tabs)
	{
		$adjustedTabs = [];

		foreach ($tabs as $tabKey => $tab) {
			$skip = ($tab['hide'] ?? false) === true ||
				($tab['tree'] ?? true) === false;
			if ($skip) {
				continue;
			}
			// Preserve the tab key as the 'tabkey' field for semantic ID generation
			$tab['tabkey'] = $tabKey;
			// Decode HTML entities in the title for proper display in the tree
			$tab['title'] = html_entity_decode($tab['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

			$adjustedTabs[] = $tab;
		}

		return new ArrayRecordSet($adjustedTabs);
	}

	private static function buildIconCache()
	{
		$cache = [];

		// Themes
		foreach (glob("images/themes/*/*.{svg,png}", GLOB_BRACE) as $file) {
			$parts = explode('/', $file);
			// images/themes/<theme>/<icon>.<ext>
			$theme = $parts[2];
			$icon = pathinfo($file, PATHINFO_FILENAME);

			$cache['themes'][$theme][$icon] = $file;
		}

		// Plugins
		foreach (glob("plugins/*/images/*.{svg,png}", GLOB_BRACE) as $file) {
			$parts = explode('/', $file);
			// plugins/<plugin>/images/<icon>.<ext>
			$plugin = $parts[1];
			$icon = pathinfo($file, PATHINFO_FILENAME);

			$cache['plugins'][$plugin][$icon] = $file;
		}

		return $cache;
	}

	/**
	 * Get the path to an icon
	 * @param string|string[]|null $icon
	 * @return string
	 */
	function icon($icon)
	{
		$conf = $this->conf();
		static $cache = null;
		if (!isset($cache)) {
			$hasSessionCache = isset($_SESSION['iconCacheData']) &&
				($_SESSION['iconCacheVersion'] ?? '') === AppContainer::getAppVersion() &&
				!isset($_REQUEST['reload_icons']);
			if ($hasSessionCache) {
				$cache = $_SESSION['iconCacheData'];
			} else {
				$cache = self::buildIconCache();
				$_SESSION['iconCacheData'] = $cache;
				$_SESSION['iconCacheVersion'] = AppContainer::getAppVersion();
			}
		}
		if (!isset($icon)) {
			return '';
		}
		if (is_string($icon)) {
			// Icon from themes
			return $cache['themes'][$conf['theme']][$icon]
				?? $cache['themes']['default'][$icon]
				?? '';
		} else {
			// Icon from plugins
			return $cache['plugins'][$icon[0]][$icon[1]] ?? '';
		}
	}

	/**
	 * Get the HTML for an icon
	 * @param string|string[]|null $icon
	 * @param string $alt Alternate text for the icon
	 * @param string $class CSS class for the icon
	 * @return string|null The HTML img tag, or null if the icon was not found
	 */
	public function getIcon($icon, $alt = '', $class = '')
	{
		$iconPath = $this->icon($icon);
		if (!$iconPath) {
			return;
		}
		$altAttr = $alt ? ' alt="' . htmlspecialchars($alt) . '"' : '';
		$classAttr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';
		return '<img src="' . htmlspecialchars($iconPath) . '"' . $altAttr . $classAttr . ' />';
	}

	/**
	 * Print an icon
	 * @param string|string[]|null $icon
	 * @param string $alt Alternate text for the icon
	 * @param string $class CSS class for the icon
	 */
	public function printIcon($icon, $alt = '', $class = '')
	{
		echo $this->getIcon($icon, $alt, $class);
	}

	/**
	 * Function to escape command line parameters
	 * @param string $str The string to escape
	 * @return string The escaped string
	 */
	function escapeShellArg($str)
	{
		$lang = $this->lang();

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			// Due to annoying PHP bugs, shell arguments cannot be escaped
			// (command simply fails), so we cannot allow complex objects
			// to be dumped.
			if (preg_match('/^[_.[:alnum:]]+$/', $str))
				return $str;
			else {
				echo $lang['strcannotdumponwindows'];
				exit;
			}
		} else
			return escapeshellarg($str);
	}

	/**
	 * Function to escape command line programs
	 * @param $str The string to escape
	 * @return string The escaped string
	 */
	function escapeShellCmd($str)
	{
		$pg = $this->postgres();

		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$pg->fieldClean($str);
			return '"' . $str . '"';
		} else
			return escapeshellcmd($str);
	}

	/**
	 * Get list of servers' groups if existing in the conf
	 * @return ArrayRecordSet|array a recordset of servers' groups
	 */
	function getServersGroups($recordset = false, $group_id = false)
	{
		$conf = $this->conf();
		$lang = $this->lang();
		$grps = [];

		if (isset($conf['srv_groups'])) {
			foreach ($conf['srv_groups'] as $i => $group) {
				if (
					(($group_id === false) and (!isset($group['parents']))) /* root */
					or (
						($group_id !== false)
						and isset($group['parents'])
						and in_array($group_id, explode(
							',',
							preg_replace('/\s/', '', $group['parents'])
						))
					) /* nested group */
				)
					$grps[$i] = [
						'id' => $i,
						'desc' => $group['desc'],
						'icon' => 'Servers',
						'action' => url(
							'servers.php',
							[
								'group' => field('id')
							]
						),
						'branch' => url(
							'servers.php',
							[
								'action' => 'tree',
								'group' => $i
							]
						)
					];
			}

			if ($group_id === false)
				$grps['all'] = [
					'id' => 'all',
					'desc' => $lang['strallservers'],
					'icon' => 'Servers',
					'action' => url(
						'servers.php',
						[
							'group' => field('id')
						]
					),
					'branch' => url(
						'servers.php',
						[
							'action' => 'tree',
							'group' => 'all'
						]
					)
				];
		}

		if ($recordset) {
			return new ArrayRecordSet($grps);
		}

		return $grps;
	}


	/**
	 * Get list of servers
	 * @param bool $recordset return as RecordSet suitable for printTable if true,
	 *                   otherwise just return an array.
	 * @param string $group a group name to filter the returned servers using $conf[srv_groups]
	 */
	function getServers($recordset = false, $group = false)
	{
		$conf = $this->conf();

		$logins = isset($_SESSION['webdbLogin']) && is_array($_SESSION['webdbLogin']) ? $_SESSION['webdbLogin'] : [];
		$srvs = [];

		if (($group !== false) and ($group !== 'all'))
			if (isset($conf['srv_groups'][$group]['servers']))
				$group = array_fill_keys(explode(',', preg_replace(
					'/\s/',
					'',
					$conf['srv_groups'][$group]['servers']
				)), 1);
			else
				$group = '';

		foreach ($conf['servers'] as $idx => $info) {
			$server_id = $info['host'] . ':' . $info['port'] . ':' . $info['sslmode'];
			if (
				($group === false)
				or (isset($group[$idx]))
				or ($group === 'all')
			) {
				$server_id = $info['host'] . ':' . $info['port'] . ':' . $info['sslmode'];

				if (isset($logins[$server_id])) {
					$srvs[$server_id] = $logins[$server_id];
					if (isset($info['desc'])) {
						$srvs[$server_id]['desc'] = $info['desc'];
					}
				} else {
					$srvs[$server_id] = $info;
				}

				$srvs[$server_id]['id'] = $server_id;
				$srvs[$server_id]['action'] = url(
					'redirect.php',
					[
						'subject' => 'server',
						'server' => field('id')
					]
				);
				if (isset($srvs[$server_id]['username'])) {
					$srvs[$server_id]['icon'] = 'Server';
					$srvs[$server_id]['branch'] = url(
						'all_db.php',
						[
							'action' => 'tree',
							'subject' => 'server',
							'server' => field('id')
						]
					);
				} else {
					$srvs[$server_id]['icon'] = 'DisconnectedServer';
					$srvs[$server_id]['branch'] = false;
				}
			}
		}

		uasort($srvs, function ($a, $b) {
			return strcmp($a['desc'], $b['desc']);
		});

		//var_dump($srvs);

		if ($recordset) {
			return new ArrayRecordSet($srvs);
		}
		return $srvs;
	}

	/**
	* Validate and retrieve information on a server.
	* If the parameter isn't supplied then the currently
	* connected server is returned.
	* @param string $server_id A server identifier (host:port)
	* @return array|null An associative array of server properties
	*/
	function getServerInfo($server_id = null)
	{

		$conf = $this->conf();
		$lang = $this->lang();

		if (!isset($server_id) && isset($_REQUEST['server']))
			$server_id = $_REQUEST['server'];

		// Check for the server in the logged-in list
		if (isset($server_id) && isset($_SESSION['webdbLogin'][$server_id])) {
			$info = $_SESSION['webdbLogin'][$server_id];

			// Decrypt password if encryption is available and password is encrypted
			if (isset($info['password']) && CredentialEncryption::isAvailable($conf)) {
				try {
					// Check if password looks encrypted (base64, sufficient length)
					if (CredentialEncryption::looksEncrypted($info['password'])) {
						$info['password'] = CredentialEncryption::decrypt($info['password'], $conf);
					}
				} catch (\Exception $e) {
					// If decryption fails, leave password as-is (might be plaintext)
					// This maintains backward compatibility
				}
			}

			return $info;
		}

		// Otherwise, look for it in the conf file
		foreach ($conf['servers'] as $idx => $info) {
			if ($server_id == $info['host'] . ':' . $info['port'] . ':' . $info['sslmode']) {
				// Automatically use shared credentials if available
				if (!isset($info['username']) && isset($_SESSION['sharedUsername'])) {
					$info['username'] = $_SESSION['sharedUsername'];
					$info['password'] = $_SESSION['sharedPassword'];
					AppContainer::setShouldReloadPage(true);
					$this->setServerInfo(null, $info, $server_id);
				}

				return $info;
			}
		}

		if ($server_id === null) {
			return null;
		} else {
			// Server not found in session or config — reject the request
			header("Content-Type: text/html; charset=utf-8");
			$errortitle = htmlspecialchars($lang['strerror'], ENT_QUOTES, 'UTF-8');
			$title = htmlspecialchars($lang['strinvalidserverparam'], ENT_QUOTES, 'UTF-8');
			$backhome = htmlspecialchars($lang['strbacktohome'], ENT_QUOTES, 'UTF-8');
			$appLocale = htmlspecialchars($lang['applocale']);
			$appLangDir = htmlspecialchars($lang['applangdir']);
			$home = htmlspecialchars('index.php');
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" id="Layer_12" data-name="Layer 12" viewBox="0 0 512 512" class="error-icon"><defs><linearGradient id="linear-gradient" x1="376.2" x2="135.8" y1="376.2" y2="135.8" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ef3739"/><stop offset=".5" stop-color="#ef3739"/><stop offset="1" stop-color="#ff8c8b"/></linearGradient><linearGradient id="linear-gradient-2" x1="320.4" x2="192.3" y1="318.4" y2="190.3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ffd2d2"/><stop offset=".6" stop-color="#fff"/><stop offset="1" stop-color="#fff"/></linearGradient><radialGradient id="radial-gradient" cx="256" cy="-5704" r="200.8" fx="256" fy="-5704" gradientTransform="matrix(1 0 0 .45 0 2994.7)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ef3739" stop-opacity=".6"/><stop offset="1" stop-color="#ef3739" stop-opacity="0"/></radialGradient></defs><path d="M496.5 129.9c-13.4-49.5-64.9-101-114.4-114.4A501 501 0 0 0 256 0a510 510 0 0 0-126.1 15.5C80.4 28.9 28.9 80.4 15.5 129.9 7.9 160.2 0 200.9 0 256c.1 55.2 8 95.8 15.5 126.2C28.9 431.6 80.4 483 129.9 496.5c30.3 7.6 71 15.4 126.1 15.5 55.2-.1 95.8-8 126.2-15.5 49.4-13.4 100.9-64.9 114.3-114.4 7.6-30.3 15.4-71 15.5-126.1-.1-55.2-7.9-95.8-15.5-126.2" style="stroke-width:0;fill:#ffe5e5"/><path d="M444.7 366.2c-10.6-17.6-51-35.9-89.7-40.6a861 861 0 0 0-99-5.5c-43.3 0-75.2 2.8-99 5.5-38.8 4.7-79.1 23-89.7 40.6A91 91 0 0 0 55.2 411a91 91 0 0 0 12.1 45c10.6 17.5 51 35.8 89.7 40.6 23.8 2.7 55.7 5.5 99 5.5s75.2-2.8 99-5.5c38.8-4.8 79.1-23 89.7-40.6a91 91 0 0 0 12.1-44.9c0-19.6-6.2-34-12.1-44.8" style="stroke-width:0;fill:url(#radial-gradient)"/><g id="INFO"><path d="M256 86a170 170 0 1 0 0 340 170 170 0 0 0 0-340" style="fill:url(#linear-gradient);stroke-width:0"/><path d="M256 371.3a27.6 27.6 0 1 1 0-55.2 27.6 27.6 0 0 1 0 55.2m26.7-95.1q-.7 6.5-5.4 11.2-4.9 4.6-11.5 5.4h-.1q-8 .6-8.2.4s-4.5.1-9.8-.4h-.1q-6.7-.8-11.5-5.4c-3-3-5-7-5.4-11.2a1625 1625 0 0 1 0-118.5q.7-6.6 5.4-11.3t11.5-5.4h.1c5.3-.5 9.8-.3 9.8-.3s2.9-.2 8.2.3q6.8.8 11.6 5.4c3 3 5 7 5.4 11.2a1633 1633 0 0 1 0 118.6" style="stroke-width:0;fill:url(#linear-gradient-2)"/></g></svg>';

			echo "<!DOCTYPE html>\n";
			echo '<html lang="' . $appLocale . '" dir="' . $appLangDir . '">' . "\n";
			echo "<head>\n";
			echo '<meta charset="UTF-8">' . "\n";
			echo '<link rel="icon" type="image/svg+xml" href="images/themes/bootstrap/favicon.svg">' . "\n";
			echo "<title>{$errortitle}</title>\n";
			echo "<style>\n";
			echo "body {\n";
			echo "    margin: 0;\n";
			echo "    min-height: 100vh;\n";
			echo "    display: flex;\n";
			echo "    align-items: flex-start;\n";
			echo "    justify-content: center;\n";
			echo "    font-family: sans-serif;\n";
			echo "    background: #f5f5f5;\n";
			echo "}\n";
			echo ".error-wrap {\n";
			echo "    display: flex;\n";
			echo "    flex-direction: column;\n";
			echo "    align-items: center;\n";
			echo "    padding-top: 40px;\n";
			echo "}\n";
			echo ".error-icon {\n";
			echo "    display: block;\n";
			echo "    width: 256px;\n";
			echo "    height: 256px;\n";
			echo "    margin-bottom: 1em;\n";
			echo "}\n";
			echo ".error-box {\n";
			echo "    max-width: 480px;\n";
			echo "    padding: 2em;\n";
			echo "    background: #fff;\n";
			echo "    border: 1px solid #ddd;\n";
			echo "    border-radius: 8px;\n";
			echo "    box-shadow: 0 2px 12px rgba(0,0,0,0.08);\n";
			echo "    text-align: center;\n";
			echo "}\n";
			echo ".error-box h1 {\n";
			echo "    margin: 0 0 0.5em;\n";
			echo "    font-size: 1.3em;\n";
			echo "    color: #cc0000;\n";
			echo "}\n";
			echo ".error-box p {\n";
			echo "    margin: 0 0 1.5em;\n";
			echo "    color: #000;\n";
			echo "    line-height: 1.6;\n";
			echo "    max-width: 36em;\n";
			echo "    margin-left: auto;\n";
			echo "    margin-right: auto;\n";
			echo "}\n";
			echo ".error-box a {\n";
			echo "    display: inline-block;\n";
			echo "    padding: 0.5em 1em;\n";
			echo "    background: #1a518c;\n";
			echo "    color: #fff;\n";
			echo "    text-decoration: none;\n";
			echo "    border-radius: 4px;\n";
			echo "}\n";
			echo ".error-box a:hover {\n";
			echo "    background: #cc0000;\n";
			echo "}\n";
			echo "</style>\n";
			echo "</head>\n";
			echo "<body>\n";
			echo '<div class="error-wrap">' . "\n";
			echo $svg . "\n";
			echo '<div class="error-box">' . "\n";
			echo "<h1>{$errortitle}</h1>\n";
			echo "<p>{$title}</p>\n";
			echo "<a href=\"{$home}\">{$backhome}</a>\n";
			echo "</div>\n";
			echo "</div>\n";
			echo "</body>\n";
			echo "</html>\n";

			exit;
		}
	}

	/**
	 * Set server information.
	 * @param ?string $key parameter name to set, or null to replace all
	 *             params with the assoc-array in $value.
	 * @param ?mixed $value the new value, or null to unset the parameter
	 * @param ?string $server_id the server identifier, or null for current
	 *                   server.
	 */
	function setServerInfo($key, $value, $server_id = null)
	{
		$conf = $this->conf();

		if ($server_id === null && isset($_REQUEST['server']))
			$server_id = $_REQUEST['server'];

		if ($key === null) {
			if ($value === null) {
				unset($_SESSION['webdbLogin'][$server_id]);
			} else {
				// Encrypt password if encryption is available and value is an array with password
				if (is_array($value) && isset($value['password']) && CredentialEncryption::isAvailable($conf)) {
					try {
						// Only encrypt if it doesn't already look encrypted
						if (!CredentialEncryption::looksEncrypted($value['password'])) {
							$value['password'] = CredentialEncryption::encrypt($value['password'], $conf);
						}
					} catch (\Exception $e) {
						// If encryption fails, store plaintext (fallback for compatibility)
					}
				}
				$_SESSION['webdbLogin'][$server_id] = $value;
			}
		} else {
			if ($value === null) {
				unset($_SESSION['webdbLogin'][$server_id][$key]);
			} else {
				// Encrypt password field if encryption is available
				if ($key === 'password' && CredentialEncryption::isAvailable($conf)) {
					try {
						// Only encrypt if it doesn't already look encrypted
						if (!CredentialEncryption::looksEncrypted($value)) {
							$value = CredentialEncryption::encrypt($value, $conf);
						}
					} catch (\Exception $e) {
						// If encryption fails, store plaintext (fallback for compatibility)
					}
				}
				$_SESSION['webdbLogin'][$server_id][$key] = $value;
			}
		}
	}

	/**
	 * Set the current schema
	 * @param string $schema The schema name
	 * @return int 0 on success
	 * @return int $data->seSchema() on error
	 */
	function setCurrentSchema($schema)
	{
		$pg = $this->postgres();
		if ($pg->_schema == $schema) {
			return 0;
		}

		$status = (new SchemaActions($pg))->setSchema($schema);
		if ($status != 0)
			return $status;

		$_REQUEST['schema'] = $schema;
		$this->setHREF();
		return 0;
	}


	/**
	 * Save the given SQL statement in the history of the database and server.
	 * @param string $sql the SQL statement to save.
	 */
	function saveSqlHistory($sql, $paginate)
	{
		$server = $_REQUEST['server'];
		$database = $_REQUEST['database'];

		if (!isset($_SESSION['history'][$server][$database])) {
			$_SESSION['history'][$server][$database] = [];
		}

		$history =& $_SESSION['history'][$server][$database];

		if (!empty($history)) {
			$lastEntry = end($history);

			if (trim($lastEntry['query']) === trim($sql)) {
				return;
			}
		}

		$time = microtime(true);

		$history[] = [
			'query' => $sql,
			'paginate' => $paginate ? 't' : 'f',
			'queryid' => $time,
		];
		if (count($history) > 100) {
			array_shift($history);
		}
	}


	/*
	 * Output dropdown list to select server and
	 * databases form the popups windows.
	 * @param $onchange Javascript action to take when selections change.
	 */
	function printConnection($onchange)
	{
		return $this->getConnectionSelector()->printConnection($onchange);
	}

	/**
	 * returns an array representing FKs definition for a table, sorted by fields
	 * or by constraint.
	 * @param string $table The table to retrieve FK constraints from
	 * @param string $context Optional context ('insert' or 'search'); defaults to 'insert'
	 * @return array|false the array of FK definition:
	 *   array(
	 *     'byconstr' => array(
	 *       constrain id => array(
	 *         confrelid => foreign relation oid
	 *         f_schema => foreign schema name
	 *         f_table => foreign table name
	 *         pattnums => array of parent's fields nums
	 *         pattnames => array of parent's fields names
	 *         fattnames => array of foreign attributes names
	 *       )
	 *     ),
	 *     'byfield' => array(
	 *       attribute num => array (constraint id, ...)
	 *     ),
	 *     'code' => HTML/js code to include in the page for auto-completion
	 *   )
	 **/
	function getAutocompleteFKProperties($table, $context = 'insert')
	{
		$pg = $this->postgres();

		$fksprops = [
			'byconstr' => [],
			'byfield' => [],
			'code' => ''
		];

		$constrs = (new ConstraintActions($pg))->getConstraintsWithFields($table);

		if (!$constrs->EOF) {
			$conrelid = $constrs->fields['conrelid'];
			while (!$constrs->EOF) {
				if ($constrs->fields['contype'] == 'f') {
					if (!isset($fksprops['byconstr'][$constrs->fields['conid']])) {
						$fksprops['byconstr'][$constrs->fields['conid']] = [
							'confrelid' => $constrs->fields['confrelid'],
							'f_table' => $constrs->fields['f_table'],
							'f_schema' => $constrs->fields['f_schema'],
							'pattnums' => [],
							'pattnames' => [],
							'fattnames' => []
						];
					}

					$fksprops['byconstr'][$constrs->fields['conid']]['pattnums'][] = $constrs->fields['p_attnum'];
					$fksprops['byconstr'][$constrs->fields['conid']]['pattnames'][] = $constrs->fields['p_field'];
					$fksprops['byconstr'][$constrs->fields['conid']]['fattnames'][] = $constrs->fields['f_field'];

					if (!isset($fksprops['byfield'][$constrs->fields['p_attnum']]))
						$fksprops['byfield'][$constrs->fields['p_attnum']] = [];
					$fksprops['byfield'][$constrs->fields['p_attnum']][] = $constrs->fields['conid'];
				}
				$constrs->moveNext();
			}

			$fksprops['code'] = "<script type=\"text/javascript\">\n";
			$fksprops['code'] .= "var constrs = {};\n";
			foreach ($fksprops['byconstr'] as $conid => $props) {
				$fksprops['code'] .= "constrs.constr_{$conid} = {\n";
				$fksprops['code'] .= 'pattnums: [' . implode(',', $props['pattnums']) . "],\n";
				$fksprops['code'] .= "f_table:'" . addslashes(htmlentities($props['f_table'], ENT_QUOTES, 'UTF-8')) . "',\n";
				$fksprops['code'] .= "f_schema:'" . addslashes(htmlentities($props['f_schema'], ENT_QUOTES, 'UTF-8')) . "',\n";
				$_ = '';
				foreach ($props['pattnames'] as $n) {
					$_ .= ",'" . htmlentities($n, ENT_QUOTES, 'UTF-8') . "'";
				}
				$fksprops['code'] .= 'pattnames: [' . substr($_, 1) . "],\n";

				$_ = '';
				foreach ($props['fattnames'] as $n) {
					$_ .= ",'" . htmlentities($n, ENT_QUOTES, 'UTF-8') . "'";
				}

				$fksprops['code'] .= 'fattnames: [' . substr($_, 1) . "]\n";
				$fksprops['code'] .= "};\n";
			}

			$fksprops['code'] .= "var attrs = {};\n";
			foreach ($fksprops['byfield'] as $attnum => $cstrs) {
				$fksprops['code'] .= "attrs.attr_{$attnum} = [" . implode(',', $fksprops['byfield'][$attnum]) . "];\n";
			}

			$fksprops['code'] .= "var table='" . addslashes(htmlentities($table, ENT_QUOTES, 'UTF-8')) . "';";
			$fksprops['code'] .= "var server='" . htmlentities($_REQUEST['server'], ENT_QUOTES, 'UTF-8') . "';";
			$fksprops['code'] .= "var database='" . addslashes(htmlentities($_REQUEST['database'], ENT_QUOTES, 'UTF-8')) . "';";
			$fksprops['code'] .= "</script>\n";

			$fksprops['code'] .= '<div class="fkbg" id="fkbg-' . htmlspecialchars($context) . '"></div>';
			$fksprops['code'] .= '<div class="fklist" id="fklist-' . htmlspecialchars($context) . '"></div>';
			$fksprops['code'] .= '<script>document.addEventListener("frameLoaded", () => AutocompleteFK.init(\'' . htmlspecialchars($context) . '\'));</script>';
		} else /* we have no foreign keys on this table */
			return false;

		return $fksprops;
	}

	public function isCatalogSchema($name = null): bool
	{
		if ($name === null) {
			$name = $this->postgres()->_schema;
		}
		return $name === 'pg_catalog' || $name === 'information_schema';
	}

}