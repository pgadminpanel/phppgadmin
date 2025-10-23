<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\FtsActions;
use PhpPgAdmin\Gui\FormRenderer;

/**
 * Manage fulltext configurations, dictionaries and mappings
 *
 * $Id: fulltext.php,v 1.6 2008/03/17 21:35:48 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'fulltext');
	$misc->printTabs('fulltext', 'ftsconfigs');
	$misc->printMsg($msg);

	$cfgs = $ftsActions->getFtsConfigurations(false);

	$columns = [
		'configuration' => [
			'title' => $lang['strftsconfig'],
			'field' => field('name'),
			'url' => "fulltext.php?action=viewconfig&amp;{$misc->href}&amp;",
			'vars' => ['ftscfg' => 'name'],
			'icon' => $misc->icon('FtsCfg'),
		],
		'schema' => [
			'title' => $lang['strschema'],
			'field' => field('schema'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('cfgowner'),
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('comment'),
		],
	];

	$footer = [
		'configuration' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strftsconfigs']}",
			'colspan' => 3,
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 2,
		],
	];

	$actions = [
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'dropconfig',
						'ftscfg' => field('name')
					]
				]
			]
		],
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'alterconfig',
						'ftscfg' => field('name')
					]
				]
			]
		],
	];

	$isSchemaCatalog = $misc->isCatalogSchema();
	if ($isSchemaCatalog) {
		$actions = [];
		unset($columns['actions']);
	}

	$misc->printTable(
		$cfgs,
		$columns,
		$actions,
		'fulltext-fulltext',
		$lang['strftsnoconfigs'],
		null,
		$footer
	);

	$navlinks = [
		'createconf' => [
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'createconfig',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateFtsCfg'),
			'content' => $lang['strftscreateconfig']
		]
	];

	if (!$isSchemaCatalog) {
		$misc->printNavLinks($navlinks, 'fulltext-fulltext', get_defined_vars());
	}
}

function doDropConfig($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	if ($confirm) {
		$misc->printTrail('ftscfg');
		$misc->printTitle($lang['strdrop'], 'pg.ftscfg.drop');

		echo "<p>", sprintf($lang['strconfdropftsconfig'], $misc->formatVal($_REQUEST['ftscfg'])), "</p>\n";

		echo "<form action=\"fulltext.php\" method=\"post\">\n";
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"dropconfig\" />\n";
		echo "<input type=\"hidden\" name=\"database\" value=\"", html_esc($_REQUEST['database']), "\" />\n";
		echo "<input type=\"hidden\" name=\"ftscfg\" value=\"", html_esc($_REQUEST['ftscfg']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		$status = $ftsActions->dropFtsConfiguration(
			$_POST['ftscfg'],
			isset($_POST['cascade'])
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strftsconfigdropped']);
		} else
			doDefault($lang['strftsconfigdroppedbad']);
	}
}

function doDropDict($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	if ($confirm) {
		$misc->printTrail('ftscfg'); // TODO: change to smth related to dictionary
		$misc->printTitle($lang['strdrop'], 'pg.ftsdict.drop');

		echo "<p>", sprintf($lang['strconfdropftsdict'], $misc->formatVal($_REQUEST['ftsdict'])), "</p>\n";

		echo "<form action=\"fulltext.php\" method=\"post\">\n";
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"dropdict\" />\n";
		echo "<input type=\"hidden\" name=\"database\" value=\"", html_esc($_REQUEST['database']), "\" />\n";
		echo "<input type=\"hidden\" name=\"ftsdict\" value=\"", html_esc($_REQUEST['ftsdict']), "\" />\n";
		//echo "<input type=\"hidden\" name=\"ftscfg\" value=\"", html_esc($_REQUEST['ftscfg']), "\" />\n";
		echo "<input type=\"hidden\" name=\"prev_action\" value=\"viewdicts\" /></p>\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		$status = $ftsActions->dropFtsDictionary(
			$_POST['ftsdict'],
			isset($_POST['cascade'])
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doViewDicts($lang['strftsdictdropped']);
		} else
			doViewDicts($lang['strftsdictdroppedbad']);
	}
}

/**
 * Displays a screen where one can enter a new FTS configuration
 */
