<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RuleActions;

/**
 * List rules on a table OR view
 *
 * $Id: rules.php,v 1.33 2007/08/31 18:30:11 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Confirm and then actually create a rule
 */
function createRule($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ruleActions = new RuleActions($pg);

	if (!isset($_POST['name']))
		$_POST['name'] = '';
	if (!isset($_POST['event']))
		$_POST['event'] = '';
	if (!isset($_POST['where']))
		$_POST['where'] = '';
	if (!isset($_POST['type']))
		$_POST['type'] = 'SOMETHING';
	if (!isset($_POST['raction']))
		$_POST['raction'] = '';

	if ($confirm) {
		$misc->printTrail($_REQUEST['subject']);
		$misc->printTitle($lang['strcreaterule'], 'pg.rule.create');
		$misc->printMsg($msg);

		?>
		<form action="rules.php" method="post">
			<table>
				<tr>
					<th class="data left required">
						<?= $lang['strname'] ?>
					</th>
					<td class="data1">
						<input name="name" size="16" maxlength="<?= $pg->_maxNameLen ?>"
							value="<?= html_esc($_POST['name']) ?>">
					</td>
				</tr>
				<tr>
					<th class="data left required">
						<?= $lang['strevent'] ?>
					</th>
					<td class="data1"><select name="event">
							<?php foreach (RuleActions::RULE_EVENTS as $v): ?>
								<option value="<?= $v ?>" <?= ($v == $_POST['event']) ? ' selected="selected"' : '' ?>>
									<?= $v ?>
								</option>
							<?php endforeach ?>
						</select></td>
				</tr>
				<tr>
					<th class="data left">
						<?= $lang['strwhere'] ?>
					</th>
					<td class="data1">
						<input name="where" size="32" value="<?= html_esc($_POST['where']) ?>" />
					</td>
				</tr>
				<tr>
					<th class="data left"><label for="instead">
							<?= $lang['strinstead'] ?>
						</label></th>
					<td class="data1">
						<input type="checkbox" id="instead" name="instead" <?= isset($_POST['instead']) ? ' checked="checked"' : '' ?> />
					</td>
				</tr>
				<tr>
					<th class="data left required">
						<?= $lang['straction'] ?>
					</th>
					<td class="data1">
						<input type="radio" id="type1" name="type" value="NOTHING" <?= ($_POST['type'] == 'NOTHING') ? ' checked="checked"' : '' ?> /> <label for="type1">NOTHING</label><br />
						<input type="radio" name="type" value="SOMETHING" <?= ($_POST['type'] == 'SOMETHING') ? ' checked="checked"' : '' ?> />
						(<input name="raction" size="32" value="<?= html_esc($_POST['raction']) ?>" />)
					</td>
				</tr>
			</table>

			<input type="hidden" name="action" value="save_create_rule" />
			<input type="hidden" name="subject" value="<?= html_esc($_REQUEST['subject']) ?>" />
			<input type="hidden" name="<?= html_esc($_REQUEST['subject']) ?>"
				value="<?= html_esc($_REQUEST[$_REQUEST['subject']]) ?>" />
			<?= $misc->form ?>
			<p><input type="submit" name="ok" value="<?= $lang['strcreate'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</p>
		</form>
		<?php

	} else {
		if (trim($_POST['name']) == '')
			createRule(true, $lang['strruleneedsname']);
		else {
			$status = $ruleActions->createRule(
				$_POST['name'],
				$_POST['event'],
				$_POST[$_POST['subject']],
				$_POST['where'],
				isset($_POST['instead']),
				$_POST['type'],
				$_POST['raction']
			);
			if ($status == 0)
				doDefault($lang['strrulecreated']);
			else
				createRule(true, $lang['strrulecreatedbad']);
		}
	}
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ruleActions = new RuleActions($pg);

	if ($confirm) {
		$misc->printTrail($_REQUEST['subject']);
		$misc->printTitle($lang['strdrop'], 'pg.rule.drop');

		?>
		<p>
			<?php printf(
				$lang['strconfdroprule'],
				$misc->formatVal($_REQUEST['rule']),
				$misc->formatVal($_REQUEST[$_REQUEST['reltype']])
			) ?>
		</p>
		<form action="rules.php" method="post">
			<input type="hidden" name="action" value="drop" />
			<input type="hidden" name="subject" value="<?= html_esc($_REQUEST['reltype']) ?>" />
			<input type="hidden" name="<?= html_esc($_REQUEST['reltype']) ?>"
				value="<?= html_esc($_REQUEST[$_REQUEST['reltype']]) ?>" />
			<input type="hidden" name="rule" value="<?= html_esc($_REQUEST['rule']) ?>" />
			<?= $misc->form ?>
			<p>
				<input type="checkbox" id="cascade" name="cascade" /> <label for="cascade">
					<?= $lang['strcascade'] ?>
				</label>
			</p>
			<input type="submit" name="yes" value="<?= $lang['stryes'] ?>" />
			<input type="submit" name="no" value="<?= $lang['strno'] ?>" />
		</form>
		<?php
	} else {
		$status = $ruleActions->dropRule(
			$_POST['rule'],
			$_POST[$_POST['subject']],
			isset($_POST['cascade'])
		);
		if ($status == 0)
			doDefault($lang['strruledropped']);
		else
			doDefault($lang['strruledroppedbad']);
	}

}

/**
 * List all the rules on the table
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$ruleActions = new RuleActions($pg);

	$misc->printTrail($_REQUEST['subject']);
	$misc->printTabs($_REQUEST['subject'], 'rules');
	$misc->printMsg($msg);

	$rules = $ruleActions->getRules($_REQUEST[$_REQUEST['subject']]);

	$columns = [
		'rule' => [
			'icon' => 'Rule',
			'title' => $lang['strname'],
			'field' => field('rulename'),
		],
		'definition' => [
			'title' => $lang['strdefinition'],
			'field' => field('definition'),
			'type' => 'sql',
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
	];

	$subject = urlencode($_REQUEST['subject']);
	$object = urlencode($_REQUEST[$_REQUEST['subject']]);

	$actions = [
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'rules.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'reltype' => $subject,
						$subject => $object,
						'subject' => 'rule',
						'rule' => field('rulename')
					]
				]
			]
		],
	];

	$misc->printTable($rules, $columns, $actions, 'rules-rules', $lang['strnorules']);

	$misc->printNavLinks([
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'rules.php',
					'urlvars' => [
						'action' => 'create_rule',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						$subject => $object,
						'subject' => $subject
					]
				]
			],
			'icon' => $misc->icon('CreateRule'),
			'content' => $lang['strcreaterule']
		]
	], 'rules-rules', get_defined_vars());
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$ruleActions = new RuleActions($pg);

	$rules = $ruleActions->getRules($_REQUEST[$_REQUEST['subject']]);

	$reqvars = $misc->getRequestVars($_REQUEST['subject']);

	$attrs = [
		'text' => field('rulename'),
		'icon' => 'Rule',
	];

	$misc->printTree($rules, $attrs, 'rules');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();

// Different header if we're view rules or table rules
$misc->printHeader($_REQUEST[$_REQUEST['subject']] . ' - ' . $lang['strrules']);
$misc->printBody();

switch ($action) {
	case 'create_rule':
		createRule(true);
		break;
	case 'save_create_rule':
		if (isset($_POST['cancel']))
			doDefault();
		else
			createRule(false);
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
	default:
		doDefault();
		break;
}

$misc->printFooter();


