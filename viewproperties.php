<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Gui\QueryExportRenderer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\ViewActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ColumnActions;
use PhpPgAdmin\Database\Actions\SchemaActions;

/**
 * List views in a database
 *
 * $Id: viewproperties.php,v 1.34 2007/12/11 14:17:17 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/** 
 * Function to save after editing a view
 */
function doSaveEdit()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$viewActions = new ViewActions($pg);

	$status = $viewActions->setView($_POST['view'], $_POST['formDefinition'], $_POST['formComment']);
	if ($status == 0)
		doDefinition($lang['strviewupdated']);
	else
		doEdit($lang['strviewupdatedbad']);
}

/**
 * Function to allow editing of a view
 */
function doEdit($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$viewActions = new ViewActions($pg);

	$misc->printTrail('view');
	$misc->printTitle($lang['stredit'], 'pg.view.alter');
	$misc->printMsg($msg);

	$viewdata = $viewActions->getView($_REQUEST['view']);

	if ($viewdata->recordCount() > 0) {

		if (!isset($_POST['formDefinition'])) {
			$_POST['formDefinition'] = $viewdata->fields['vwdefinition'];
			$_POST['formComment'] = $viewdata->fields['relcomment'];
		}

		echo "<form action=\"viewproperties.php\" method=\"post\">\n";
		echo "<table style=\"width: 100%\">\n";
		echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strdefinition']}</th>\n";
		echo "\t\t<td class=\"data1\"><textarea style=\"width: 100%;\" rows=\"20\" cols=\"50\" name=\"formDefinition\" class=\"sql-editor frame resizable high\">",
			html_esc($_POST['formDefinition']), "</textarea></td>\n\t</tr>\n";
		echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strcomment']}</th>\n";
		echo "\t\t<td class=\"data1\"><textarea rows=\"3\" cols=\"32\" name=\"formComment\">",
			html_esc($_POST['formComment']), "</textarea></td>\n\t</tr>\n";
		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"save_edit\" />\n";
		echo "<input type=\"hidden\" name=\"view\" value=\"", html_esc($_REQUEST['view']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" value=\"{$lang['stralter']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else
		echo "<p>{$lang['strnodata']}</p>\n";
}

/** 
 * Allow the dumping of the data "in" a view
 * NOTE:: PostgreSQL doesn't currently support dumping the data in a view 
 *        so I have disabled the data related parts for now. In the future 
 *        we should allow it conditionally if it becomes supported.  This is 
 *        a SMOP since it is based on pg_dump version not backend version. 
 */
function doExport($msg = '')
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();

	$misc->printTrail('view');
	$misc->printTabs('view', 'export');
	$misc->printMsg($msg);

	$schema = $pg->escapeIdentifier($_REQUEST['schema']);
	$view = $pg->escapeIdentifier($_REQUEST['view']);
	$query = "SELECT * FROM {$schema}.{$view}";
	$queryExportRenderer = new QueryExportRenderer();
	$queryExportRenderer->renderExportForm($query, [
		'subject' => 'view',
		'view' => $_REQUEST['view'],
	]);
}

/**
 * Show definition for a view
 */
function doDefinition($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$viewActions = new ViewActions($pg);

	// Get view
	$vdata = $viewActions->getView($_REQUEST['view']);

	$misc->printTrail('view');
	$misc->printTabs('view', 'definition');
	$misc->printMsg($msg);

	if ($vdata->recordCount() > 0) {
		// Show comment if any
		if ($vdata->fields['relcomment'] !== null)
			echo "<p class=\"comment\">", $misc->formatVal($vdata->fields['relcomment']), "</p>\n";

		echo "<table style=\"width: 100%\">\n";
		echo "<tr><th class=\"data\">{$lang['strdefinition']}</th></tr>\n";
		echo "<tr><td class=\"data1\"><pre class=\"sql-viewer\">", $misc->formatVal($vdata->fields['vwdefinition']), "</pre></td></tr>\n";
		echo "</table>\n";
	} else
		echo "<p>{$lang['strnodata']}</p>\n";

	$misc->printNavLinks([
		'alter' => [
			'attr' => [
				'href' => [
					'url' => 'viewproperties.php',
					'urlvars' => [
						'action' => 'edit',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'view' => $_REQUEST['view']
					]
				]
			],
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit']
		]
	], 'viewproperties-definition', get_defined_vars());
}

/**
 * Displays a screen where they can alter a column in a view
 */
function doProperties($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$columnActions = new ColumnActions($pg);

	if (!isset($_REQUEST['stage']))
		$_REQUEST['stage'] = 1;

	switch ($_REQUEST['stage']) {
		case 1:
			$misc->printTrail('column');
			$misc->printTitle($lang['stralter'], 'pg.column.alter');
			$misc->printMsg($msg);

			echo "<form action=\"viewproperties.php\" method=\"post\">\n";

			// Output view header
			echo "<table>\n";
			echo "<tr><th class=\"data required\">{$lang['strname']}</th><th class=\"data required\">{$lang['strtype']}</th>";
			echo "<th class=\"data\">{$lang['strdefault']}</th><th class=\"data\">{$lang['strcomment']}</th></tr>";

			$column = $tableActions->getTableAttributes($_REQUEST['view'], $_REQUEST['column']);

			if (!isset($_REQUEST['default'])) {
				$_REQUEST['field'] = $column->fields['attname'];
				$_REQUEST['default'] = $_REQUEST['olddefault'] = $column->fields['adsrc'];
				$_REQUEST['comment'] = $column->fields['comment'];
			}

			echo "<tr><td><input name=\"field\" size=\"32\" value=\"",
				html_esc($_REQUEST['field']), "\" /></td>";

			echo "<td>", $misc->formatVal($pg->formatType($column->fields['type'], $column->fields['atttypmod'])), "</td>";
			echo "<td><input name=\"default\" size=\"20\" value=\"",
				html_esc($_REQUEST['default']), "\" /></td>";
			echo "<td><input name=\"comment\" size=\"32\" value=\"",
				html_esc($_REQUEST['comment']), "\" /></td>";

			echo "</table>\n";
			echo "<p><input type=\"hidden\" name=\"action\" value=\"properties\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"2\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"view\" value=\"", html_esc($_REQUEST['view']), "\" />\n";
			echo "<input type=\"hidden\" name=\"column\" value=\"", html_esc($_REQUEST['column']), "\" />\n";
			echo "<input type=\"hidden\" name=\"olddefault\" value=\"", html_esc($_REQUEST['olddefault']), "\" />\n";
			echo "<input type=\"submit\" value=\"{$lang['stralter']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";

			break;
		case 2:
			// Check inputs
			if (trim($_REQUEST['field']) == '') {
				$_REQUEST['stage'] = 1;
				doProperties($lang['strcolneedsname']);
				return;
			}

			// Alter the view column
			$status = $columnActions->alterColumn(
				$_REQUEST['view'],
				$_REQUEST['column'],
				$_REQUEST['field'],
				false,
				false,
				$_REQUEST['default'],
				$_REQUEST['olddefault'],
				'',
				'',
				'',
				'',
				$_REQUEST['comment']
			);
			if ($status == 0)
				doDefault($lang['strcolumnaltered']);
			else {
				$_REQUEST['stage'] = 1;
				doProperties($lang['strcolumnalteredbad']);
				return;
			}
			break;
		default:
			echo "<p>{$lang['strinvalidparam']}</p>\n";
	}
}

function doAlter($confirm = false, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$misc = AppContainer::getMisc();
	$viewActions = new ViewActions($pg);
	$roleActions = new RoleActions($pg);
	$schemaActions = new SchemaActions($pg);

	if ($confirm) {

		$misc->printTrail('view');
		$misc->printTitle($lang['stralter'], 'pg.view.alter');
		$misc->printMsg($msg);

		// Fetch view info
		$view = $viewActions->getView($_REQUEST['view']);

		if ($view->recordCount() > 0) {
			if (!isset($_POST['name']))
				$_POST['name'] = $view->fields['relname'];
			if (!isset($_POST['owner']))
				$_POST['owner'] = $view->fields['relowner'];
			if (!isset($_POST['newschema']))
				$_POST['newschema'] = $view->fields['nspname'];
			if (!isset($_POST['comment']))
				$_POST['comment'] = $view->fields['relcomment'];

			echo "<form action=\"viewproperties.php\" method=\"post\">\n";
			echo "<table>\n";
			echo "<tr><th class=\"data left required\">{$lang['strname']}</th>\n";
			echo "<td class=\"data1\">";
			echo "<input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
				html_esc($_POST['name']), "\" /></td></tr>\n";

			if ($roleActions->isSuperUser()) {

				// Fetch all users
				$users = $roleActions->getUsers();

				echo "<tr><th class=\"data left required\">{$lang['strowner']}</th>\n";
				echo "<td class=\"data1\"><select name=\"owner\">";
				while (!$users->EOF) {
					$uname = $users->fields['usename'];
					echo "<option value=\"", html_esc($uname), "\"", ($uname == $_POST['owner']) ? ' selected="selected"' : '', ">", html_esc($uname), "</option>\n";
					$users->moveNext();
				}
				echo "</select></td></tr>\n";
			}

			if ($pg->hasAlterTableSchema()) {
				$schemas = $schemaActions->getSchemas();
				echo "<tr><th class=\"data left required\">{$lang['strschema']}</th>\n";
				echo "<td class=\"data1\"><select name=\"newschema\">";
				while (!$schemas->EOF) {
					$schema = $schemas->fields['nspname'];
					echo "<option value=\"", html_esc($schema), "\"", ($schema == $_POST['newschema']) ? ' selected="selected"' : '', ">", html_esc($schema), "</option>\n";
					$schemas->moveNext();
				}
				echo "</select></td></tr>\n";
			}

			echo "<tr><th class=\"data left\">{$lang['strcomment']}</th>\n";
			echo "<td class=\"data1\">";
			echo "<textarea rows=\"3\" cols=\"32\" name=\"comment\">",
				html_esc($_POST['comment']), "</textarea></td></tr>\n";
			echo "</table>\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"alter\" />\n";
			echo "<input type=\"hidden\" name=\"view\" value=\"", html_esc($_REQUEST['view']), "\" />\n";
			echo $misc->form;
			echo "<p><input type=\"submit\" name=\"alter\" value=\"{$lang['stralter']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
		} else
			echo "<p>{$lang['strnodata']}</p>\n";
	} else {
		AppContainer::setShouldReloadTree(true);

		// For databases that don't allow owner change
		if (!isset($_POST['owner']))
			$_POST['owner'] = '';
		if (!isset($_POST['newschema']))
			$_POST['newschema'] = null;

		$status = $viewActions->alterView($_POST['view'], $_POST['name'], $_POST['owner'], $_POST['newschema'], $_POST['comment']);
		if ($status == 0) {
			// If view has been renamed, need to change to the new name and
			// reload the browser frame.
			if ($_POST['view'] != $_POST['name']) {
				// Jump them to the new view name
				$_REQUEST['view'] = $_POST['name'];
				// Need to reload the tree
				AppContainer::setShouldReloadTree(true);
			}
			// If schema has changed, need to change to the new schema and reload the browser
			if (!empty($_POST['newschema']) && ($_POST['newschema'] != $pg->_schema)) {
				// Jump them to the new sequence schema
				$misc->setCurrentSchema($_POST['newschema']);
				AppContainer::setShouldReloadTree(true);
			}
			doDefault($lang['strviewaltered']);
		} else
			doAlter(true, $lang['strviewalteredbad']);
	}
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$tableActions = new TableActions($pg);

	$reqvars = $misc->getRequestVars('column');
	$columns = $tableActions->getTableAttributes($_REQUEST['view']);

	$attrs = [
		'text' => field('attname'),
		'action' => url(
			'colproperties.php',
			$reqvars,
			[
				'view' => $_REQUEST['view'],
				'column' => field('attname')
			]
		),
		'icon' => 'Column',
		'iconAction' => url(
			'display.php',
			$reqvars,
			[
				'view' => $_REQUEST['view'],
				'column' => field('attname'),
				'query' => replace(
					'SELECT "%column%", count(*) AS "count" FROM %view% GROUP BY "%column%" ORDER BY "%column%"',
					[
						'%column%' => field('attname'),
						'%view%' => $_REQUEST['view']
					]
				)
			]
		),
		'toolTip' => field('comment')
	];

	$misc->printTree($columns, $attrs, 'viewcolumns');

	exit;
}

/**
 * Show view definition and virtual columns
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$viewActions = new ViewActions($pg);

	function attPre($rowdata)
	{
		$pg = AppContainer::getPostgres();
		$rowdata->fields['+type'] = $pg->formatType($rowdata->fields['type'], $rowdata->fields['atttypmod']);
	}

	$misc->printTrail('view');
	$misc->printTabs('view', 'columns');
	$misc->printMsg($msg);

	// Get view
	$vdata = $viewActions->getView($_REQUEST['view']);
	// Get columns (using same method for getting a view)
	$attrs = $tableActions->getTableAttributes($_REQUEST['view']);

	// Show comment if any
	if ($vdata->fields['relcomment'] !== null)
		echo "<p class=\"comment\">", $misc->formatVal($vdata->fields['relcomment']), "</p>\n";

	$columns = [
		'column' => [
			'title' => $lang['strcolumn'],
			'field' => field('attname'),
			'url' => "colproperties.php?subject=column&amp;{$misc->href}&amp;view=" . urlencode($_REQUEST['view']) . "&amp;",
			'vars' => ['column' => 'attname'],
		],
		'type' => [
			'title' => $lang['strtype'],
			'field' => field('+type'),
		],
		'default' => [
			'title' => $lang['strdefault'],
			'field' => field('adsrc'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('comment'),
		],
	];

	$actions = [
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'viewproperties.php',
					'urlvars' => [
						'action' => 'properties',
						'view' => $_REQUEST['view'],
						'column' => field('attname')
					]
				]
			]
		],
	];

	$misc->printTable($attrs, $columns, $actions, 'viewproperties-viewproperties', null, 'attPre');

	echo "<br />\n";

	$navlinks = [
		'browse' => [
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'view' => $_REQUEST['view'],
						'subject' => 'view',
						'return' => 'view'
					]
				]
			],
			'icon' => $misc->icon('Table'),
			'content' => $lang['strbrowse']
		],
		'select' => [
			'attr' => [
				'href' => [
					'url' => 'views.php',
					'urlvars' => [
						'action' => 'confselectrows',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'view' => $_REQUEST['view']
					]
				]
			],
			'icon' => $misc->icon('Search'),
			'content' => $lang['strselect']
		],
		'drop' => [
			'attr' => [
				'href' => [
					'url' => 'views.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'view' => $_REQUEST['view']
					]
				]
			],
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop']
		],
		'alter' => [
			'attr' => [
				'href' => [
					'url' => 'viewproperties.php',
					'urlvars' => [
						'action' => 'confirm_alter',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'view' => $_REQUEST['view']
					]
				]
			],
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter']
		]
	];

	$misc->printNavLinks($navlinks, 'viewproperties-viewproperties', get_defined_vars());
}

// Main program

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();

$misc = AppContainer::getMisc();

$misc->printHeader($lang['strviews'] . ' - ' . $_REQUEST['view']);
$misc->printBody();

switch ($action) {
	case 'save_edit':
		if (isset($_POST['cancel']))
			doDefinition();
		else
			doSaveEdit();
		break;
	case 'edit':
		doEdit();
		break;
	case 'export':
		doExport();
		break;
	case 'definition':
		doDefinition();
		break;
	case 'properties':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doProperties();
		break;
	case 'alter':
		if (isset($_POST['cancel']))
			doDefault();
		elseif (isset($_POST['alter']))
			doAlter(false);
		else
			doDefault();
		break;
	case 'confirm_alter':
		doAlter(true);
		break;
	case 'drop':
		if (isset($_POST['drop']))
			doDrop(false);
		else
			doDefault();
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
