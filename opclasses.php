<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\OperatorClassActions;

/**
 * Manage opclasss in a database
 *
 * $Id: opclasses.php,v 1.10 2007/08/31 18:30:11 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Show default list of opclasss in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$opClassActions = new OperatorClassActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'opclasses');
	$misc->printMsg($msg);

	$opClasses = $opClassActions->getOpClasses();

	$columns = [
		'accessmethod' => [
			'title' => $lang['straccessmethod'],
			'field' => field('amname'),
		],
		'opclass' => [
			'title' => $lang['strname'],
			'field' => field('opcname'),
		],
		'type' => [
			'title' => $lang['strtype'],
			'field' => field('opcintype'),
		],
		'default' => [
			'title' => $lang['strdefault'],
			'field' => field('opcdefault'),
			'type' => 'yesno',
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('opcowner'),
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('opccomment'),
		],
	];

	$footer = [
		'accessmethod' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['stropclasses']}",
			'colspan' => 4,
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 2,
		],
	];

	$actions = [];

	$misc->printTable(
		$opClasses,
		$columns,
		$actions,
		'opclasses-opclasses',
		$lang['strnoopclasses'],
		null,
		$footer
	);
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$opClassActions = new OperatorClassActions($pg);

	$opClasses = $opClassActions->getOpClasses();

	// OpClass prototype: "op_class/access_method"
	$proto = concat(field('opcname'), '/', field('amname'));

	$attrs = [
		'text' => $proto,
		'icon' => 'OperatorClass',
		'toolTip' => field('opccomment'),
	];

	$misc->printTree($opClasses, $attrs, 'opclasses');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();

$misc->printHeader($lang['stropclasses']);
$misc->printBody();

switch ($action) {
	default:
		doDefault();
		break;
}

$misc->printFooter();