function doCreateConfig($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);
	$formRenderer = new FormRenderer();

	//$server_info = $misc->getServerInfo();

	if (!isset($_POST['formName']))
		$_POST['formName'] = '';
	if (!isset($_POST['formParser']))
		$_POST['formParser'] = '';
	if (!isset($_POST['formTemplate']))
		$_POST['formTemplate'] = '';
	if (!isset($_POST['formWithMap']))
		$_POST['formWithMap'] = '';
	if (!isset($_POST['formComment']))
		$_POST['formComment'] = '';

	// Fetch all FTS configurations from the database
	$ftscfgs = $ftsActions->getFtsConfigurations();
	// Fetch all FTS parsers from the database
	$ftsparsers = $ftsActions->getFtsParsers();

	$misc->printTrail('schema');
	$misc->printTitle($lang['strftscreateconfig'], 'pg.ftscfg.create');
	$misc->printMsg($msg);

	echo "<form action=\"fulltext.php\" method=\"post\">\n";
	echo "<table>\n";
	/* conf name */
	echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
	echo "\t\t<td class=\"data1\"><input name=\"formName\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
		html_esc($_POST['formName']), "\" /></td>\n\t</tr>\n";

	// Template
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strftstemplate']}</th>\n";
	echo "\t\t<td class=\"data1\">";

	$tpls = [];
	$tplsel = '';
	while (!$ftscfgs->EOF) {
		$pg->fieldClean($ftscfgs->fields['schema']);
		$pg->fieldClean($ftscfgs->fields['name']);
		$tplname = $ftscfgs->fields['schema'] . '.' . $ftscfgs->fields['name'];
		$tpls[$tplname] = serialize([
			'name' => $ftscfgs->fields['name'],
			'schema' => $ftscfgs->fields['schema']
		]);
		if ($_POST['formTemplate'] == $tpls[$tplname]) {
			$tplsel = html_esc($tpls[$tplname]);
		}
		$ftscfgs->moveNext();
	}
	echo $formRenderer->printCombo(
		$tpls,
		'formTemplate',
		true,
		$tplsel,
		false
	);
	echo "\n\t\t</td>\n\t</tr>\n";

	// Parser
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strftsparser']}</th>\n";
	echo "\t\t<td class=\"data1\">\n";
	$ftsparsers_ = [];
	$ftsparsel = '';
	while (!$ftsparsers->EOF) {
		$pg->fieldClean($ftsparsers->fields['schema']);
		$pg->fieldClean($ftsparsers->fields['name']);
		$parsername = $ftsparsers->fields['schema'] . '.' . $ftsparsers->fields['name'];

		$ftsparsers_[$parsername] = serialize([
			'parser' => $ftsparsers->fields['name'],
			'schema' => $ftsparsers->fields['schema']
		]);
		if ($_POST['formParser'] == $ftsparsers_[$parsername]) {
			$ftsparsel = html_esc($ftsparsers_[$parsername]);
		}
		$ftsparsers->moveNext();
	}
	echo $formRenderer->printCombo(
		$ftsparsers_,
		'formParser',
		true,
		$ftsparsel,
		false
	);
	echo "\n\t\t</td>\n\t</tr>\n";

	// Comment
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strcomment']}</th>\n";
	echo "\t\t<td class=\"data1\"><textarea name=\"formComment\" rows=\"3\" cols=\"32\">",
		html_esc($_POST['formComment']), "</textarea></td>\n\t</tr>\n";

	echo "</table>\n";
	echo "<p>\n";
	echo "<input type=\"hidden\" name=\"action\" value=\"createconfig\" />\n";
	echo "<input type=\"hidden\" name=\"database\" value=\"", html_esc($_REQUEST['database']), "\" />\n";
	echo $misc->form;
	echo "<input type=\"submit\" name=\"create\" value=\"{$lang['strcreate']}\" />\n";
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
	echo "</p>\n";
	echo "</form>\n";
}

/**
 * Actually creates the new FTS configuration in the database
 */
function doSaveCreateConfig()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$err = '';
	// Check that they've given a name
	if ($_POST['formName'] == '')
		$err .= "{$lang['strftsconfigneedsname']}<br />";
	if (($_POST['formParser'] != '') && ($_POST['formTemplate'] != ''))
		$err .= "{$lang['strftscantparsercopy']}<br />";

	if ($err != '')
		return doCreateConfig($err);

	if ($_POST['formParser'] != '')
		$formParser = unserialize($_POST['formParser']);
	else
		$formParser = '';
	if ($_POST['formTemplate'] != '')
		$formTemplate = unserialize($_POST['formTemplate']);
	else
		$formTemplate = '';

	$status = $ftsActions->createFtsConfiguration(
		$_POST['formName'],
		$formParser,
		$formTemplate,
		$_POST['formComment']
	);
	if ($status == 0) {
		AppContainer::setShouldReloadTree(true);
		doDefault($lang['strftsconfigcreated']);
	} else
		doCreateConfig($lang['strftsconfigcreatedbad']);
}

/**
 * Display a form to permit editing FTS configuration properties.
 */
