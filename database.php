<?php

use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Gui\ExportFormRenderer;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Gui\ImportFormRenderer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\DatabaseActions;

/**
 * Manage schemas within a database
 *
 * $Id: database.php,v 1.104 2007/11/30 06:04:43 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


function _highlight($string, $term)
{
	return str_replace($term, "<b>{$term}</b>", $string);
}

/**
 * Sends a signal to a process
 */
function doSignal()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$adminActions = new AdminActions($pg);

	$status = $adminActions->sendSignal($_REQUEST['pid'], $_REQUEST['signal']);
	if ($status == 0)
		doProcesses($lang['strsignalsent']);
	else
		doProcesses($lang['strsignalsentbad']);
}

/**
 * Searches for a named database object
 */
function doFind($confirm = true, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$conf = AppContainer::getConf();

	if (!isset($_REQUEST['term']))
		$_REQUEST['term'] = '';
	if (!isset($_REQUEST['filter']))
		$_REQUEST['filter'] = '';

	$misc->printTrail('database');
	$misc->printTabs('database', 'find');
	$misc->printMsg($msg);

	?>
	<form action="database.php" method="get" name="findform">
		<?php
		// Build filter options array
		$filterOptions = [
			'' => 'strallobjects',
			'SCHEMA' => 'strschemas',
			'TABLE' => 'strtables',
			'VIEW' => 'strviews',
			'SEQUENCE' => 'strsequences',
			'COLUMN' => 'strcolumns',
			'RULE' => 'strrules',
			'INDEX' => 'strindexes',
			'TRIGGER' => 'strtriggers',
			'CONSTRAINT' => 'strconstraints',
			'FUNCTION' => 'strfunctions',
			'DOMAIN' => 'strdomains',
		];

		if ($conf['show_advanced']) {
			$filterOptions['AGGREGATE'] = 'straggregates';
			$filterOptions['TYPE'] = 'strtypes';
			$filterOptions['OPERATOR'] = 'stroperators';
			$filterOptions['OPCLASS'] = 'stropclasses';
			$filterOptions['CONVERSION'] = 'strconversions';
			$filterOptions['LANGUAGE'] = 'strlanguages';
		}
		?>
		<!-- Output list of filters.  This is complex due to all the 'has' and 'conf' feature possibilities -->
		<p>
			<select name="filter">
				<?php foreach ($filterOptions as $value => $langKey): ?>
					<option value="<?= $value; ?>" <?php if ($_REQUEST['filter'] == $value)
						  echo ' selected="selected"'; ?>>
						<?= $lang[$langKey]; ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<input name="term" value="<?= html_esc($_REQUEST['term']); ?>" size="32" maxlength="<?= $pg->_maxNameLen; ?>" />
		</p>
		<p>
			<input type="submit" value="<?= $lang['strfind']; ?>" />
			<?= $misc->form; ?>
			<input type="hidden" name="action" value="find" />
		</p>
	</form>
	<?php

	// Default focus
	$misc->setFocus('findform.term');

	// If a search term has been specified, then perform the search
	// and display the results, grouped by object type
	if ($_REQUEST['term'] != '') {
		$rs = $pg->findObject($_REQUEST['term'], $_REQUEST['filter']);
		if ($rs->recordCount() == 0) {
			echo "<p>{$lang['strnoobjects']}</p>\n";
			return;
		}
		$curr = '';
		while (!$rs->EOF) {
			// Output a new header if the current type has changed, but not if it's just changed the rule type
			if ($rs->fields['type'] != $curr) {
				// Short-circuit in the case of changing from table rules to view rules; table cols to view cols;
				// table constraints to domain constraints
				if ($rs->fields['type'] == 'RULEVIEW' && $curr == 'RULETABLE') {
					$curr = $rs->fields['type'];
				} elseif ($rs->fields['type'] == 'COLUMNVIEW' && $curr == 'COLUMNTABLE') {
					$curr = $rs->fields['type'];
				} elseif ($rs->fields['type'] == 'CONSTRAINTTABLE' && $curr == 'CONSTRAINTDOMAIN') {
					$curr = $rs->fields['type'];
				} else {
					if ($curr != '')
						echo "</ul>\n";
					$curr = $rs->fields['type'];
					echo "<h3>";
					switch ($curr) {
						case 'SCHEMA':
							echo $lang['strschemas'];
							break;
						case 'TABLE':
							echo $lang['strtables'];
							break;
						case 'VIEW':
							echo $lang['strviews'];
							break;
						case 'SEQUENCE':
							echo $lang['strsequences'];
							break;
						case 'COLUMNTABLE':
						case 'COLUMNVIEW':
							echo $lang['strcolumns'];
							break;
						case 'INDEX':
							echo $lang['strindexes'];
							break;
						case 'CONSTRAINTTABLE':
						case 'CONSTRAINTDOMAIN':
							echo $lang['strconstraints'];
							break;
						case 'TRIGGER':
							echo $lang['strtriggers'];
							break;
						case 'RULETABLE':
						case 'RULEVIEW':
							echo $lang['strrules'];
							break;
						case 'FUNCTION':
							echo $lang['strfunctions'];
							break;
						case 'TYPE':
							echo $lang['strtypes'];
							break;
						case 'DOMAIN':
							echo $lang['strdomains'];
							break;
						case 'OPERATOR':
							echo $lang['stroperators'];
							break;
						case 'CONVERSION':
							echo $lang['strconversions'];
							break;
						case 'LANGUAGE':
							echo $lang['strlanguages'];
							break;
						case 'AGGREGATE':
							echo $lang['straggregates'];
							break;
						case 'OPCLASS':
							echo $lang['stropclasses'];
							break;
					}
					echo "</h3>";
					echo "<ul>\n";
				}
			}

			switch ($curr) {
				case 'SCHEMA':
					echo "<li><a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", $misc->formatVal($rs->fields['name']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'TABLE':
					echo "<li>";
					echo "<a href=\"tables.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"redirect.php?subject=table&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;table=",
						urlencode($rs->fields['name']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'VIEW':
					echo "<li>";
					echo "<a href=\"views.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"redirect.php?subject=view&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;view=",
						urlencode($rs->fields['name']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'SEQUENCE':
					echo "<li>";
					echo "<a href=\"sequences.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"sequences.php?subject=sequence&amp;action=properties&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']),
						"&amp;sequence=", urlencode($rs->fields['name']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'COLUMNTABLE':
					echo "<li>";
					echo "<a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"tblproperties.php?subject=table&amp;{$misc->href}&amp;table=", urlencode($rs->fields['relname']), "&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['relname']), "</a>.";
					echo "<a href=\"colproperties.php?{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;table=",
						urlencode($rs->fields['relname']), "&amp;column=", urlencode($rs->fields['name']), "\">",
						_highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'COLUMNVIEW':
					echo "<li>";
					echo "<a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"viewproperties.php?subject=view&amp;{$misc->href}&amp;view=", urlencode($rs->fields['relname']), "&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['relname']), "</a>.";
					echo "<a href=\"colproperties.php?{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;view=",
						urlencode($rs->fields['relname']), "&amp;column=", urlencode($rs->fields['name']), "\">",
						_highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'INDEX':
					echo "<li>";
					echo "<a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"redirect.php?subject=table&amp;{$misc->href}&amp;table=", urlencode($rs->fields['relname']), "&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['relname']), "</a>.";
					echo "<a href=\"indexes.php?{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;table=", urlencode($rs->fields['relname']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'CONSTRAINTTABLE':
					echo "<li>";
					echo "<a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"redirect.php?subject=table&amp;{$misc->href}&amp;table=", urlencode($rs->fields['relname']), "&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['relname']), "</a>.";
					echo "<a href=\"constraints.php?{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;table=",
						urlencode($rs->fields['relname']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'CONSTRAINTDOMAIN':
					echo "<li>";
					echo "<a href=\"domains.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"domains.php?action=properties&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;domain=", urlencode($rs->fields['relname']), "\">",
						$misc->formatVal($rs->fields['relname']), '.', _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'TRIGGER':
					echo "<li>";
					echo "<a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"redirect.php?subject=table&amp;{$misc->href}&amp;table=", urlencode($rs->fields['relname']), "&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['relname']), "</a>.";
					echo "<a href=\"triggers.php?{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;table=", urlencode($rs->fields['relname']), "\">",
						_highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'RULETABLE':
					echo "<li>";
					echo "<a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"redirect.php?subject=table&amp;{$misc->href}&amp;table=", urlencode($rs->fields['relname']), "&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['relname']), "</a>.";
					echo "<a href=\"rules.php?subject=table&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;reltype=table&amp;table=",
						urlencode($rs->fields['relname']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'RULEVIEW':
					echo "<li>";
					echo "<a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"redirect.php?subject=view&amp;{$misc->href}&amp;view=", urlencode($rs->fields['relname']), "&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['relname']), "</a>.";
					echo "<a href=\"rules.php?subject=view&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;reltype=view&amp;view=",
						urlencode($rs->fields['relname']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'FUNCTION':
					echo "<li>";
					echo "<a href=\"functions.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"functions.php?action=properties&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;function=",
						urlencode($rs->fields['name']), "&amp;function_oid=", urlencode($rs->fields['oid']), "\">",
						_highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'TYPE':
					echo "<li>";
					echo "<a href=\"types.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"types.php?action=properties&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;type=",
						urlencode($rs->fields['name']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'DOMAIN':
					echo "<li>";
					echo "<a href=\"domains.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"domains.php?action=properties&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;domain=",
						urlencode($rs->fields['name']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'OPERATOR':
					echo "<li>";
					echo "<a href=\"operators.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"operators.php?action=properties&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "&amp;operator=",
						urlencode($rs->fields['name']), "&amp;operator_oid=", urlencode($rs->fields['oid']), "\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'CONVERSION':
					echo "<li>";
					echo "<a href=\"conversions.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"conversions.php?{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']),
						"\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'LANGUAGE':
					echo "<li><a href=\"languages.php?{$misc->href}\">", _highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'AGGREGATE':
					echo "<li>";
					echo "<a href=\"aggregates.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"aggregates.php?{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">",
						_highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
				case 'OPCLASS':
					echo "<li>";
					echo "<a href=\"redirect.php?subject=schema&amp;{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">", $misc->formatVal($rs->fields['schemaname']), "</a>.";
					echo "<a href=\"opclasses.php?{$misc->href}&amp;schema=", urlencode($rs->fields['schemaname']), "\">",
						_highlight($misc->formatVal($rs->fields['name']), $_REQUEST['term']), "</a></li>\n";
					break;
			}
			$rs->moveNext();
		}
		echo "</ul>\n";

		echo "<p>", $rs->recordCount(), " ", $lang['strobjects'], "</p>\n";
	}
}

/**
 * Displays options for database download
 */
function doExport($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$pg = AppContainer::getPostgres();

	$misc->printTrail('database');
	$misc->printTabs('database', 'export');
	$misc->printMsg($msg);

	$schemaActions = new SchemaActions($pg);
	$schemas = $schemaActions->getSchemas(false);
	$schemaNames = [];
	while (!$schemas->EOF) {
		$schemaNames[] = $schemas->fields['nspname'];
		;
		$schemas->moveNext();
	}

	// Use the unified DumpRenderer for the export form
	$exportRenderer = new ExportFormRenderer();
	$exportRenderer->renderExportForm('database', [
		'name' => 'schemas',
		//'icon' => 'Schema',
		//'objects' => $schemaNames,
		'objects_by_type' => [
			'schemas' => $schemaNames,
		],
	]);
}

function doImport($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$pg = AppContainer::getPostgres();

	$misc->printTrail('database');
	$misc->printTabs('database', 'import');
	$misc->printMsg($msg);

	$import = new ImportFormRenderer();
	$import->renderImportForm('database', [
		'scope_ident' => $_REQUEST['database'] ?? ''
	]);
}


/**
 * Show the current status of all database variables
 */
function doVariables()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	// Fetch the variables from the database
	$variables = $pg->getVariables();
	$misc->printTrail('database');
	$misc->printTabs('database', 'variables');

	$columns = [
		'variable' => [
			'title' => $lang['strname'],
			'field' => field('name'),
		],
		'value' => [
			'title' => $lang['strsetting'],
			'field' => field('setting'),
		],
	];

	$actions = [];

	$misc->printTable($variables, $columns, $actions, 'database-variables', $lang['strnodata']);
}

/**
 * Show all current database connections and any queries they
 * are running.
 */
function doProcesses($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printTrail('database');
	$misc->printTabs('database', 'processes');
	$misc->printMsg($msg);

	if (strlen($msg) === 0) {
		echo "<br /><a id=\"control\" href=\"\"><img src=\"" . $misc->icon('Refresh') . "\" alt=\"{$lang['strrefresh']}\" title=\"{$lang['strrefresh']}\"/>&nbsp;{$lang['strrefresh']}</a>";
	}

	echo "<div id=\"data_block\">";
	currentProcesses();
	echo "</div>";
}

function currentProcesses($isAjax = false)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);
	$adminActions = new AdminActions($pg);

	// Display prepared transactions
	if ($pg->hasPreparedXacts()) {
		echo "<h3>{$lang['strpreparedxacts']}</h3>\n";
		$prep_xacts = $pg->getPreparedXacts($_REQUEST['database']);

		$columns = [
			'transaction' => [
				'title' => $lang['strxactid'],
				'field' => field('transaction'),
			],
			'gid' => [
				'title' => $lang['strgid'],
				'field' => field('gid'),
			],
			'prepared' => [
				'title' => $lang['strstarttime'],
				'field' => field('prepared'),
			],
			'owner' => [
				'title' => $lang['strowner'],
				'field' => field('owner'),
			],
		];

		$actions = [];

		$misc->printTable($prep_xacts, $columns, $actions, 'database-processes-preparedxacts', $lang['strnodata']);
	}

	// Fetch the processes from the database
	echo "<h3>{$lang['strprocesses']}</h3>\n";
	$processes = $adminActions->getProcesses($_REQUEST['database']);

	$columns = [
		'user' => [
			'title' => $lang['strusername'],
			'field' => field('usename'),
		],
		'process' => [
			'title' => $lang['strprocess'],
			'field' => field('pid'),
		],
		'blocked' => [
			'title' => $lang['strblocked'],
			'field' => field('waiting'),
		],
		'query' => [
			'title' => $lang['strsqlrunning'],
			'field' => field('query'),
			'type' => 'sql',
		],
		'start_time' => [
			'title' => $lang['strstarttime'],
			'field' => field('query_start'),
		],
	];

	// Build possible actions for our process list
	$columns['actions'] = ['title' => $lang['stractions']];

	$actions = [];
	if ($pg->hasUserSignals() || $roleActions->isSuperUser()) {
		$actions = [
			'cancel' => [
				'icon' => $misc->icon('Cancel'),
				'content' => $lang['strcancel'],
				'attr' => [
					'href' => [
						'url' => 'database.php',
						'urlvars' => [
							'action' => 'signal',
							'signal' => 'CANCEL',
							'pid' => field('pid')
						]
					]
				]
			],
			'kill' => [
				'icon' => $misc->icon('Delete'),
				'content' => $lang['strkill'],
				'attr' => [
					'href' => [
						'url' => 'database.php',
						'urlvars' => [
							'action' => 'signal',
							'signal' => 'KILL',
							'pid' => field('pid')
						]
					]
				]
			]
		];

		// Remove actions where not supported
		if (!$pg->hasQueryKill())
			unset($actions['kill']);
		if (!$pg->hasQueryCancel())
			unset($actions['cancel']);
	}

	if (count($actions) == 0)
		unset($columns['actions']);

	$misc->printTable($processes, $columns, $actions, 'database-processes', $lang['strnodata']);

	if ($isAjax)
		exit;
}

function currentLocks($isAjax = false)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$adminActions = new AdminActions($pg);

	// Get the info from the pg_locks view
	$variables = $adminActions->getLocks();

	$columns = [
		'namespace' => [
			'title' => $lang['strschema'],
			'field' => field('nspname'),
		],
		'tablename' => [
			'title' => $lang['strtablename'],
			'field' => field('tablename'),
		],
		'vxid' => [
			'title' => $lang['strvirtualtransaction'],
			'field' => field('virtualtransaction'),
		],
		'transactionid' => [
			'title' => $lang['strtransaction'],
			'field' => field('transaction'),
		],
		'processid' => [
			'title' => $lang['strprocessid'],
			'field' => field('pid'),
		],
		'mode' => [
			'title' => $lang['strmode'],
			'field' => field('mode'),
		],
		'granted' => [
			'title' => $lang['strislockheld'],
			'field' => field('granted'),
			'type' => 'yesno',
		],
	];

	if (!$pg->hasVirtualTransactionId())
		unset($columns['vxid']);

	$actions = [];
	$misc->printTable($variables, $columns, $actions, 'database-locks', $lang['strnodata']);

	if ($isAjax)
		exit;
}

/**
 * Show the existing table locks in the current database
 */
function doLocks()
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printTrail('database');
	$misc->printTabs('database', 'locks');

	echo "<br /><a id=\"control\" href=\"\"><img src=\"" . $misc->icon('Refresh') . "\" alt=\"{$lang['strrefresh']}\" title=\"{$lang['strrefresh']}\"/>&nbsp;{$lang['strrefresh']}</a>";

	echo "<div id=\"data_block\">";
	currentLocks();
	echo "</div>";
}

