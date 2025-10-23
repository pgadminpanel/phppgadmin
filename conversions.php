<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\TypeActions;

/**
 * Manage conversions in a database
 *
 * $Id: conversions.php,v 1.15 2007/08/31 18:30:10 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Show default list of conversions in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'conversions');
	$misc->printMsg($msg);

	$conversions = $typeActions->getConversions();

	$columns = [
		'conversion' => [
			'title' => $lang['strname'],
			'field' => field('conname'),
		],
		'source_encoding' => [
			'title' => $lang['strsourceencoding'],
			'field' => field('conforencoding'),
		],
		'target_encoding' => [
			'title' => $lang['strtargetencoding'],
			'field' => field('contoencoding'),
		],
		'default' => [
			'title' => $lang['strdefault'],
			'field' => field('condefault'),
			'type' => 'yesno',
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('concomment'),
		],
	];

	$actions = [];

	$misc->printTable($conversions, $columns, $actions, 'conversions-conversions', $lang['strnoconversions']);
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$typeActions = new TypeActions($pg);

	$conversions = $typeActions->getConversions();

	$attrs = [
		'text' => field('conname'),
		'icon' => 'Conversion',
		'toolTip' => field('concomment')
	];

	$misc->printTree($conversions, $attrs, 'conversions');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';


if ($action == 'tree')
	doTree();

$misc->printHeader($lang['strconversions']);
$misc->printBody();

switch ($action) {
	default:
		doDefault();
		break;
}

$misc->printFooter();


