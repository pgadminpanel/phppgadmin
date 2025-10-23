<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\SequenceActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ViewActions;
use PhpPgAdmin\Gui\ExportFormRenderer;
use PhpPgAdmin\Gui\ImportFormRenderer;

/**
 * Manage schemas in a database
 *
 * $Id: schemas.php,v 1.22 2007/12/15 22:57:43 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Show default list of schemas in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$schemaActions = new SchemaActions($pg);

	$misc->printTrail('database');
	$misc->printTabs('database', 'schemas');
	$misc->printMsg($msg);

	// Check that the DB actually supports schemas
	$schemas = $schemaActions->getSchemasWithSize();

	$columns = [
		'schema' => [
			'title' => $lang['strschema'],
			'field' => field('nspname'),
			'url' => "redirect.php?subject=schema&amp;{$misc->href}&amp;",
			'vars' => ['schema' => 'nspname'],
			'icon' => $misc->icon('Schema'),
			'class' => 'no-wrap',
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('nspowner'),
		],
		'size' => [
			'title' => $lang['strsize'],
			'field' => field('total_size'),
			'type' => 'prettysize',
			'class' => 'text-end',
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('nspcomment'),
		],
	];

	$footer = [
		'schema' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strschemas']}",
		],
		'owner' => [
			'text' => $lang['strtotal'],
		],
		'size' => [
			'agg' => 'sum',
			'type' => 'prettysize',
			'class' => 'text-end',
			//'params' => ['class' => 'numeric'],
		],
		'actions' => [
			'text' => '',
			'colspan' => 2,
		],
	];

	$actions = [
		'multiactions' => [
			'keycols' => ['nsp' => 'nspname'],
			'url' => 'schemas.php',
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'schemas.php',
					'urlvars' => [
						'action' => 'drop',
						'nsp' => field('nspname')
					]
				]
			],
			'multiaction' => 'drop',
		],
		'privileges' => [
			'icon' => $misc->icon('Privileges'),
			'content' => $lang['strprivileges'],
			'attr' => [
				'href' => [
					'url' => 'privileges.php',
					'urlvars' => [
						'subject' => 'schema',
						'schema' => field('nspname')
					]
				]
			]
		],
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'schemas.php',
					'urlvars' => [
						'action' => 'alter',
						'schema' => field('nspname')
					]
				]
			]
		]
	];

	$misc->printTable(
		$schemas,
		$columns,
		$actions,
		'schemas-schemas',
		$lang['strnoschemas'],
		null,
		$footer
	);

	$misc->printNavLinks([
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'schemas.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database']
					]
				]
			],
			'icon' => $misc->icon('CreateSchema'),
			'content' => $lang['strcreateschema']
		]
	], 'schemas-schemas', get_defined_vars());
}

/**
 * Displays a screen where they can enter a new schema
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);

	$server_info = $misc->getServerInfo();

	if (!isset($_POST['formName']))
		$_POST['formName'] = '';
	if (!isset($_POST['formAuth']))
		$_POST['formAuth'] = $server_info['username'];
	if (!isset($_POST['formSpc']))
		$_POST['formSpc'] = '';
	if (!isset($_POST['formComment']))
		$_POST['formComment'] = '';

	// Fetch all users from the database
	$users = $roleActions->getUsers();

	$misc->printTrail('database');
	$misc->printTitle($lang['strcreateschema'], 'pg.schema.create');
	$misc->printMsg($msg);

	echo "<form action=\"schemas.php\" method=\"post\">\n";
	echo "<table>\n";
	echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
	echo "\t\t<td class=\"data1\"><input name=\"formName\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
		html_esc($_POST['formName']), "\" /></td>\n\t</tr>\n";
	// Owner
	echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strowner']}</th>\n";
	echo "\t\t<td class=\"data1\">\n\t\t\t<select name=\"formAuth\">\n";
	while (!$users->EOF) {
		$uname = html_esc($users->fields['usename']);
		echo "\t\t\t\t<option value=\"{$uname}\"", ($uname == $_POST['formAuth']) ? ' selected="selected"' : '', ">{$uname}</option>\n";
		$users->moveNext();
	}
	echo "\t\t\t</select>\n\t\t</td>\n\t</tr>\n";
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strcomment']}</th>\n";
	echo "\t\t<td class=\"data1\"><textarea name=\"formComment\" rows=\"3\" cols=\"32\">",
		html_esc($_POST['formComment']), "</textarea></td>\n\t</tr>\n";

	echo "</table>\n";
	echo "<p>\n";
	echo "<input type=\"hidden\" name=\"action\" value=\"create\" />\n";
	echo "<input type=\"hidden\" name=\"database\" value=\"", html_esc($_REQUEST['database']), "\" />\n";
	echo $misc->form;
	echo "<input type=\"submit\" name=\"create\" value=\"{$lang['strcreate']}\" />\n";
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
	echo "</p>\n";
	echo "</form>\n";
}

/**
 * Actually creates the new schema in the database
 */
