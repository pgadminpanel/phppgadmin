<?php

use PhpPgAdmin\Core\AppContainer;

/**
 * Manage servers
 *
 * $Id: servers.php,v 1.12 2008/02/18 22:20:26 ioguix Exp $
 */

// Include application functions
$_ENV["SKIP_DB_CONNECTION"] = '1';
include_once('./libraries/bootstrap.php');

$action = $_REQUEST['action'] ?? '';
if (!isset($msg))
	$msg = '';

function doLogout()
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$plugin_manager = AppContainer::getPluginManager();

	$plugin_manager->do_hook('logout', $_REQUEST['logoutServer']);

	$server_info = $misc->getServerInfo($_REQUEST['logoutServer']);
	$misc->setServerInfo(null, null, $_REQUEST['logoutServer']);

	unset($_SESSION['sharedUsername'], $_SESSION['sharedPassword']);

	doDefault(sprintf($lang['strlogoutmsg'], $server_info['desc']));

	AppContainer::setShouldReloadTree(true);
}

function doDefault($msg = '')
{
	$conf = AppContainer::getConf();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printTabs('root', 'servers');
	$misc->printMsg($msg);
	$group = $_GET['group'] ?? false;

	$groups = $misc->getServersGroups(true, $group);

	$columns = [
		'group' => [
			'title' => $lang['strgroup'],
			'field' => field('desc'),
			'url' => 'servers.php?',
			'vars' => ['group' => 'id'],
		],
	];
	$actions = [];

	if (($group !== false) and (isset($conf['srv_groups'][$group])) and ($groups->recordCount() > 0)) {
		$misc->printTitle(sprintf($lang['strgroupgroups'], htmlentities($conf['srv_groups'][$group]['desc'], ENT_QUOTES, 'UTF-8')));
	}

	$misc->printTable($groups, $columns, $actions, 'servers-servers');

	$servers = $misc->getServers(true, $group);

	$svPre = function (&$rowdata, $actions) {
		$authType = $rowdata->fields['auth_type'] ?? '';
		$actions['logout']['disable'] = empty($rowdata->fields['username']) || $authType == 'http' || $authType == 'config';
		return $actions;
	};

	$columns = [
		'server' => [
			'icon' => 'Server',
			'title' => $lang['strserver'],
			'field' => field('desc'),
			'url' => "redirect.php?subject=server&amp;",
			'vars' => ['server' => 'id'],
		],
		'host' => [
			'title' => $lang['strhost'],
			'field' => field('host'),
		],
		'port' => [
			'title' => $lang['strport'],
			'field' => field('port'),
		],
		'username' => [
			'icon' => 'User',
			'title' => $lang['strusername'],
			'field' => field('username'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
	];

	$actions = [
		'logout' => [
			'icon' => $misc->icon('Exit'),
			'content' => $lang['strlogout'],
			'attr' => [
				'href' => [
					'url' => 'servers.php',
					'urlvars' => [
						'action' => 'logout',
						'logoutServer' => field('id')
					]
				]
			]
		],
	];

	if (($group !== false) and isset($conf['srv_groups'][$group])) {
		$misc->printTitle(
			sprintf($lang['strgroupservers'], htmlentities($conf['srv_groups'][$group]['desc'], ENT_QUOTES, 'UTF-8'))
		);
		$actions['logout']['attr']['href']['urlvars']['group'] = $group;
	}

	$misc->printTable(
		$servers,
		$columns,
		$actions,
		'servers-servers',
		$lang['strnoobjects'],
		$svPre
	);
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$conf = AppContainer::getConf();

	$nodes = [];
	$group_id = $_GET['group'] ?? false;

	/* root with srv_groups */
	if (
		isset($conf['srv_groups']) and count($conf['srv_groups']) > 0
		and $group_id === false
	) {
		$nodes = $misc->getServersGroups(true);
	} /* group subtree */ else if (isset($conf['srv_groups']) and $group_id !== false) {
		if ($group_id !== 'all')
			$nodes = $misc->getServersGroups(false, $group_id);
		$nodes = array_merge($nodes, $misc->getServers(false, $group_id));
		$nodes = new \PhpPgAdmin\Database\ArrayRecordSet($nodes);
	} /* no srv_group */ else {
		$nodes = $misc->getServers(true, false);
	}

	$reqvars = $misc->getRequestVars('server');

	$attrs = [
		'text' => field('desc'),

		// Show different icons for logged in/out
		'icon' => field('icon'),

		'toolTip' => field('id'),

		'action' => field('action'),

		// Only create a branch url if the user has
		// logged into the server.
		'branch' => field('branch'),
	];

	$misc->printTree($nodes, $attrs, 'servers');
	exit;
}


if ($action == 'tree') {
	doTree();
}

$misc->printHeader($lang['strservers']);
$misc->printBody();
$misc->printTrail('root');

switch ($action) {
	case 'logout':
		doLogout();
		break;
	default:
		doDefault($msg);
		break;
}

$misc->printFooter();