function doAlterConfig($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$misc->printTrail('ftscfg');
	$misc->printTitle($lang['stralter'], 'pg.ftscfg.alter');
	$misc->printMsg($msg);

	$ftscfg = $ftsActions->getFtsConfigurationByName($_REQUEST['ftscfg']);
	if ($ftscfg->recordCount() > 0) {
		if (!isset($_POST['formComment']))
			$_POST['formComment'] = $ftscfg->fields['comment'];
		if (!isset($_POST['ftscfg']))
			$_POST['ftscfg'] = $_REQUEST['ftscfg'];
		if (!isset($_POST['formName']))
			$_POST['formName'] = $_REQUEST['ftscfg'];
		if (!isset($_POST['formParser']))
			$_POST['formParser'] = '';

		// Fetch all FTS parsers from the database
		$ftsparsers = $ftsActions->getFtsParsers();

		echo "<form action=\"fulltext.php\" method=\"post\">\n";
		echo "<table>\n";

		echo "\t<tr>\n";
		echo "\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
		echo "\t\t<td class=\"data1\">";
		echo "\t\t\t<input name=\"formName\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
			html_esc($_POST['formName']), "\" />\n";
		echo "\t\t</td>\n";
		echo "\t</tr>\n";

		// Comment
		echo "\t<tr>\n";
		echo "\t\t<th class=\"data\">{$lang['strcomment']}</th>\n";
		echo "\t\t<td class=\"data1\"><textarea cols=\"32\" rows=\"3\"name=\"formComment\">", html_esc($_POST['formComment']), "</textarea></td>\n";
		echo "\t</tr>\n";
		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"alterconfig\" />\n";
		echo "<input type=\"hidden\" name=\"ftscfg\" value=\"", html_esc($_POST['ftscfg']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"alter\" value=\"{$lang['stralter']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		echo "<p>{$lang['strnodata']}</p>\n";
	}
}

/**
 * Save the form submission containing changes to a FTS configuration
 */
function doSaveAlterConfig()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$status = $ftsActions->updateFtsConfiguration(
		$_POST['ftscfg'],
		$_POST['formComment'],
		$_POST['formName']
	);
	if ($status == 0)
		doDefault($lang['strftsconfigaltered']);
	else
		doAlterConfig($lang['strftsconfigalteredbad']);
}

/**
 * View list of FTS parsers
 */
function doViewParsers($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'fulltext');
	$misc->printTabs('fulltext', 'ftsparsers');
	$misc->printMsg($msg);

	$parsers = $ftsActions->getFtsParsers(false);

	$columns = [
		'name' => [
			'title' => $lang['strname'],
			'field' => field('name'),
			'icon' => $misc->icon('FtsParser'),
		],
		'schema' => [
			'title' => $lang['strschema'],
			'field' => field('schema'),
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('comment'),
		],
	];

	$footer = [
		'name' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strftsparsers']}",
			'colspan' => 2,
		],
		'comment' => [
			'text' => $lang['strtotal'],
			'colspan' => 1,
		],
	];

	$actions = [];

	$misc->printTable(
		$parsers,
		$columns,
		$actions,
		'fulltext-viewparsers',
		$lang['strftsnoparsers'],
		null,
		$footer
	);

	//TODO: navlink to "create parser"
}


/**
 * View list of FTS dictionaries
 */
function doViewDicts($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'fulltext');
	$misc->printTabs('fulltext', 'ftsdicts');
	$misc->printMsg($msg);

	$dicts = $ftsActions->getFtsDictionaries(false);

	$columns = [
		'name' => [
			'title' => $lang['strname'],
			'field' => field('name'),
			'icon' => $misc->icon('FtsDict'),
		],
		'schema' => [
			'title' => $lang['strschema'],
			'field' => field('schema'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('dictowner'),
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('comment'),
		],
	];

	$footer = [
		'name' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strftsdicts']}",
			'colspan' => 3,
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 2,
		],
	];

	$actions = [
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'dropdict',
						'ftsdict' => field('name')
					]
				]
			]
		],
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'alterdict',
						'ftsdict' => field('name')
					]
				]
			]
		],
	];

	$isSchemaCatalog = $misc->isCatalogSchema();
	if ($isSchemaCatalog) {
		$actions = [];
		unset($columns['actions']);
	}

	$misc->printTable(
		$dicts,
		$columns,
		$actions,
		'fulltext-viewdicts',
		$lang['strftsnodicts'],
		null,
		$footer
	);

	$navlinks = [
		'createdict' => [
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'createdict',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
					]
				]
			],
			'icon' => $misc->icon('CreateFtsDict'),
			'content' => $lang['strftscreatedict']
		]
	];

	if (!$isSchemaCatalog) {
		$misc->printNavLinks($navlinks, 'fulltext-viewdicts', get_defined_vars());
	}
}


/**
 * View details of FTS configuration given
 */