/**
 * Allow execution of arbitrary SQL statements on a database
 */
function doSQL()
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$conf = AppContainer::getConf();

	if ((!isset($_SESSION['sqlquery'])) || isset($_REQUEST['new'])) {
		$_SESSION['sqlquery'] = '';
		$_REQUEST['paginate'] = '';
	}

	$paginate = $_REQUEST['paginate'] ?? '';

	$misc->printTrail('database');
	$misc->printTabs('database', 'sql');
	?>
	<p><?= $lang['strentersql']; ?></p>
	<script type="text/javascript">
		// Adjust form method based on whether the query is read-only
		let adjustSqlFormMethod = function (form) {
			const isValidReadQuery =
				!form.script.value
				&& extractSqlQueries(form.query.value).every(stmt => isReadOnlyQuery(stmt))
				&& form.query.value.length <= <?= $conf['max_get_query_length'] ?>;
			if (isValidReadQuery) {
				form.method = 'get';
			} else {
				form.method = 'post';
			}
		};
	</script>
	<form action="sql.php" name="sqlForm" onsubmit="adjustSqlFormMethod(this)" method="post" enctype="multipart/form-data">
		<div><?= $lang['strsqlquery']; ?></div>
		<div>
			<textarea class="sql-editor frame resizable bigger" style="width:100%;" rows="20" cols="50" data-mode="plpgsql"
				name="query"><?= html_esc($_SESSION['sqlquery']); ?></textarea>
		</div>

		<?php
		// Check that file uploads are enabled
		if (ini_get('file_uploads')) {
			// Don't show upload option if max size of uploads is zero
			$max_size = $misc->inisizeToBytes(ini_get('upload_max_filesize'));
			if (is_double($max_size) && $max_size > 0) {
				?>
				<input type="hidden" name="MAX_FILE_SIZE" value="<?= $max_size; ?>">
				<p>
					<label for="script"><?= $lang['struploadscript']; ?></label>
				</p>
				<p>
					<input id="script" name="script" type="file">
				</p>
				<?php
			}
		}
		?>

		<p class="flex-row">
			<span><?= $lang['strpaginate']; ?>&nbsp;&nbsp;&nbsp;</span>
			<span>
				<input data-use-in-url="t" type="radio" id="paginate-auto" name="paginate" value="" <?php if (empty($paginate))
					echo ' checked="checked"'; ?>> <label
					for="paginate-auto"><?= $lang['strauto']; ?></label>
				&nbsp;
				<input data-use-in-url="t" type="radio" id="paginate-true" name="paginate" value="t" <?php if ($paginate == 't')
					echo ' checked="checked"'; ?>> <label
					for="paginate-true"><?= $lang['stryes']; ?></label>
				&nbsp;
				<input data-use-in-url="t" type="radio" id="paginate-false" name="paginate" value="f" <?php if ($paginate == 'f')
					echo ' checked="checked"'; ?>> <label
					for="paginate-false"><?= $lang['strno']; ?></label>
			</span>
		</p>
		<p>
			<input type="submit" name="execute" accesskey="r" value="<?= $lang['strexecute']; ?>" />
			<?= $misc->form; ?>
			<input type="reset" accesskey="q" value="<?= $lang['strreset']; ?>" />
		</p>
	</form>

	<?php
	// Default focus
	$misc->setFocus('forms["sqlForm"].query');
}

