<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\AggregateActions;

/**
 * Manage aggregates in a database
 *
 * $Id: aggregates.php,v 1.27 2008/01/19 13:46:15 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Actually creates the new aggregate in the database
 */
function doSaveCreate()
{

	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$aggregateActions = new AggregateActions($pg);

	// Check inputs
	if (trim($_REQUEST['name']) == '') {
		doCreate($lang['straggrneedsname']);
		return;
	} else if (trim($_REQUEST['basetype']) == '') {
		doCreate($lang['straggrneedsbasetype']);
		return;
	} else if (trim($_REQUEST['sfunc']) == '') {
		doCreate($lang['straggrneedssfunc']);
		return;
	} else if (trim($_REQUEST['stype']) == '') {
		doCreate($lang['straggrneedsstype']);
		return;
	}

	$status = $aggregateActions->createAggregate(
		$_REQUEST['name'],
		$_REQUEST['basetype'],
		$_REQUEST['sfunc'],
		$_REQUEST['stype'],
		$_REQUEST['ffunc'],
		$_REQUEST['initcond'],
		$_REQUEST['sortop'],
		$_REQUEST['aggrcomment']
	);

	if ($status == 0) {
		AppContainer::setShouldReloadTree(true);
		doDefault($lang['straggrcreated']);
	} else {
		doCreate($lang['straggrcreatedbad']);
	}
}

/**
 * Displays a screen for create a new aggregate function
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	if (!isset($_REQUEST['name']))
		$_REQUEST['name'] = '';
	if (!isset($_REQUEST['basetype']))
		$_REQUEST['basetype'] = '';
	if (!isset($_REQUEST['sfunc']))
		$_REQUEST['sfunc'] = '';
	if (!isset($_REQUEST['stype']))
		$_REQUEST['stype'] = '';
	if (!isset($_REQUEST['ffunc']))
		$_REQUEST['ffunc'] = '';
	if (!isset($_REQUEST['initcond']))
		$_REQUEST['initcond'] = '';
	if (!isset($_REQUEST['sortop']))
		$_REQUEST['sortop'] = '';
	if (!isset($_REQUEST['aggrcomment']))
		$_REQUEST['aggrcomment'] = '';

	$misc->printTrail('schema');
	$misc->printTitle($lang['strcreateaggregate'], 'pg.aggregate.create');
	$misc->printMsg($msg);
	?>
	<form action="aggregates.php" method="post">
		<table>
			<tr>
				<th class="data left required"><?= $lang['strname'] ?></th>
				<td class="data"><input name="name" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['name']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left required"><?= $lang['straggrbasetype'] ?></th>
				<td class="data"><input name="basetype" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['basetype']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left required"><?= $lang['straggrsfunc'] ?></th>
				<td class="data"><input name="sfunc" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['sfunc']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left required"><?= $lang['straggrstype'] ?></th>
				<td class="data"><input name="stype" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['stype']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrffunc'] ?></th>
				<td class="data"><input name="ffunc" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['ffunc']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrinitcond'] ?></th>
				<td class="data"><input name="initcond" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['initcond']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrsortop'] ?></th>
				<td class="data"><input name="sortop" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['sortop']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strcomment'] ?></th>
				<td><textarea name="aggrcomment" rows="3" cols="32"><?= html_esc($_REQUEST['aggrcomment']) ?></textarea>
				</td>
			</tr>
		</table>
		<p>
			<input type="hidden" name="action" value="save_create" />
			<?= $misc->form ?>
			<input type="submit" value="<?= $lang['strcreate'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

/** 
 * Function to save after altering an aggregate 
 */
function doSaveAlter()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$aggregateActions = new AggregateActions($pg);

	// Check inputs
	if (trim($_REQUEST['aggrname']) == '') {
		doAlter($lang['straggrneedsname']);
		return;
	}

	$status = $aggregateActions->alterAggregate(
		$_REQUEST['aggrname'],
		$_REQUEST['aggrtype'],
		$_REQUEST['aggrowner'],
		$_REQUEST['aggrschema'],
		$_REQUEST['aggrcomment'],
		$_REQUEST['newaggrname'],
		$_REQUEST['newaggrowner'],
		$_REQUEST['newaggrschema'],
		$_REQUEST['newaggrcomment']
	);
	if ($status == 0)
		doDefault($lang['straggraltered']);
	else {
		doAlter($lang['straggralteredbad']);
		return;
	}
}


/**
 * Function to allow editing an aggregate function
 */
function doAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$aggregateActions = new AggregateActions($pg);

	$misc->printTrail('aggregate');
	$misc->printTitle($lang['stralter'], 'pg.aggregate.alter');
	$misc->printMsg($msg);

	$aggrdata = $aggregateActions->getAggregate(
		$_REQUEST['aggrname'],
		$_REQUEST['aggrtype']
	);
	?>
	<form action="aggregates.php" method="post">
		<?php if ($aggrdata->recordCount() > 0) { ?>
			<table>
				<tr>
					<th class="data required"><?= $lang['strname'] ?></th>
					<th class="data required"><?= $lang['strowner'] ?></th>
					<th class="data required"><?= $lang['strschema'] ?></th>
				</tr>
				<tr>
					<td><input name="newaggrname" size="32" maxlength="32" value="<?= html_esc($_REQUEST['aggrname']) ?>" />
					</td>
					<td><input name="newaggrowner" size="32" maxlength="32"
							value="<?= html_esc($aggrdata->fields['usename']) ?>" /></td>
					<td><input name="newaggrschema" size="32" maxlength="32" value="<?= html_esc($_REQUEST['schema']) ?>" />
					</td>
				</tr>
				<tr>
					<th class="data left"><?= $lang['strcomment'] ?></th>
					<td><textarea name="newaggrcomment" rows="3"
							cols="32"><?= html_esc($aggrdata->fields['aggrcomment']) ?></textarea></td>
				</tr>
			</table>
			<p>
				<input type="hidden" name="action" value="save_alter" />
				<?= $misc->form ?>
				<input type="hidden" name="aggrname" value="<?= html_esc($_REQUEST['aggrname']) ?>" />
				<input type="hidden" name="aggrtype" value="<?= html_esc($_REQUEST['aggrtype']) ?>" />
				<input type="hidden" name="aggrowner" value="<?= html_esc($aggrdata->fields['usename']) ?>" />
				<input type="hidden" name="aggrschema" value="<?= html_esc($_REQUEST['schema']) ?>" />
				<input type="hidden" name="aggrcomment" value="<?= html_esc($aggrdata->fields['aggrcomment']) ?>" />
				<input type="submit" name="alter" value="<?= $lang['stralter'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</p>
		<?php } else { ?>
			<p class="nodata"><?= $lang['strnodata'] ?></p>
			<p><input type="submit" name="cancel" value="<?= $lang['strback'] ?>" /></p>
		<?php } ?>
	</form>
	<?php
}

/**
 * Show confirmation of drop and perform actual drop of the aggregate function selected
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$aggregateActions = new AggregateActions($pg);

	if ($confirm) {
		$misc->printTrail('aggregate');
		$misc->printTitle($lang['strdrop'], 'pg.aggregate.drop');
		?>
		<p><?= sprintf($lang['strconfdropaggregate'], html_esc($_REQUEST['aggrname'])) ?></p>

		<form action="aggregates.php" method="post">
			<p><input type="checkbox" id="cascade" name="cascade" /> <label for="cascade"><?= $lang['strcascade'] ?></label></p>
			<p>
				<input type="hidden" name="action" value="drop" />
				<input type="hidden" name="aggrname" value="<?= html_esc($_REQUEST['aggrname']) ?>" />
				<input type="hidden" name="aggrtype" value="<?= html_esc($_REQUEST['aggrtype']) ?>" />
				<?= $misc->form ?>
				<input type="submit" name="drop" value="<?= $lang['strdrop'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</p>
		</form>
		<?php
	} else {
		$status = $aggregateActions->dropAggregate(
			$_POST['aggrname'],
			$_POST['aggrtype'],
			isset($_POST['cascade'])
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['straggregatedropped']);
		} else
			doDefault($lang['straggregatedroppedbad']);
	}
}

/**
 * Show the properties of an aggregate
 */