function doViewConfig($ftscfg, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$misc->printTrail('ftscfg');
	$misc->printTabs('schema', 'fulltext');
	$misc->printTabs('fulltext', 'ftsconfigs');
	$misc->printMsg($msg);

	echo "<h3>{$lang['strftsconfigmap']}</h3>\n";

	$map = $ftsActions->getFtsConfigurationMap($ftscfg);

	$columns = [
		'name' => [
			'title' => $lang['strftsmapping'],
			'field' => field('name'),
		],
		'dictionaries' => [
			'title' => $lang['strftsdicts'],
			'field' => field('dictionaries'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('description'),
		],
	];

	$actions = [
		'drop' => [
			'multiaction' => 'dropmapping',
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'dropmapping',
						'mapping' => field('name'),
						'ftscfg' => field('cfgname')
					]
				]
			]
		],
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'altermapping',
						'mapping' => field('name'),
						'ftscfg' => field('cfgname')
					]
				]
			]
		],
		'multiactions' => [
			'keycols' => ['mapping' => 'name'],
			'url' => 'fulltext.php',
			'default' => null,
			'vars' => ['ftscfg' => $ftscfg],
		],

	];

	$isCatalogSchema = $misc->isCatalogSchema();
	if ($isCatalogSchema) {
		$actions = [];
		unset($columns['actions']);
	}

	$misc->printTable($map, $columns, $actions, 'fulltext-viewconfig', $lang['strftsemptymap']);

	$navlinks = [
		'addmapping' => [
			'attr' => [
				'href' => [
					'url' => 'fulltext.php',
					'urlvars' => [
						'action' => 'addmapping',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'ftscfg' => $ftscfg
					]
				]
			],
			'content' => $lang['strftsaddmapping']
		]
	];

	if (!$isCatalogSchema) {
		$misc->printNavLinks($navlinks, 'fulltext-viewconfig', get_defined_vars());
	}
}

/**
 * Displays a screen where one can enter a details of a new FTS dictionary
 */
function doCreateDict($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	//$server_info = $misc->getServerInfo();

	if (!isset($_POST['formName']))
		$_POST['formName'] = '';
	if (!isset($_POST['formIsTemplate']))
		$_POST['formIsTemplate'] = false;
	if (!isset($_POST['formTemplate']))
		$_POST['formTemplate'] = '';
	if (!isset($_POST['formLexize']))
		$_POST['formLexize'] = '';
	if (!isset($_POST['formInit']))
		$_POST['formInit'] = '';
	if (!isset($_POST['formOption']))
		$_POST['formOption'] = '';
	if (!isset($_POST['formComment']))
		$_POST['formComment'] = '';

	// Fetch all FTS dictionaries from the database
	$ftstpls = $ftsActions->getFtsDictionaryTemplates();

	$misc->printTrail('schema');
	// TODO: create doc links
	$misc->printTitle($lang['strftscreatedict'], 'pg.ftsdict.create');
	$misc->printMsg($msg);

	echo "<form action=\"fulltext.php\" method=\"post\">\n";
	echo "<table>\n";
	echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
	echo "\t\t<td class=\"data1\"><input name=\"formName\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
		html_esc($_POST['formName']), "\" />&nbsp;",
		"<input type=\"checkbox\" name=\"formIsTemplate\" id=\"formIsTemplate\"", $_POST['formIsTemplate'] ? ' checked="checked" ' : '', " />\n",
		"<label for=\"formIsTemplate\">{$lang['strftscreatedicttemplate']}</label></td>\n\t</tr>\n";

	// Template
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strftstemplate']}</th>\n";
	echo "\t\t<td class=\"data1\">";
	$tpls = [];
	$tplsel = '';
	while (!$ftstpls->EOF) {
		$pg->fieldClean($ftstpls->fields['schema']);
		$pg->fieldClean($ftstpls->fields['name']);
		$tplname = $ftstpls->fields['schema'] . '.' . $ftstpls->fields['name'];
		$tpls[$tplname] = serialize([
			'name' => $ftstpls->fields['name'],
			'schema' => $ftstpls->fields['schema']
		]);
		if ($_POST['formTemplate'] == $tpls[$tplname]) {
			$tplsel = html_esc($tpls[$tplname]);
		}
		$ftstpls->moveNext();
	}
	$formRenderer = new FormRenderer();
	echo $formRenderer->printCombo(
		$tpls,
		'formTemplate',
		true,
		$tplsel,
		false
	);
	echo "\n\t\t</td>\n\t</tr>\n";

	// TODO: what about maxlengths?
	// Lexize
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strftslexize']}</th>\n";
	echo "\t\t<td class=\"data1\"><input name=\"formLexize\" size=\"32\" maxlength=\"1000\" value=\"",
		html_esc($_POST['formLexize']), "\" ", isset($_POST['formIsTemplate']) ? '' : ' disabled="disabled" ',
		"/></td>\n\t</tr>\n";

	// Init
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strftsinit']}</th>\n";
	echo "\t\t<td class=\"data1\"><input name=\"formInit\" size=\"32\" maxlength=\"1000\" value=\"",
		html_esc($_POST['formInit']), "\"", @$_POST['formIsTemplate'] ? '' : ' disabled="disabled" ',
		"/></td>\n\t</tr>\n";

	// Option
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strftsoptionsvalues']}</th>\n";
	echo "\t\t<td class=\"data1\"><input name=\"formOption\" size=\"32\" maxlength=\"1000\" value=\"",
		html_esc($_POST['formOption']), "\" /></td>\n\t</tr>\n";

	// Comment
	echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strcomment']}</th>\n";
	echo "\t\t<td class=\"data1\"><textarea name=\"formComment\" rows=\"3\" cols=\"32\">",
		html_esc($_POST['formComment']), "</textarea></td>\n\t</tr>\n";

	echo "</table>\n";
	echo "<p>\n";
	echo "<input type=\"hidden\" name=\"action\" value=\"createdict\" />\n";
	echo "<input type=\"hidden\" name=\"database\" value=\"", html_esc($_REQUEST['database']), "\" />\n";
	echo $misc->form;
	echo "<input type=\"submit\" name=\"create\" value=\"{$lang['strcreate']}\" />\n";
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
	echo "</p>\n";
	echo "</form>\n",
		"<script type=\"text/javascript\">				
				function templateOpts() {
					isTpl = document.getElementsByName('formIsTemplate')[0].checked;
					document.getElementsByName('formTemplate')[0].disabled = isTpl;
					document.getElementsByName('formOption')[0].disabled = isTpl;
					document.getElementsByName('formLexize')[0].disabled = !isTpl;
					document.getElementsByName('formInit')[0].disabled = !isTpl;
				}
				
				document.getElementsByName('formIsTemplate')[0].onchange = templateOpts;

				templateOpts();
			</script>\n";
}

