<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\OperatorActions;
use PhpPgAdmin\Database\Actions\FunctionActions;
use PhpPgAdmin\Database\Actions\TypeActions;

/**
 * Manage operators in a database
 *
 * $Id: operators.php,v 1.29 2007/08/31 18:30:11 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Show read only properties for an operator
 */
function doProperties($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$operatorActions = new OperatorActions($pg);

	$misc->printTrail('operator');
	$misc->printTitle($lang['strproperties'], 'pg.operator');
	$misc->printMsg($msg);

	$oprdata = $operatorActions->getOperator($_REQUEST['operator_oid']);
	$oprdata->fields['oprcanhash'] = $pg->phpBool($oprdata->fields['oprcanhash']);

	if ($oprdata->recordCount() == 0) {
		doDefault($lang['strinvalidparam']);
		return;
	}

	?>
	<table>
		<tr>
			<th class="data left"><?= $lang['strname'] ?></th>
			<td class="data1"><?= $misc->formatVal($oprdata->fields['oprname']) ?></td>
		</tr>
		<tr>
			<th class="data left"><?= $lang['strleftarg'] ?></th>
			<td class="data1"><?= $misc->formatVal($oprdata->fields['oprleftname']) ?></td>
		</tr>
		<tr>
			<th class="data left"><?= $lang['strrightarg'] ?></th>
			<td class="data1"><?= $misc->formatVal($oprdata->fields['oprrightname']) ?></td>
		</tr>
		<tr>
			<th class="data left"><?= $lang['strcommutator'] ?></th>
			<td class="data1"><?= $misc->formatVal($oprdata->fields['oprcom']) ?></td>
		</tr>
		<tr>
			<th class="data left"><?= $lang['strnegator'] ?></th>
			<td class="data1"><?= $misc->formatVal($oprdata->fields['oprnegate']) ?></td>
		</tr>
		<tr>
			<th class="data left"><?= $lang['strjoin'] ?></th>
			<td class="data1"><?= $misc->formatVal($oprdata->fields['oprjoin']) ?></td>
		</tr>
		<tr>
			<th class="data left"><?= $lang['strhashes'] ?></th>
			<td class="data1"><?php echo ($oprdata->fields['oprcanhash']) ? $lang['stryes'] : $lang['strno']; ?></td>
		</tr>

		<?php if (isset($oprdata->fields['oprlsortop'])): ?>
			<tr>
				<th class="data left"><?= $lang['strmerges'] ?></th>
				<td class="data1">
					<?php echo ($oprdata->fields['oprlsortop'] !== '0' && $oprdata->fields['oprrsortop'] !== '0') ? $lang['stryes'] : $lang['strno']; ?>
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strrestrict'] ?></th>
				<td class="data1"><?= $misc->formatVal($oprdata->fields['oprrest']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strleftsort'] ?></th>
				<td class="data1"><?= $misc->formatVal($oprdata->fields['oprlsortop']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strrightsort'] ?></th>
				<td class="data1"><?= $misc->formatVal($oprdata->fields['oprrsortop']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strlessthan'] ?></th>
				<td class="data1"><?= $misc->formatVal($oprdata->fields['oprltcmpop']) ?></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strgreaterthan'] ?></th>
				<td class="data1"><?= $misc->formatVal($oprdata->fields['oprgtcmpop']) ?></td>
			</tr>
		<?php else: ?>
			<tr>
				<th class="data left"><?= $lang['strmerges'] ?></th>
				<td class="data1">
					<?php echo $pg->phpBool($oprdata->fields['oprcanmerge']) ? $lang['stryes'] : $lang['strno']; ?>
				</td>
			</tr>
		<?php endif; ?>
	</table>
	<?php

	$misc->printNavLinks(
		[
			'showall' => [
				'attr' => [
					'href' => [
						'url' => 'operators.php',
						'urlvars' => [
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema']
						]
					]
				],
				'icon' => $misc->icon('Operators'),
				'content' => $lang['strshowalloperators']
			]
		],
		'operators-properties',
		get_defined_vars()
	);
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$operatorActions = new OperatorActions($pg);

	if ($confirm) {
		$misc->printTrail('operator');
		$misc->printTitle($lang['strdrop'], 'pg.operator.drop');

		echo "<p>", sprintf($lang['strconfdropoperator'], $misc->formatVal($_REQUEST['operator'])), "</p>\n";

		echo "<form action=\"operators.php\" method=\"post\">\n";
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo "<input type=\"hidden\" name=\"operator\" value=\"", html_esc($_REQUEST['operator']), "\" />\n";
		echo "<input type=\"hidden\" name=\"operator_oid\" value=\"", html_esc($_REQUEST['operator_oid']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		$status = $operatorActions->dropOperator(
			$_POST['operator_oid'],
			isset($_POST['cascade'])
		);
		if ($status == 0)
			doDefault($lang['stroperatordropped']);
		else
			doDefault($lang['stroperatordroppedbad']);
	}

}

// Create operator form and handlers
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$funcActions = new FunctionActions($pg);
	$typeActions = new TypeActions($pg);

	if (!isset($_POST['name']))
		$_POST['name'] = '';
	if (!isset($_POST['procedure']))
		$_POST['procedure'] = '';
	if (!isset($_POST['leftarg']))
		$_POST['leftarg'] = '';
	if (!isset($_POST['rightarg']))
		$_POST['rightarg'] = '';
	if (!isset($_POST['commutator']))
		$_POST['commutator'] = '';
	if (!isset($_POST['negator']))
		$_POST['negator'] = '';
	if (!isset($_POST['restrict']))
		$_POST['restrict'] = '';
	if (!isset($_POST['join']))
		$_POST['join'] = '';
	if (!isset($_POST['hashes']))
		$_POST['hashes'] = false;
	if (!isset($_POST['merges']))
		$_POST['merges'] = false;
	if (!isset($_POST['comment']))
		$_POST['comment'] = '';

	$functions = $funcActions->getFunctions(true)->getArray();
	$types = $typeActions->getTypes(true)->getArray();

	$misc->printTrail('schema');
	$misc->printTitle($lang['strcreateoperator'], 'pg.operator.create');
	$misc->printMsg($msg);
	?>
	<form action="operators.php" method="post">
		<table>
			<tr>
				<th class="data left required"><?= $lang['strname'] ?></th>
				<td class="data1">
					<input name="name" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_POST['name']) ?>" />
				</td>
			</tr>
			<tr>
				<th class="data left required"><?= $lang['strfunction'] ?></th>
				<td class="data1">
					<select name="procedure">
						<option value="">(CHOOSE)</option>
						<?php foreach ($functions as $f):
							$val = htmlspecialchars($f['proproto']);
							$sel = ($val == $_POST['procedure']) ? ' selected' : '';
							?>
							<option value="<?= $val ?>" <?= $sel ?>><?= $val ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strleftarg'] ?></th>
				<td class="data1">
					<select name="leftarg">
						<option value="">(NONE)</option>
						<?php foreach ($types as $t):
							$val = htmlspecialchars($t['typname']);
							$sel = ($val == $_POST['leftarg']) ? ' selected' : '';
							?>
							<option value="<?= $val ?>" <?= $sel ?>><?= $val ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strrightarg'] ?></th>
				<td class="data1">
					<select name="rightarg">
						<option value="">(NONE)</option>
						<?php foreach ($types as $t):
							$val = htmlspecialchars($t['typname']);
							$sel = ($val == $_POST['rightarg']) ? ' selected' : '';
							?>
							<option value="<?= $val ?>" <?= $sel ?>><?= $val ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strcommutator'] ?></th>
				<td class="data1"><input name="commutator" size="32" value="<?= html_esc($_POST['commutator']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strnegator'] ?></th>
				<td class="data1"><input name="negator" size="32" value="<?= html_esc($_POST['negator']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strrestrict'] ?></th>
				<td class="data1">
					<select name="restrict">
						<option value="">(NONE)</option>
						<?php foreach ($functions as $f):
							$val = htmlspecialchars($f['proproto']);
							$sel = ($val == $_POST['restrict']) ? ' selected' : '';
							?>
							<option value="<?= $val ?>" <?= $sel ?>><?= $val ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strjoin'] ?></th>
				<td class="data1">
					<select name="join">
						<option value="">(NONE)</option>
						<?php foreach ($functions as $f):
							$val = htmlspecialchars($f['proproto']);
							$sel = ($val == $_POST['join']) ? ' selected' : '';
							?>
							<option value="<?= $val ?>" <?= $sel ?>><?= $val ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strhashes'] ?></th>
				<td class="data1"><input type="checkbox" name="hashes" value="1" <?= isset($_POST['hashes']) && $_POST['hashes'] ? ' checked' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strmerges'] ?></th>
				<td class="data1"><input type="checkbox" name="merges" value="1" <?= isset($_POST['merges']) && $_POST['merges'] ? ' checked' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left"><?= $lang['strcomment'] ?></th>
				<td class="data1"><textarea name="comment" rows="3" cols="40"><?= html_esc($_POST['comment']) ?></textarea>
				</td>
			</tr>
		</table>
		<p>
			<input type="hidden" name="action" value="save_create" />
			<?= $misc->form ?>
			<input type="submit" name="create" value="<?= $lang['strcreate'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

function doSaveCreate()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$operatorActions = new OperatorActions($pg);

	$name = trim($_POST['name'] ?? '');
	if ($name === '') {
		doCreate($lang['stroperatorneedsname']);
		return;
	}
	// Strip off any argument list that may have been included
	$procedure = trim($_POST['procedure'] ?? '');
	if ($procedure == '') {
		doCreate($lang['stroperatorcreatedbad']);
		return;
	}

	$hashes = isset($_POST['hashes']) && $_POST['hashes'] ? true : false;
	$merges = isset($_POST['merges']) && $_POST['merges'] ? true : false;

	$status = $operatorActions->createOperator(
		$name,
		$procedure,
		$_POST['leftarg'] ?? '',
		$_POST['rightarg'] ?? '',
		$_POST['commutator'] ?? '',
		$_POST['negator'] ?? '',
		$_POST['restrict'] ?? '',
		$_POST['join'] ?? '',
		$hashes,
		$merges,
		$_POST['comment'] ?? ''
	);

	if ($status == 0) {
		AppContainer::setShouldReloadTree(true);
		doDefault($lang['stroperatorcreated']);
	} else {
		doCreate($lang['stroperatorcreatedbad']);
	}
}

/**
 * Show default list of operators in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$operatorActions = new OperatorActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'operators');
	$misc->printMsg($msg);

	$operators = $operatorActions->getOperators();

	$columns = [
		'operator' => [
			'title' => $lang['stroperator'],
			'field' => field('oprname'),
			'url' => "operators.php?action=properties&amp;{$misc->href}&amp;",
			'vars' => ['operator' => 'oprname', 'operator_oid' => 'oid'],
			'icon' => $misc->icon('Operator'),
		],
		'leftarg' => [
			'title' => $lang['strleftarg'],
			'field' => field('oprleftname'),
		],
		'rightarg' => [
			'title' => $lang['strrightarg'],
			'field' => field('oprrightname'),
		],
		'returns' => [
			'title' => $lang['strreturns'],
			'field' => field('resultname'),
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('oprowner'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('oprcomment'),
		],
	];

	$footer = [
		'operator' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['stroperators']}",
			'colspan' => 4,
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 3,
		],
	];

	$actions = [
		'drop' => [
			// 'title' => $lang['strdrop'],
			// 'url'   => "operators.php?action=confirm_drop&amp;{$misc->href}&amp;",
			// 'vars'  => array('operator' => 'oprname', 'operator_oid' => 'oid'),
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'operators.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'operator' => field('oprname'),
						'operator_oid' => field('oid')
					]
				]
			]
		]
	];

	$isCatalogSchema = $misc->isCatalogSchema();
	if ($isCatalogSchema) {
		$actions = [];
		unset($columns['actions']);
	}

	$misc->printTable(
		$operators,
		$columns,
		$actions,
		'operators-operators',
		$lang['strnooperators'],
		null,
		$footer
	);

	if ($isCatalogSchema) {
		return;
	}

	$misc->printNavLinks(
		[
			'create' => [
				'attr' => [
					'href' => [
						'url' => 'operators.php',
						'urlvars' => [
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
							'action' => 'create',
						]
					]
				],
				'icon' => $misc->icon('CreateOperator'),
				'content' => $lang['strcreateoperator']
			]
		],
		'operators-default',
		get_defined_vars()
	);

}


/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$operatorActions = new OperatorActions($pg);

	$operators = $operatorActions->getOperators();

	// Operator prototype: "type operator type"
	$proto = concat(field('oprleftname'), ' ', field('oprname'), ' ', field('oprrightname'));

	$reqvars = $misc->getRequestVars('operator');

	$attrs = [
		'text' => $proto,
		'icon' => 'Operator',
		'toolTip' => field('oprcomment'),
		'action' => url(
			'operators.php',
			$reqvars,
			[
				'action' => 'properties',
				'operator' => $proto,
				'operator_oid' => field('oid')
			]
		)
	];

	$misc->printTree($operators, $attrs, 'operators');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();

$misc->printHeader($lang['stroperators']);
$misc->printBody();

switch ($action) {
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
		if (isset($_POST['cancel']))
			doDefault();
		else
			doDrop(false);
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	case 'properties':
		doProperties();
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();