function doProperties($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$aggregateActions = new AggregateActions($pg);

	$aggrdata = $aggregateActions->getAggregate(
		$_REQUEST['aggrname'],
		$_REQUEST['aggrtype']
	);

	$misc->printTrail('aggregate');
	$misc->printTitle($lang['strproperties'], 'pg.aggregate');
	$misc->printMsg($msg);

	if ($aggrdata->recordCount() > 0) {
		?>
		<table>
			<tr>
				<th class="data left"><?= $lang['strname'] ?></th>
				<td class="data1"><?= html_esc($_REQUEST['aggrname']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrbasetype'] ?></th>
				<td class="data1"><?= html_esc($_REQUEST['aggrtype']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrsfunc'] ?></th>
				<td class="data1"><?= html_esc($aggrdata->fields['aggtransfn']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrstype'] ?></th>
				<td class="data1"><?= html_esc($aggrdata->fields['aggstype']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrffunc'] ?></th>
				<td class="data1"><?= html_esc($aggrdata->fields['aggfinalfn']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrinitcond'] ?></th>
				<td class="data1"><?= html_esc($aggrdata->fields['agginitval']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['straggrsortop'] ?></th>
				<td class="data1"><?= html_esc($aggrdata->fields['aggsortop']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strowner'] ?></th>
				<td class="data1"><?= html_esc($aggrdata->fields['usename']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strcomment'] ?></th>
				<td class="data1"><?= $misc->formatVal($aggrdata->fields['aggrcomment']) ?></td>
			</tr>
		</table>
		<?php
	} else {
		?>
		<p class="nodata"><?= $lang['strnodata'] ?></p>
		<?php
	}


	$navlinks = [
		'showall' => [
			'attr' => [
				'href' => [
					'url' => 'aggregates.php',
					'urlvars' => [
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('Aggregates'),
			'content' => $lang['straggrshowall']
		],
		'alter' => [
			'attr' => [
				'href' => [
					'url' => 'aggregates.php',
					'urlvars' => [
						'action' => 'alter',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'aggrname' => $_REQUEST['aggrname'],
						'aggrtype' => $_REQUEST['aggrtype']
					]
				]
			],
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter']
		],
		'drop' => [
			'attr' => [
				'href' => [
					'url' => 'aggregates.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'aggrname' => $_REQUEST['aggrname'],
						'aggrtype' => $_REQUEST['aggrtype']
					]
				]
			],
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop']
		],
	];

	$misc->printNavLinks($navlinks, 'aggregates-properties', get_defined_vars());
}


/**
 * Show default list of aggregate functions in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$aggregateActions = new AggregateActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'aggregates');
	$misc->printMsg($msg);

	$aggregates = $aggregateActions->getAggregates();

	$columns = [
		'aggrname' => [
			'title' => $lang['strname'],
			'field' => field('proname'),
			'url' => "redirect.php?subject=aggregate&amp;action=properties&amp;{$misc->href}&amp;",
			'vars' => ['aggrname' => 'proname', 'aggrtype' => 'proargtypes'],
			'icon' => 'Aggregate',
			'class' => 'nowrap'
		],
		'aggrtype' => [
			'title' => $lang['strtype'],
			'field' => field('proargtypes'),
		],
		'aggrtransfn' => [
			'title' => $lang['straggrsfunc'],
			'field' => field('aggtransfn'),
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('usename'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('aggrcomment'),
		],
	];

	$footer = [
		'aggrname' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['straggregates']}",
			'colspan' => 3,
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 3,
		],
	];

	$actions = [
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'aggregates.php',
					'urlvars' => [
						'action' => 'alter',
						'aggrname' => field('proname'),
						'aggrtype' => field('proargtypes')
					]
				]
			]
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'aggregates.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'aggrname' => field('proname'),
						'aggrtype' => field('proargtypes')
					]
				]
			]
		]
	];

	$isCatalogSchema = $misc->isCatalogSchema();
	if ($isCatalogSchema) {
		// Disable actions on system aggregates
		unset($columns['actions']);
		$actions = [];
	}

	$misc->printTable(
		$aggregates,
		$columns,
		$actions,
		'aggregates-aggregates',
		$lang['strnoaggregates'],
		null,
		$footer
	);

	$navlinks = [
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'aggregates.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
					]
				]
			],
			'icon' => $misc->icon('CreateAggregate'),
			'content' => $lang['strcreateaggregate']
		]
	];

	if (!$isCatalogSchema) {
		$misc->printNavLinks($navlinks, 'aggregates-aggregates', get_defined_vars());
	}

}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$aggregateActions = new AggregateActions($pg);

	$aggregates = $aggregateActions->getAggregates();

	$proto = concat(field('proname'), ' (', field('proargtypes'), ')');
	$reqvars = $misc->getRequestVars('aggregate');

	$attrs = [
		'text' => $proto,
		'icon' => 'Aggregate',
		'toolTip' => field('aggcomment'),
		'action' => url(
			'redirect.php',
			$reqvars,
			[
				'action' => 'properties',
				'aggrname' => field('proname'),
				'aggrtype' => field('proargtypes')
			]
		)
	];

	$misc->printTree($aggregates, $attrs, 'aggregates');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';


if ($action == 'tree')
	doTree();

$misc->printHeader($lang['straggregates']);
$misc->printBody();

switch ($action) {
	case 'create':
		doCreate();
		break;
	case 'save_create':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doSaveCreate();
		break;
	case 'alter':
		doAlter();
		break;
	case 'save_alter':
		if (isset($_POST['alter']))
			doSaveAlter();
		else
			doProperties();
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
	case 'properties':
		doProperties();
		break;
}

$misc->printFooter();
