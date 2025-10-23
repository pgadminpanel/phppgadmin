<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\RoleActions;

/**
 * Manage privileges in a database
 *
 * $Id: privileges.php,v 1.45 2007/09/13 13:41:01 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Grant permissions on an object to a user
 * @param bool $confirm To show entry screen
 * @param string $mode 'grant' or 'revoke'
 * @param string $msg (optional) A message to show
 */
function doAlter($confirm, $mode, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);
	$aclActions = new AclActions($pg);

	if (!isset($_REQUEST['username']))
		$_REQUEST['username'] = [];
	if (!isset($_REQUEST['groupname']))
		$_REQUEST['groupname'] = [];
	if (!isset($_REQUEST['privilege']))
		$_REQUEST['privilege'] = [];

	if ($confirm) {
		// Get users from the database
		$users = $roleActions->getUsers();
		// Get groups from the database
		$groups = $roleActions->getGroups();

		$misc->printTrail($_REQUEST['subject']);

		switch ($mode) {
			case 'grant':
				$misc->printTitle($lang['strgrant'], 'pg.privilege.grant');
				break;
			case 'revoke':
				$misc->printTitle($lang['strrevoke'], 'pg.privilege.revoke');
				break;
		}
		$misc->printMsg($msg);

		echo "<form action=\"privileges.php\" method=\"post\">\n";
		echo "<table>\n";
		echo "<tr><th class=\"data left\">{$lang['strusers']}</th>\n";
		echo "<td class=\"data1\"><select name=\"username[]\" multiple=\"multiple\" size=\"", min(6, $users->recordCount()), "\">\n";
		while (!$users->EOF) {
			$uname = html_esc($users->fields['usename']);
			echo "<option value=\"{$uname}\"",
				in_array($users->fields['usename'], $_REQUEST['username']) ? ' selected="selected"' : '', ">{$uname}</option>\n";
			$users->moveNext();
		}
		echo "</select></td></tr>\n";
		echo "<tr><th class=\"data left\">{$lang['strgroups']}</th>\n";
		echo "<td class=\"data1\">\n";
		echo "<input type=\"checkbox\" id=\"public\" name=\"public\"", (isset($_REQUEST['public']) ? ' checked="checked"' : ''), " /><label for=\"public\">PUBLIC</label>\n";
		// Only show groups if there are groups!
		if ($groups->recordCount() > 0) {
			echo "<br /><select name=\"groupname[]\" multiple=\"multiple\" size=\"", min(6, $groups->recordCount()), "\">\n";
			while (!$groups->EOF) {
				$gname = html_esc($groups->fields['groname']);
				echo "<option value=\"{$gname}\"",
					in_array($groups->fields['groname'], $_REQUEST['groupname']) ? ' selected="selected"' : '', ">{$gname}</option>\n";
				$groups->moveNext();
			}
			echo "</select>\n";
		}
		echo "</td></tr>\n";
		echo "<tr><th class=\"data left required\">{$lang['strprivileges']}</th>\n";
		echo "<td class=\"data1\">\n";
		foreach (AclActions::PRIV_LIST[$_REQUEST['subject']] as $v) {
			$v = html_esc($v);
			echo "<input type=\"checkbox\" id=\"privilege[$v]\" name=\"privilege[$v]\"",
				isset($_REQUEST['privilege'][$v]) ? ' checked="checked"' : '', " /><label for=\"privilege[$v]\">{$v}</label><br />\n";
		}
		echo "</td></tr>\n";
		// Grant option
		if ($pg->hasGrantOption()) {
			echo "<tr><th class=\"data left\">{$lang['stroptions']}</th>\n";
			echo "<td class=\"data1\">\n";
			if ($mode == 'grant') {
				echo "<input type=\"checkbox\" id=\"grantoption\" name=\"grantoption\"",
					isset($_REQUEST['grantoption']) ? ' checked="checked"' : '', " /><label for=\"grantoption\">GRANT OPTION</label>\n";
			} elseif ($mode == 'revoke') {
				echo "<input type=\"checkbox\" id=\"grantoption\" name=\"grantoption\"",
					isset($_REQUEST['grantoption']) ? ' checked="checked"' : '', " /><label for=\"grantoption\">GRANT OPTION FOR</label><br />\n";
				echo "<input type=\"checkbox\" id=\"cascade\" name=\"cascade\"",
					isset($_REQUEST['cascade']) ? ' checked="checked"' : '', " /><label for=\"cascade\">CASCADE</label><br />\n";
			}
			echo "</td></tr>\n";
		}
		echo "</table>\n";

		echo "<p><input type=\"hidden\" name=\"action\" value=\"save\" />\n";
		echo "<input type=\"hidden\" name=\"mode\" value=\"", html_esc($mode), "\" />\n";
		echo "<input type=\"hidden\" name=\"subject\" value=\"", html_esc($_REQUEST['subject']), "\" />\n";
		if (isset($_REQUEST[$_REQUEST['subject'] . '_oid']))
			echo "<input type=\"hidden\" name=\"", html_esc($_REQUEST['subject'] . '_oid'),
				"\" value=\"", html_esc($_REQUEST[$_REQUEST['subject'] . '_oid']), "\" />\n";
		echo "<input type=\"hidden\" name=\"", html_esc($_REQUEST['subject']),
			"\" value=\"", html_esc($_REQUEST[$_REQUEST['subject']]), "\" />\n";
		if ($_REQUEST['subject'] == 'column')
			echo "<input type=\"hidden\" name=\"table\" value=\"",
				html_esc($_REQUEST['table']), "\" />\n";
		echo $misc->form;
		if ($mode == 'grant')
			echo "<input type=\"submit\" name=\"grant\" value=\"{$lang['strgrant']}\" />\n";
		elseif ($mode == 'revoke')
			echo "<input type=\"submit\" name=\"revoke\" value=\"{$lang['strrevoke']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>";
		echo "</form>\n";
	} else {
		// Determine whether object should be ref'd by name or oid.
		if (isset($_REQUEST[$_REQUEST['subject'] . '_oid']))
			$object = $_REQUEST[$_REQUEST['subject'] . '_oid'];
		else
			$object = $_REQUEST[$_REQUEST['subject']];

		if (isset($_REQUEST['table']))
			$table = $_REQUEST['table'];
		else
			$table = null;
		$status = $aclActions->setPrivileges(
			($mode == 'grant') ? 'GRANT' : 'REVOKE',
			$_REQUEST['subject'],
			$object,
			isset($_REQUEST['public']),
			$_REQUEST['username'],
			$_REQUEST['groupname'],
			array_keys($_REQUEST['privilege']),
			isset($_REQUEST['grantoption']),
			isset($_REQUEST['cascade']),
			$table
		);

		if ($status == 0)
			doDefault($lang['strgranted']);
		elseif ($status == -3 || $status == -4)
			doAlter(true, $_REQUEST['mode'], $lang['strgrantbad']);
		else
			doAlter(true, $_REQUEST['mode'], $lang['strgrantfailed']);
	}
}