/**
 * Actually creates the new FTS dictionary in the database
 */
function doSaveCreateDict()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	// Check that they've given a name
	if ($_POST['formName'] == '')
		doCreateDict($lang['strftsdictneedsname']);
	else {

		if (!isset($_POST['formIsTemplate']))
			$_POST['formIsTemplate'] = false;
		if (isset($_POST['formTemplate']))
			$formTemplate = unserialize($_POST['formTemplate']);
		else
			$formTemplate = '';
		if (!isset($_POST['formLexize']))
			$_POST['formLexize'] = '';
		if (!isset($_POST['formInit']))
			$_POST['formInit'] = '';
		if (!isset($_POST['formOption']))
			$_POST['formOption'] = '';

		$status = $ftsActions->createFtsDictionary(
			$_POST['formName'],
			$_POST['formIsTemplate'],
			$formTemplate,
			$_POST['formLexize'],
			$_POST['formInit'],
			$_POST['formOption'],
			$_POST['formComment']
		);

		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doViewDicts($lang['strftsdictcreated']);
		} else
			doCreateDict($lang['strftsdictcreatedbad']);
	}
}

/**
 * Display a form to permit editing FTS dictionary properties.
 */
function doAlterDict($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$misc->printTrail('ftscfg'); // TODO: change to smth related to dictionary
	$misc->printTitle($lang['stralter'], 'pg.ftsdict.alter');
	$misc->printMsg($msg);

	$ftsdict = $ftsActions->getFtsDictionaryByName($_REQUEST['ftsdict']);
	if ($ftsdict->recordCount() > 0) {
		if (!isset($_POST['formComment']))
			$_POST['formComment'] = $ftsdict->fields['comment'];
		if (!isset($_POST['ftsdict']))
			$_POST['ftsdict'] = $_REQUEST['ftsdict'];
		if (!isset($_POST['formName']))
			$_POST['formName'] = $_REQUEST['ftsdict'];

		echo "<form action=\"fulltext.php\" method=\"post\">\n";
		echo "<table>\n";

		echo "\t<tr>\n";
		echo "\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
		echo "\t\t<td class=\"data1\">";
		echo "\t\t\t<input name=\"formName\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
			html_esc($_POST['formName']), "\" />\n";
		echo "\t\t</td>\n";
		echo "\t</tr>\n";

		// Comment
		echo "\t<tr>\n";
		echo "\t\t<th class=\"data\">{$lang['strcomment']}</th>\n";
		echo "\t\t<td class=\"data1\"><textarea cols=\"32\" rows=\"3\"name=\"formComment\">", html_esc($_POST['formComment']), "</textarea></td>\n";
		echo "\t</tr>\n";
		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"alterdict\" />\n";
		echo "<input type=\"hidden\" name=\"ftsdict\" value=\"", html_esc($_POST['ftsdict']), "\" />\n";
		echo "<input type=\"hidden\" name=\"prev_action\" value=\"viewdicts\" /></p>\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"alter\" value=\"{$lang['stralter']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		echo "<p>{$lang['strnodata']}</p>\n";
	}
}

