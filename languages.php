<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\LanguageActions;

/**
 * Manage languages in a database
 *
 * $Id: languages.php,v 1.13 2007/08/31 18:30:11 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Show default list of languages in the database
 */
function doDefault($msg = '')
{
	$lang = AppContainer::getLang();
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$languageActions = new LanguageActions($pg);

	$misc->printTrail('database');
	$misc->printTabs('database', 'languages');
	$misc->printMsg($msg);

	$languages = $languageActions->getLanguages();

	$columns = [
		'language' => [
			'title' => $lang['strname'],
			'field' => field('lanname'),
		],
		'trusted' => [
			'title' => $lang['strtrusted'],
			'field' => field('lanpltrusted'),
			'type' => 'yesno',
		],
		'function' => [
			'title' => $lang['strfunction'],
			'field' => field('lanplcallf'),
		],
	];

	$actions = [];

	$misc->printTable($languages, $columns, $actions, 'languages-languages', $lang['strnolanguages']);
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$languageActions = new LanguageActions($pg);

	$languages = $languageActions->getLanguages();

	$attrs = [
		'text' => field('lanname'),
		'icon' => 'Language'
	];

	$misc->printTree($languages, $attrs, 'languages');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();

$misc->printHeader($lang['strlanguages']);
$misc->printBody();

switch ($action) {
	default:
		doDefault();
		break;
}

$misc->printFooter();
