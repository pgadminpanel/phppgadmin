<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\TriggerActions;
use PhpPgAdmin\Html\XHtmlOption;
use PhpPgAdmin\Html\XHtmlSelect;

/**
 * List triggers on a table
 *
 * $Id: triggers.php,v 1.37 2007/09/19 14:42:12 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Function to save after altering a trigger
 */
function doSaveAlter()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$triggerActions = new TriggerActions($pg);

	$status = $triggerActions->alterTrigger($_POST['table'], $_POST['trigger'], $_POST['name']);
	if ($status == 0)
		doDefault($lang['strtriggeraltered']);
	else
		doAlter($lang['strtriggeralteredbad']);
}

/**
 * Function to allow altering of a trigger
 */
function doAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$triggerActions = new TriggerActions($pg);

	$misc->printTrail('trigger');
	$misc->printTitle($lang['stralter'], 'pg.trigger.alter');
	$misc->printMsg($msg);

	$triggerdata = $triggerActions->getTrigger($_REQUEST['table'], $_REQUEST['trigger']);

	if ($triggerdata->recordCount() > 0) {

		if (!isset($_POST['name']))
			$_POST['name'] = $triggerdata->fields['tgname'];

		echo "<form action=\"triggers.php\" method=\"post\">\n";
		echo "<table>\n";
		echo "<tr><th class=\"data\">{$lang['strname']}</th>\n";
		echo "<td class=\"data1\">";
		echo "<input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
			html_esc($_POST['name']), "\" />\n";
		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"alter\" />\n";
		echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		echo "<input type=\"hidden\" name=\"trigger\" value=\"", html_esc($_REQUEST['trigger']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"alter\" value=\"{$lang['strok']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else
		echo "<p>{$lang['strnodata']}</p>\n";
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$triggerActions = new TriggerActions($pg);

	if ($confirm) {
		$misc->printTrail('trigger');
		$misc->printTitle($lang['strdrop'], 'pg.trigger.drop');

		echo "<p>", sprintf(
			$lang['strconfdroptrigger'],
			$misc->formatVal($_REQUEST['trigger']),
			$misc->formatVal($_REQUEST['table'])
		), "</p>\n";

		echo "<form action=\"triggers.php\" method=\"post\">\n";
		echo "<input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		echo "<input type=\"hidden\" name=\"trigger\" value=\"", html_esc($_REQUEST['trigger']), "\" />\n";
		echo $misc->form;
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<input type=\"submit\" name=\"yes\" value=\"{$lang['stryes']}\" />\n";
		echo "<input type=\"submit\" name=\"no\" value=\"{$lang['strno']}\" />\n";
		echo "</form>\n";
	} else {
		$status = $triggerActions->dropTrigger($_POST['trigger'], $_POST['table'], isset($_POST['cascade']));
		if ($status == 0)
			doDefault($lang['strtriggerdropped']);
		else
			doDefault($lang['strtriggerdroppedbad']);
	}

}

/**
 * Show confirmation of enable trigger and perform enabling the trigger
 */
function doEnable($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$triggerActions = new TriggerActions($pg);

	if ($confirm) {
		$misc->printTrail('trigger');
		$misc->printTitle($lang['strenable'], 'pg.table.alter');

		echo "<p>", sprintf(
			$lang['strconfenabletrigger'],
			$misc->formatVal($_REQUEST['trigger']),
			$misc->formatVal($_REQUEST['table'])
		), "</p>\n";

		echo "<form action=\"triggers.php\" method=\"post\">\n";
		echo "<input type=\"hidden\" name=\"action\" value=\"enable\" />\n";
		echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		echo "<input type=\"hidden\" name=\"trigger\" value=\"", html_esc($_REQUEST['trigger']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"yes\" value=\"{$lang['stryes']}\" />\n";
		echo "<input type=\"submit\" name=\"no\" value=\"{$lang['strno']}\" />\n";
		echo "</form>\n";
	} else {
		$status = $triggerActions->enableTrigger($_POST['trigger'], $_POST['table']);
		if ($status == 0)
			doDefault($lang['strtriggerenabled']);
		else
			doDefault($lang['strtriggerenabledbad']);
	}

}

/**
 * Show confirmation of disable trigger and perform disabling the trigger
 */
function doDisable($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$triggerActions = new TriggerActions($pg);

	if ($confirm) {
		$misc->printTrail('trigger');
		$misc->printTitle($lang['strdisable'], 'pg.table.alter');

		echo "<p>", sprintf(
			$lang['strconfdisabletrigger'],
			$misc->formatVal($_REQUEST['trigger']),
			$misc->formatVal($_REQUEST['table'])
		), "</p>\n";

		echo "<form action=\"triggers.php\" method=\"post\">\n";
		echo "<input type=\"hidden\" name=\"action\" value=\"disable\" />\n";
		echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		echo "<input type=\"hidden\" name=\"trigger\" value=\"", html_esc($_REQUEST['trigger']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"yes\" value=\"{$lang['stryes']}\" />\n";
		echo "<input type=\"submit\" name=\"no\" value=\"{$lang['strno']}\" />\n";
		echo "</form>\n";
	} else {
		$status = $triggerActions->disableTrigger($_POST['trigger'], $_POST['table']);
		if ($status == 0)
			doDefault($lang['strtriggerdisabled']);
		else
			doDefault($lang['strtriggerdisabledbad']);
	}

}

/**
 * Let them create s.th.
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$triggerActions = new TriggerActions($pg);

	$misc->printTrail('table');
	$misc->printTitle($lang['strcreatetrigger'], 'pg.trigger.create');
	$misc->printMsg($msg);

	// Get all the functions that can be used in triggers
	$funcs = $triggerActions->getTriggerFunctions();
	if ($funcs->recordCount() == 0) {
		doDefault($lang['strnofunctions']);
		return;
	}

	/* Populate functions */
	$sel0 = new XHtmlSelect('formFunction');
	while (!$funcs->EOF) {
		$sel0->add(new XHtmlOption($funcs->fields['proname']));
		$funcs->moveNext();
	}

	/* Populate times */
	$sel1 = new XHtmlSelect('formExecTime');
	$sel1->set_data($triggerActions->triggerExecTimes);

	/* Populate events */
	$sel2 = new XHtmlSelect('formEvent');
	$sel2->set_data($triggerActions->triggerEvents);

	/* Populate occurrences */
	$sel3 = new XHtmlSelect('formFrequency');
	$sel3->set_data($triggerActions->triggerFrequency);

	echo "<form action=\"triggers.php\" method=\"post\">\n";
	echo "<table>\n";
	echo "<tr>\n";
	echo "		<th class=\"data\">{$lang['strname']}</th>\n";
	echo "		<th class=\"data\">{$lang['strwhen']}</th>\n";
	echo "</tr>\n";
	echo "<tr>\n";
	echo "		<td class=\"data1\"> <input type=\"text\" name=\"formTriggerName\" size=\"32\" /></td>\n";
	echo "		<td class=\"data1\"> ", $sel1->fetch(), "</td>\n";
	echo "</tr>\n";
	echo "<tr>\n";
	echo "    <th class=\"data\">{$lang['strevent']}</th>\n";
	echo "    <th class=\"data\">{$lang['strforeach']}</th>\n";
	echo "</tr>\n";
	echo "<tr>\n";
	echo "     <td class=\"data1\"> ", $sel2->fetch(), "</td>\n";
	echo "     <td class=\"data1\"> ", $sel3->fetch(), "</td>\n";
	echo "</tr>\n";
	echo "<tr><th class=\"data\"> {$lang['strfunction']}</th>\n";
	echo "<th class=\"data\"> {$lang['strarguments']}</th></tr>\n";
	echo "<tr><td class=\"data1\">", $sel0->fetch(), "</td>\n";
	echo "<td class=\"data1\">(<input type=\"text\" name=\"formTriggerArgs\" size=\"32\" />)</td>\n";
	echo "</tr></table>\n";
	echo "<p><input type=\"submit\" value=\"{$lang['strcreate']}\" />\n";
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
	echo "<input type=\"hidden\" name=\"action\" value=\"save_create\" />\n";
	echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
	echo $misc->form;
	echo "</form>\n";
}

/**
 * Actually creates the new trigger in the database
 */
function doSaveCreate()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$triggerActions = new TriggerActions($pg);

	// Check that they've given a name and a definition

	if ($_POST['formFunction'] == '')
		doCreate($lang['strtriggerneedsfunc']);
	elseif ($_POST['formTriggerName'] == '')
		doCreate($lang['strtriggerneedsname']);
	elseif ($_POST['formEvent'] == '')
		doCreate();
	else {
		$status = $triggerActions->createTrigger(
			$_POST['formTriggerName'],
			$_POST['table'],
			$_POST['formFunction'],
			$_POST['formExecTime'],
			$_POST['formEvent'],
			$_POST['formFrequency'],
			$_POST['formTriggerArgs']
		);
		if ($status == 0)
			doDefault($lang['strtriggercreated']);
		else
			doCreate($lang['strtriggercreatedbad']);
	}
}

/**
 * List all the triggers on the table
 */
function doDefault($msg = '')
{
	//global $database;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$triggerActions = new TriggerActions($pg);

	$tgPre = function (&$rowdata, $actions) {
		$pg = AppContainer::getPostgres();
		// toggle enable/disable trigger per trigger
		if ($pg->phpBool($rowdata->fields["tgenabled"])) {
			unset($actions['enable']);
		} else {
			unset($actions['disable']);
		}
		return $actions;
	};

	$misc->printTrail('table');
	$misc->printTabs('table', 'triggers');
	$misc->printMsg($msg);

	$triggers = $triggerActions->getTriggers($_REQUEST['table']);

	$columns = [
		'trigger' => [
			'title' => $lang['strname'],
			'field' => field('tgname'),
		],
		'definition' => [
			'title' => $lang['strdefinition'],
			'field' => field('tgdef'),
			'type' => 'sql',
		],
		'function' => [
			'title' => $lang['strfunction'],
			'field' => field('proproto'),
			'url' => "functions.php?action=properties&amp;server={$_REQUEST['server']}&amp;database={$_REQUEST['database']}&amp;",
			'vars' => [
				'schema' => 'pronamespace',
				'function' => 'proproto',
				'function_oid' => 'prooid',
			],
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
	];

	$actions = [
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit'],
			'attr' => [
				'href' => [
					'url' => 'triggers.php',
					'urlvars' => [
						'action' => 'confirm_alter',
						'table' => $_REQUEST['table'],
						'trigger' => field('tgname')
					]
				]
			]
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'triggers.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'table' => $_REQUEST['table'],
						'trigger' => field('tgname')
					]
				]
			]
		],
	];
	if ($pg->hasDisableTriggers()) {
		$actions['enable'] = [
			'icon' => $misc->icon('Show'),
			'content' => $lang['strenable'],
			'attr' => [
				'href' => [
					'url' => 'triggers.php',
					'urlvars' => [
						'action' => 'confirm_enable',
						'table' => $_REQUEST['table'],
						'trigger' => field('tgname')
					]
				]
			]
		];
		$actions['disable'] = [
			'icon' => $misc->icon('Hide'),
			'content' => $lang['strdisable'],
			'attr' => [
				'href' => [
					'url' => 'triggers.php',
					'urlvars' => [
						'action' => 'confirm_disable',
						'table' => $_REQUEST['table'],
						'trigger' => field('tgname')
					]
				]
			]
		];
	}

	$misc->printTable($triggers, $columns, $actions, 'triggers-triggers', $lang['strnotriggers'], $tgPre);

	$misc->printNavLinks([
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'triggers.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table']
					]
				]
			],
			'icon' => $misc->icon('CreateTrigger'),
			'content' => $lang['strcreatetrigger']
		]
	], 'triggers-triggers', get_defined_vars());
}

function doTree()
{

	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$triggerActions = new TriggerActions($pg);

	$triggers = $triggerActions->getTriggers($_REQUEST['table']);

	$reqvars = $misc->getRequestVars('table');

	$attrs = [
		'text' => field('tgname'),
		'icon' => 'Trigger',
	];

	$misc->printTree($triggers, $attrs, 'triggers');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();

$misc->printHeader(
	"{$lang['strtables']} - {$_REQUEST['table']} - {$lang['strtriggers']}"
);
$misc->printBody();

switch ($action) {
	case 'alter':
		if (isset($_POST['alter']))
			doSaveAlter();
		else
			doDefault();
		break;
	case 'confirm_alter':
		doAlter();
		break;
	case 'confirm_enable':
		doEnable(true);
		break;
	case 'confirm_disable':
		doDisable(true);
		break;
	case 'save_create':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doSaveCreate();
		break;
	case 'create':
		doCreate();
		break;
	case 'drop':
		if (isset($_POST['yes']))
			doDrop(false);
		else
			doDefault();
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	case 'enable':
		if (isset($_POST['yes']))
			doEnable(false);
		else
			doDefault();
		break;
	case 'disable':
		if (isset($_POST['yes']))
			doDisable(false);
		else
			doDefault();
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();