function doSaveCreate()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$schemaActions = new SchemaActions($pg);

	// Check that they've given a name
	if ($_POST['formName'] == '')
		doCreate($lang['strschemaneedsname']);
	else {
		$status = $schemaActions->createSchema(
			$_POST['formName'],
			$_POST['formAuth'],
			$_POST['formComment']
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strschemacreated']);
		} else
			doCreate($lang['strschemacreatedbad']);
	}
}

/**
 * Display a form to permit editing schema properties.
 * TODO: permit changing owner
 */
function doAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$schemaActions = new SchemaActions($pg);
	$roleActions = new RoleActions($pg);

	$schemaName = $_REQUEST['schema'] ?? '';

	$misc->printTrail('schema');
	$misc->printTitle("{$lang['stralterschema']}: $schemaName", 'pg.schema.alter');
	$misc->printMsg($msg);

	$schema = $schemaActions->getSchemaByName($schemaName);
	if ($schema->recordCount() > 0) {
		if (!isset($_POST['comment']))
			$_POST['comment'] = $schema->fields['nspcomment'];
		if (!isset($_POST['schema']))
			$_POST['schema'] = $schemaName;
		if (!isset($_POST['name']))
			$_POST['name'] = $schemaName;
		if (!isset($_POST['owner']))
			$_POST['owner'] = $schema->fields['ownername'];

		echo "<form action=\"schemas.php\" method=\"post\">\n";
		echo "<table>\n";

		echo "\t<tr>\n";
		echo "\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
		echo "\t\t<td class=\"data1\">";
		echo "\t\t\t<input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
			html_esc($_POST['name']), "\" />\n";
		echo "\t\t</td>\n";
		echo "\t</tr>\n";

		$users = $roleActions->getUsers();
		echo "<tr><th class=\"data left required\">{$lang['strowner']}</th>\n";
		echo "<td class=\"data2\"><select name=\"owner\">";
		while (!$users->EOF) {
			$uname = $users->fields['usename'];
			echo "<option value=\"", html_esc($uname), "\"", ($uname == $_POST['owner']) ? ' selected="selected"' : '', ">", html_esc($uname), "</option>\n";
			$users->moveNext();
		}
		echo "</select></td></tr>\n";

		echo "\t<tr>\n";
		echo "\t\t<th class=\"data\">{$lang['strcomment']}</th>\n";
		echo "\t\t<td class=\"data1\"><textarea cols=\"32\" rows=\"3\" name=\"comment\">", html_esc($_POST['comment']), "</textarea></td>\n";
		echo "\t</tr>\n";
		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"alter\" />\n";
		echo "<input type=\"hidden\" name=\"schema\" value=\"", html_esc($_POST['schema']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"alter\" value=\"{$lang['stralter']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		echo "<p>{$lang['strnodata']}</p>\n";
	}
}

/**
 * Save the form submission containing changes to a schema
 */
function doSaveAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$schemaActions = new SchemaActions($pg);

	$status = $schemaActions->updateSchema(
		$_POST['schema'],
		$_POST['comment'],
		$_POST['name'],
		$_POST['owner']
	);
	if ($status == 0) {
		AppContainer::setShouldReloadTree(true);
		doDefault($lang['strschemaaltered']);
	} else
		doAlter($lang['strschemaalteredbad']);
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$schemaActions = new SchemaActions($pg);

	if (empty($_REQUEST['nsp']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifyschematodrop']);
		exit();
	}

	if ($confirm) {
		$misc->printTrail('schema');
		$misc->printTitle($lang['strdrop'], 'pg.schema.drop');

		echo "<form action=\"schemas.php\" method=\"post\">\n";
		//If multi drop
		if (isset($_REQUEST['ma'])) {
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				echo '<p>', sprintf($lang['strconfdropschema'], $misc->formatVal($a['nsp'])), "</p>\n";
				echo '<input type="hidden" name="nsp[]" value="', html_esc($a['nsp']), "\" />\n";
			}
		} else {
			echo "<p>", sprintf($lang['strconfdropschema'], $misc->formatVal($_REQUEST['nsp'])), "</p>\n";
			echo "<input type=\"hidden\" name=\"nsp\" value=\"", html_esc($_REQUEST['nsp']), "\" />\n";
		}

		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo "<input type=\"hidden\" name=\"database\" value=\"", html_esc($_REQUEST['database']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		if (is_array($_POST['nsp'])) {
			$msg = '';
			$status = $pg->beginTransaction();
			if ($status == 0) {
				foreach ($_POST['nsp'] as $s) {
					$status = $schemaActions->dropSchema($s, isset($_POST['cascade']));
					if ($status == 0)
						$msg .= sprintf('%s: %s<br />', htmlentities($s, ENT_QUOTES, 'UTF-8'), $lang['strschemadropped']);
					else {
						$pg->endTransaction();
						doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($s, ENT_QUOTES, 'UTF-8'), $lang['strschemadroppedbad']));
						return;
					}
				}
			}
			if ($pg->endTransaction() == 0) {
				// Everything went fine, back to the Default page....
				AppContainer::setShouldReloadTree(true);
				doDefault($msg);
			} else
				doDefault($lang['strschemadroppedbad']);
		} else {
			$status = $schemaActions->dropSchema($_POST['nsp'], isset($_POST['cascade']));
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strschemadropped']);
			} else
				doDefault($lang['strschemadroppedbad']);
		}
	}
}