/**
 * Show permissions on a database, namespace, relation, language or function
 */
function doDefault($msg = '')
{

	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$aclActions = new AclActions($pg);

	$misc->printTrail($_REQUEST['subject']);

	# @@@FIXME: This switch is just a temporary solution,
	# need a better way, maybe every type of object should
	# have a tab bar???
	switch ($_REQUEST['subject']) {
		case 'server':
		case 'database':
		case 'schema':
		case 'table':
		case 'column':
		case 'view':
			$misc->printTabs($_REQUEST['subject'], 'privileges');
			break;
		default:
			$misc->printTitle($lang['strprivileges'], 'pg.privilege');
	}
	$misc->printMsg($msg);

	// Determine whether object should be ref'd by name or oid.
	if (isset($_REQUEST[$_REQUEST['subject'] . '_oid']))
		$object = $_REQUEST[$_REQUEST['subject'] . '_oid'];
	else
		$object = $_REQUEST[$_REQUEST['subject']];

	// Get the privileges on the object, given its type
	if ($_REQUEST['subject'] == 'column')
		$privileges = $aclActions->getPrivileges($object, 'column', $_REQUEST['table']);
	else
		$privileges = $aclActions->getPrivileges($object, $_REQUEST['subject']);

	//var_dump($privileges);
	if (count($privileges) > 0) {
		echo "<div class=\"flex-row justify-content-center mt-4\">\n";
		echo "<table class=\"data\">\n";
		echo "<tr><th class=\"data\">{$lang['strrole']}</th>";

		foreach (AclActions::PRIV_LIST[$_REQUEST['subject']] as $v2) {
			// Skip over ALL PRIVILEGES
			if ($v2 == 'ALL PRIVILEGES')
				continue;
			echo "<th class=\"data\">{$v2}</th>\n";
		}
		if ($pg->hasGrantOption()) {
			echo "<th class=\"data\">{$lang['strgrantor']}</th>";
		}
		echo "</tr>\n";

		// Loop over privileges, outputting them
		$i = 0;
		foreach ($privileges as $v) {
			$id = (($i & 1) == 0 ? '1' : '2');
			echo "<tr class=\"data{$id}\">\n";
			echo "<td><img class=\"icon\" src=\"", $misc->icon('Role'), "\" alt=\"", $lang['strrole'], "\" /> <b>", $misc->formatVal($v['entity']), "</b></td>\n";
			foreach (AclActions::PRIV_LIST[$_REQUEST['subject']] as $v2) {
				// Skip over ALL PRIVILEGES
				if ($v2 == 'ALL PRIVILEGES')
					continue;
				echo "<td>";
				if (in_array($v2, $v['privileges']))
					echo $lang['stryes'];
				else
					echo $lang['strno'];
				// If we have grant option for this, end mark
				if ($pg->hasGrantOption() && in_array($v2, $v['grantable']))
					echo $lang['strasterisk'];
				echo "</td>\n";
			}
			if ($pg->hasGrantOption()) {
				echo "<td>", $misc->formatVal($v['grantor']), "</td>\n";
			}
			echo "</tr>\n";
			$i++;
		}

		echo "</table></div>\n";
	} else {
		echo "<p>{$lang['strnoprivileges']}</p>\n";
	}

	// Links for granting to a user or group
	switch ($_REQUEST['subject']) {
		//case 'table':
		//case 'view':
		case 'sequence':
		case 'function':
		case 'tablespace':
			$alllabel = "showall{$_REQUEST['subject']}s";
			$allurl = "{$_REQUEST['subject']}s.php";
			$alltxt = $lang["strshowall{$_REQUEST['subject']}s"];
			$allicon = $misc->icon(ucfirst($_REQUEST['subject']) . 's');
			break;
		/*
	case 'schema':
		$alllabel = "showallschemas";
		$allurl = "schemas.php";
		$alltxt = $lang["strshowallschemas"];
		$allicon = $misc->icon('Schemas');
		break;
		*/
		case 'database':
			$alllabel = "showalldatabases";
			$allurl = 'all_db.php';
			$alltxt = $lang['strshowalldatabases'];
			$allicon = $misc->icon('Databases');
			break;
	}

	$subject = $_REQUEST['subject'];
	$object = $_REQUEST[$_REQUEST['subject']];

	if ($_REQUEST['subject'] == 'function') {
		$objectoid = $_REQUEST[$_REQUEST['subject'] . '_oid'];
		$urlvars = [
			'action' => 'alter',
			'server' => $_REQUEST['server'],
			'database' => $_REQUEST['database'],
			'schema' => $_REQUEST['schema'],
			$subject => $object,
			"{$subject}_oid" => $objectoid,
			'subject' => $subject
		];
	} else if ($_REQUEST['subject'] == 'column') {
		$urlvars = [
			'action' => 'alter',
			'server' => $_REQUEST['server'],
			'database' => $_REQUEST['database'],
			'schema' => $_REQUEST['schema'],
			$subject => $object,
			'subject' => $subject
		];

		if (isset($_REQUEST['table']))
			$urlvars['table'] = $_REQUEST['table'];
		else
			$urlvars['view'] = $_REQUEST['view'];
	} else {
		$urlvars = [
			'action' => 'alter',
			'server' => $_REQUEST['server'],
			$subject => $object,
			'subject' => $subject
		];
		if (isset($_REQUEST['database'])) {
			$urlvars['database'] = $_REQUEST['database'];
		}
		if (isset($_REQUEST['schema'])) {
			$urlvars['schema'] = $_REQUEST['schema'];
		}
	}

	$navlinks = [
		'grant' => [
			'attr' => [
				'href' => [
					'url' => 'privileges.php',
					'urlvars' => array_merge($urlvars, ['mode' => 'grant'])
				]
			],
			'icon' => $misc->icon('GrantPrivileges'),
			'content' => $lang['strgrant']
		],
		'revoke' => [
			'attr' => [
				'href' => [
					'url' => 'privileges.php',
					'urlvars' => array_merge($urlvars, ['mode' => 'revoke'])
				]
			],
			'icon' => $misc->icon('RevokePrivileges'),
			'content' => $lang['strrevoke']
		]
	];

	if (isset($allurl)) {
		$urlvars = [
			'server' => $_REQUEST['server'],
		];
		if (isset($_REQUEST['database'])) {
			$urlvars['database'] = $_REQUEST['database'];
		}
		$navlinks[$alllabel] = [
			'attr' => [
				'href' => [
					'url' => $allurl,
					'urlvars' => $urlvars,
				]
			],
			'icon' => $allicon,
			'content' => $alltxt
		];
		if (isset($_REQUEST['schema'])) {
			$navlinks[$alllabel]['attr']['href']['urlvars']['schema'] = $_REQUEST['schema'];
		}
	}

	$misc->printNavLinks($navlinks, 'privileges-privileges', get_defined_vars());
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

$misc->printHeader($lang['strprivileges']);
$misc->printBody();

switch ($action) {
	case 'save':
		if (isset($_REQUEST['cancel']))
			doDefault();
		else
			doAlter(false, $_REQUEST['mode']);
		break;
	case 'alter':
		doAlter(true, $_REQUEST['mode']);
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