function doTree()
{
	$misc = AppContainer::getMisc();

	$reqvars = $misc->getRequestVars('database');

	$tabs = $misc->getNavTabs('database');

	$items = $misc->adjustTabsForTree($tabs);

	$attrs = [
		'text' => field('title'),
		'icon' => field('icon'),
		'action' => url(
			field('url'),
			$reqvars,
			field('urlvars', [])
		),
		'branch' => url(
			field('url'),
			$reqvars,
			field('urlvars'),
			['action' => 'tree']
		),
	];

	$misc->printTree($items, $attrs, 'database');

	exit;
}

// Main program

$misc = AppContainer::getMisc();
$conf = AppContainer::getConf();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';
$scripts = '';


require __DIR__ . '/admin.php';

/* shortcuts: these functions exit the script */
if ($action == 'tree')
	doTree();
if ($action == 'refresh_locks')
	currentLocks(true);
if ($action == 'refresh_processes')
	currentProcesses(true);

/* normal flow */
if ($action == 'locks' or $action == 'processes') {
	$scripts .= "<script src=\"js/database.js\" type=\"text/javascript\"></script>";

	$refreshTime = $conf['ajax_refresh'] * 1000;

	$scripts .= "<script type=\"text/javascript\">\n";
	$scripts .= "var Database = {\n";
	$scripts .= "ajax_time_refresh: {$refreshTime},\n";
	$scripts .= "str_start: {text:'{$lang['strstart']}',icon: '" . $misc->icon('Execute') . "'},\n";
	$scripts .= "str_stop: {text:'{$lang['strstop']}',icon: '" . $misc->icon('Stop') . "'},\n";
	$scripts .= "load_icon: '" . $misc->icon('Loading') . "',\n";
	$scripts .= "server:'{$_REQUEST['server']}',\n";
	$scripts .= "dbname:'{$_REQUEST['database']}',\n";
	$scripts .= "action:'refresh_{$action}',\n";
	$scripts .= "errmsg: '" . str_replace("'", "\'", $lang['strconnectionfail']) . "'\n";
	$scripts .= "};\n";
	$scripts .= "</script>\n";
}

$misc->printHeader($lang['strdatabase'], $scripts);
$misc->printBody();

switch ($action) {
	case 'find':
		if (isset($_REQUEST['term']))
			doFind(false);
		else
			doFind(true);
		break;
	case 'sql':
		doSQL();
		break;
	case 'variables':
		doVariables();
		break;
	case 'processes':
		doProcesses();
		break;
	case 'locks':
		doLocks();
		break;
	case 'export':
		doExport();
		break;
	case 'import':
		doImport();
		break;
	case 'signal':
		doSignal();
		break;
	default:
		if (adminActions($action, 'database') === false)
			doSQL();
		break;
}

$misc->printFooter();