/**
 * Displays options for database download
 */
function doExport($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'export');
	$misc->printMsg($msg);

	$tableActions = new TableActions($pg);
	$viewActions = new ViewActions($pg);
	$sequenceActions = new SequenceActions($pg);

	$tableNames = [];
	$tableIcons = [];
	$tables = $tableActions->getTables(false);
	while (!$tables->EOF) {
		$tableName = $tables->fields['relname'];
		$tableNames[] = $tableName;
		$tableIcons[$tableName] = $tables->fields['relkind'] == 'p' ? 'PartitionedTable' : 'Table';
		$tables->moveNext();
	}

	$viewNames = [];
	$viewIcons = [];
	$views = $viewActions->getViews(true, true);
	while ($views && !$views->EOF) {
		$viewName = $views->fields['relname'];
		$viewNames[] = $viewName;
		$viewIcons[$viewName] = $views->fields['relkind'] == 'm' ? 'MaterializedView' : 'View';
		$views->moveNext();
	}

	$sequenceNames = [];
	$sequences = $sequenceActions->getSequences();
	while ($sequences && !$sequences->EOF) {
		$sequenceNames[] = $sequences->fields['seqname'];
		$sequences->moveNext();
	}

	// Use the unified ExportFormRenderer for the export form
	$exportRenderer = new ExportFormRenderer();
	$exportRenderer->renderExportForm('schema', [
		'name' => 'objects',
		'objects_by_type' => [
			'tables' => $tableNames,
			'sequences' => $sequenceNames,
			'views' => $viewNames,
		],
		'icons_by_type' => [
			'views' => $viewIcons,
			'tables' => $tableIcons,
		],
	]);
}

function doImport()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$schemaActions = new SchemaActions($pg);
	$rs = $schemaActions->getSchemaByName($_REQUEST['schema']);
	if (!$rs || $rs->EOF) {
		doDefault($lang['strnoschema']);
		return;
	}
	if ($schemaActions->isSystemSchema($_REQUEST['schema'])) {
		$_SESSION['webdbLastTab']['schema'] = '';
		doDefault($lang['strsystemobjectdenied']);
		return;
	}

	$misc->printTrail('database');
	$misc->printTabs('schema', 'import');
	$import = new ImportFormRenderer();
	$import->renderImportForm('schema', ['scope_ident' => $_REQUEST['schema'] ?? '']);

}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$schemaActions = new SchemaActions($pg);

	$schemas = $schemaActions->getSchemas();

	$reqvars = $misc->getRequestVars('schema');

	$attrs = [
		'text' => field('nspname'),
		'icon' => 'Schema',
		'toolTip' => field('nspcomment'),
		'action' => url(
			'redirect.php',
			$reqvars,
			[
				'subject' => 'schema',
				'schema' => field('nspname')
			]
		),
		'branch' => url(
			'schemas.php',
			$reqvars,
			[
				'action' => 'subtree',
				'schema' => field('nspname')
			]
		),
	];

	$misc->printTree($schemas, $attrs, 'schemas');

	exit;
}

function doSubTree()
{
	$misc = AppContainer::getMisc();

	$tabs = $misc->getNavTabs('schema');

	$items = $misc->adjustTabsForTree($tabs);

	$reqvars = $misc->getRequestVars('schema');

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
		)
	];

	$misc->printTree($items, $attrs, 'schema');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';
//exit;


if ($action == 'tree')
	doTree();
if ($action == 'subtree')
	doSubTree();

$misc->printHeader($lang['strschemas']);
$misc->printBody();

if (isset($_POST['cancel']))
	$action = '';

switch ($action) {
	case 'create':
		if (isset($_POST['create']))
			doSaveCreate();
		else
			doCreate();
		break;
	case 'alter':
		if (isset($_POST['alter']))
			doSaveAlter();
		else
			doAlter();
		break;
	case 'drop':
		if (isset($_POST['drop']))
			doDrop(false);
		else
			doDrop(true);
		break;
	case 'export':
		doExport();
		break;
	case 'import':
		doImport();
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