/**
 * Save the form submission containing changes to a FTS dictionary
 */
function doSaveAlterDict()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$status = $ftsActions->updateFtsDictionary(
		$_POST['ftsdict'],
		$_POST['formComment'],
		$_POST['formName']
	);
	if ($status == 0)
		doViewDicts($lang['strftsdictaltered']);
	else
		doAlterDict($lang['strftsdictalteredbad']);
}

/**
 * Show confirmation of drop and perform actual drop of FTS mapping
 */
function doDropMapping($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	if (empty($_REQUEST['mapping']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strftsspecifymappingtodrop']);
		return;
	}

	if (empty($_REQUEST['ftscfg'])) {
		doDefault($lang['strftsspecifyconfigtoalter']);
		return;
	}

	if ($confirm) {
		$misc->printTrail('ftscfg'); // TODO: proper breadcrumbs
		$misc->printTitle($lang['strdrop'], 'pg.ftscfg.alter');

		echo "<form action=\"fulltext.php\" method=\"post\">\n";

		// Case of multiaction drop
		if (isset($_REQUEST['ma'])) {

			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				echo "<p>", sprintf($lang['strconfdropftsmapping'], $misc->formatVal($a['mapping']), $misc->formatVal($_REQUEST['ftscfg'])), "</p>\n";
				printf('<input type="hidden" name="mapping[]" value="%s" />', html_esc($a['mapping']));
			}
		} else {
			echo "<p>", sprintf($lang['strconfdropftsmapping'], $misc->formatVal($_REQUEST['mapping']), $misc->formatVal($_REQUEST['ftscfg'])), "</p>\n";
			echo "<input type=\"hidden\" name=\"mapping\" value=\"", html_esc($_REQUEST['mapping']), "\" />\n";
		}

		echo "<input type=\"hidden\" name=\"ftscfg\" value=\"{$_REQUEST['ftscfg']}\" />\n";
		echo "<input type=\"hidden\" name=\"action\" value=\"dropmapping\" />\n";
		echo "<input type=\"hidden\" name=\"prev_action\" value=\"viewconfig\" /></p>\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} else {
		// Case of multiaction drop
		if (is_array($_REQUEST['mapping'])) {
			$status = $ftsActions->changeFtsMapping(
				$_REQUEST['ftscfg'],
				$_REQUEST['mapping'],
				'drop'
			);
			if ($status != 0) {
				doViewConfig($_REQUEST['ftscfg'], $lang['strftsmappingdroppedbad']);
				return;
			}
			doViewConfig($_REQUEST['ftscfg'], $lang['strftsmappingdropped']);
		} else {
			$status = $ftsActions->changeFtsMapping(
				$_REQUEST['ftscfg'],
				[$_REQUEST['mapping']],
				'drop'
			);
			if ($status == 0) {
				doViewConfig($_REQUEST['ftscfg'], $lang['strftsmappingdropped']);
			} else {
				doViewConfig($_REQUEST['ftscfg'], $lang['strftsmappingdroppedbad']);
			}
		}
	}
}

function doAlterMapping($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$pg = AppContainer::getPostgres();
	$ftsActions = new FtsActions($pg);

	$misc->printTrail('ftscfg');
	$misc->printTitle($lang['stralter'], 'pg.ftscfg.alter');
	$misc->printMsg($msg);

	$ftsdicts = $ftsActions->getFtsDictionaries();
	if ($ftsdicts->recordCount() > 0) {
		if (!isset($_POST['formMapping']))
			$_POST['formMapping'] = @$_REQUEST['mapping'];
		if (!isset($_POST['formDictionary']))
			$_POST['formDictionary'] = '';
		if (!isset($_POST['ftscfg']))
			$_POST['ftscfg'] = $_REQUEST['ftscfg'];

		echo "<form action=\"fulltext.php\" method=\"post\">\n";

		echo "<table>\n";
		echo "\t<tr>\n";
		echo "\t\t<th class=\"data left required\">{$lang['strftsmapping']}</th>\n";
		echo "\t\t<td class=\"data1\">";

		// Case of multiaction drop
		if (isset($_REQUEST['ma'])) {
			$ma_mappings = [];
			$ma_mappings_names = [];
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				printf('<input type="hidden" name="formMapping[]" value="%s" />', html_esc($a['mapping']));
				$ma_mappings[] = $ftsActions->getFtsMappingByName(
					$_POST['ftscfg'],
					$a['mapping']
				);
				$ma_mappings_names[] = $a['mapping'];
			}
			echo implode(", ", $ma_mappings_names);
		} else {
			$mapping = $ftsActions->getFtsMappingByName(
				$_POST['ftscfg'],
				$_POST['formMapping']
			);
			echo $mapping->fields['name'];
			echo "<input type=\"hidden\" name=\"formMapping\" value=\"", html_esc($_POST['formMapping']), "\" />\n";
		}

		echo "\t\t</td>\n";
		echo "\t</tr>\n";


		// Dictionary
		echo "\t<tr>\n";
		echo "\t\t<th class=\"data left required\">{$lang['strftsdict']}</th>\n";
		echo "\t\t<td class=\"data1\">";
		echo "\t\t\t<select name=\"formDictionary\">\n";
		while (!$ftsdicts->EOF) {
			$ftsdict = html_esc($ftsdicts->fields['name']);
			echo "\t\t\t\t<option value=\"{$ftsdict}\"", ($ftsdict == $_POST['formDictionary'] || $ftsdict == @$mapping->fields['dictionaries'] || $ftsdict == @$ma_mappings[0]->fields['dictionaries']) ? ' selected="selected"' : '', ">{$ftsdict}</option>\n";
			$ftsdicts->moveNext();
		}

		echo "\t\t</td>\n";
		echo "\t</tr>\n";

		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"altermapping\" />\n";
		echo "<input type=\"hidden\" name=\"ftscfg\" value=\"", html_esc($_POST['ftscfg']), "\" />\n";
		echo "<input type=\"hidden\" name=\"prev_action\" value=\"viewconfig\" /></p>\n";

		echo $misc->form;
		echo "<input type=\"submit\" name=\"alter\" value=\"{$lang['stralter']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		echo "<p>{$lang['strftsnodictionaries']}</p>\n";
	}
}

/**
 * Save the form submission containing changes to a FTS mapping
 */
function doSaveAlterMapping()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$mappingArray = $_POST['formMapping'];
	if (!is_array($mappingArray)) {
		$mappingArray = [$mappingArray];
	}
	$status = $ftsActions->changeFtsMapping(
		$_POST['ftscfg'],
		$mappingArray,
		'alter',
		$_POST['formDictionary']
	);
	if ($status == 0)
		doViewConfig($_POST['ftscfg'], $lang['strftsmappingaltered']);
	else
		doAlterMapping($lang['strftsmappingalteredbad']);
}

/**
 * Show the form to enter parameters of a new FTS mapping
 */
function doAddMapping($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$misc->printTrail('ftscfg');
	$misc->printTitle($lang['stralter'], 'pg.ftscfg.alter');
	$misc->printMsg($msg);

	$ftsdicts = $ftsActions->getFtsDictionaries();
	if ($ftsdicts->recordCount() > 0) {
		if (!isset($_POST['formMapping']))
			$_POST['formMapping'] = '';
		if (!isset($_POST['formDictionary']))
			$_POST['formDictionary'] = '';
		if (!isset($_POST['ftscfg']))
			$_POST['ftscfg'] = $_REQUEST['ftscfg'];
		$mappings = $ftsActions->getFtsMappings($_POST['ftscfg']);

		echo "<form action=\"fulltext.php\" method=\"post\">\n";
		echo "<table>\n";
		echo "\t<tr>\n";
		echo "\t\t<th class=\"data left required\">{$lang['strftsmapping']}</th>\n";
		echo "\t\t<td class=\"data1\">";
		echo "\t\t\t<select name=\"formMapping\">\n";
		while (!$mappings->EOF) {
			$mapping = html_esc($mappings->fields['name']);
			$mapping_desc = html_esc($mappings->fields['description']);
			echo "\t\t\t\t<option value=\"{$mapping}\"",
				$mapping == $_POST['formMapping'] ? ' selected="selected"' : '', ">{$mapping}", $mapping_desc ? " - {$mapping_desc}" : "", "</option>\n";
			$mappings->moveNext();
		}
		echo "\t\t</td>\n";
		echo "\t</tr>\n";


		// Dictionary
		echo "\t<tr>\n";
		echo "\t\t<th class=\"data left required\">{$lang['strftsdict']}</th>\n";
		echo "\t\t<td class=\"data1\">";
		echo "\t\t\t<select name=\"formDictionary\">\n";
		while (!$ftsdicts->EOF) {
			$ftsdict = html_esc($ftsdicts->fields['name']);
			echo "\t\t\t\t<option value=\"{$ftsdict}\"",
				$ftsdict == $_POST['formDictionary'] ? ' selected="selected"' : '', ">{$ftsdict}</option>\n";
			$ftsdicts->moveNext();
		}

		echo "\t\t</td>\n";
		echo "\t</tr>\n";

		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"addmapping\" />\n";
		echo "<input type=\"hidden\" name=\"ftscfg\" value=\"", html_esc($_POST['ftscfg']), "\" />\n";
		echo "<input type=\"hidden\" name=\"prev_action\" value=\"viewconfig\" /></p>\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"add\" value=\"{$lang['stradd']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		echo "<p>{$lang['strftsnodictionaries']}</p>\n";
	}
}

/**
 * Save the form submission containing parameters of a new FTS mapping
 */
function doSaveAddMapping()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$ftsActions = new FtsActions($pg);

	$mappingArray = (is_array($_POST['formMapping']) ? $_POST['formMapping'] : [$_POST['formMapping']]);
	$status = $ftsActions->changeFtsMapping(
		$_POST['ftscfg'],
		$mappingArray,
		'add',
		$_POST['formDictionary']
	);
	if ($status == 0)
		doViewConfig($_POST['ftscfg'], $lang['strftsmappingadded']);
	else
		doAddMapping($lang['strftsmappingaddedbad']);
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	//$pg = AppContainer::getPostgres();
	//$ftsActions = new FtsActions($pg);

	$tabs = $misc->getNavTabs('fulltext');
	$items = $misc->adjustTabsForTree($tabs);

	$reqvars = $misc->getRequestVars('ftscfg');

	$attrs = [
		'text' => field('title'),
		'icon' => field('icon'),
		'action' => url(
			'fulltext.php',
			$reqvars,
			field('urlvars')
		),
		'branch' => url(
			'fulltext.php',
			$reqvars,
			[
				'action' => 'subtree',
				'what' => field('icon') // IZ: yeah, it's ugly, but I do not want to change navigation tabs arrays
			]
		),
	];

	$misc->printTree($items, $attrs, 'fts');

	exit;
}

function doSubTree($what)
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$ftsActions = new FtsActions($pg);

	switch ($what) {
		case 'FtsCfg':
			$items = $ftsActions->getFtsConfigurations(false);
			$urlvars = ['action' => 'viewconfig', 'ftscfg' => field('name')];
			break;
		case 'FtsDict':
			$items = $ftsActions->getFtsDictionaries(false);
			$urlvars = ['action' => 'viewdicts'];
			break;
		case 'FtsParser':
			$items = $ftsActions->getFtsParsers(false);
			$urlvars = ['action' => 'viewparsers'];
			break;
		default:
			exit;
	}

	$reqvars = $misc->getRequestVars('ftscfg');

	$attrs = [
		'text' => field('name'),
		'icon' => $what,
		'toolTip' => field('comment'),
		'action' => url(
			'fulltext.php',
			$reqvars,
			$urlvars
		),
		'branch' => ifempty(
			field('branch'),
			'',
			url(
				'fulltext.php',
				$reqvars,
				[
					'action' => 'subtree',
					'ftscfg' => field('name')
				]
			)
		),
	];

	$misc->printTree($items, $attrs, strtolower($what));
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';


if ($action == 'tree')
	doTree();
if ($action == 'subtree')
	doSubTree($_REQUEST['what']);

$misc->printHeader($lang['strschemas']);
$misc->printBody();

if (isset($_POST['cancel'])) {
	if (isset($_POST['prev_action'])) {
		$action = $_POST['prev_action'];
	} else {
		$action = '';
	}
}

switch ($action) {
	case 'createconfig':
		if (isset($_POST['create']))
			doSaveCreateConfig();
		else
			doCreateConfig();
		break;
	case 'alterconfig':
		if (isset($_POST['alter']))
			doSaveAlterConfig();
		else
			doAlterConfig();
		break;
	case 'dropconfig':
		if (isset($_POST['drop']))
			doDropConfig(false);
		else
			doDropConfig(true);
		break;
	case 'viewconfig':
		doViewConfig($_REQUEST['ftscfg']);
		break;
	case 'viewparsers':
		doViewParsers();
		break;
	case 'viewdicts':
		doViewDicts();
		break;
	case 'createdict':
		if (isset($_POST['create']))
			doSaveCreateDict();
		else
			doCreateDict();
		break;
	case 'alterdict':
		if (isset($_POST['alter']))
			doSaveAlterDict();
		else
			doAlterDict();
		break;
	case 'dropdict':
		if (isset($_POST['drop']))
			doDropDict(false);
		else
			doDropDict(true);
		break;
	case 'dropmapping':
		if (isset($_POST['drop']))
			doDropMapping(false);
		else
			doDropMapping(true);
		break;
	case 'altermapping':
		if (isset($_POST['alter']))
			doSaveAlterMapping();
		else
			doAlterMapping();
		break;
	case 'addmapping':
		if (isset($_POST['add']))
			doSaveAddMapping();
		else
			doAddMapping();
		break;

	default:
		doDefault();
		break;
}

$misc->printFooter();
