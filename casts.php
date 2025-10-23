<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\CastActions;
use PhpPgAdmin\Database\Actions\TypeActions;

/**
 * Manage casts in a database
 *
 * $Id: casts.php,v 1.16 2007/09/25 16:08:05 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Show default list of casts in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$castActions = new CastActions($pg);

	$renderCastContext = function ($val) use ($lang) {
		switch ($val) {
			case 'e':
				return $lang['strno'];
			case 'a':
				return $lang['strinassignment'];
			default:
				return $lang['stryes'];
		}
	};

	$misc->printTrail('database');
	$misc->printTabs('database', 'casts');
	$misc->printMsg($msg);

	$casts = $castActions->getCasts();

	$columns = [
		'source_type' => [
			'title' => $lang['strsourcetype'],
			'field' => field('castsource'),
			'icon' => $misc->icon('Cast'),
		],
		'target_type' => [
			'title' => $lang['strtargettype'],
			'field' => field('casttarget'),
		],
		'function' => [
			'title' => $lang['strfunction'],
			'field' => field('castfunc'),
			'params' => ['null' => $lang['strbinarycompat']],
		],
		'implicit' => [
			'title' => $lang['strimplicit'],
			'field' => field('castcontext'),
			'type' => 'callback',
			'params' => ['function' => $renderCastContext, 'align' => 'center'],
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('castcomment'),
		],
	];

	$footer = [
		'source_type' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strcasts']}",
			'colspan' => 2,
		],
		'function' => [
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
					'url' => 'casts.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'castsource' => field('castsource'),
						'casttarget' => field('casttarget'),
						'castsourceoid' => field('castsourceoid'),
						'casttargetoid' => field('casttargetoid'),
					],
				],
			],
		],
	];

	$pre_fn = function ($tabledata, $actions) {
		if (!($tabledata->fields['is_user_cast'] ?? false)) {
			$actions['drop']['disable'] = true;
		}
		return $actions;
	};

	$misc->printTable(
		$casts,
		$columns,
		$actions,
		'casts-casts',
		$lang['strnocasts'],
		$pre_fn,
		$footer
	);

	$misc->printNavLinks(
		[
			'create' => [
				'attr' => [
					'href' => [
						'url' => 'casts.php',
						'urlvars' => [
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'action' => 'create',
						],
					],
				],
				'icon' => $misc->icon('CreateCast'),
				'content' => $lang['strcreatecast'],
			],
		],
		'casts-default',
		get_defined_vars()
	);
}

function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);
	$castActions = new CastActions($pg);

	$_POST['castsourceoid'] = $_POST['castsourceoid'] ?? '';
	$_POST['casttargetoid'] = $_POST['casttargetoid'] ?? '';
	$_POST['method'] = $_POST['method'] ?? 'with_function';
	$_POST['function_oid'] = $_POST['function_oid'] ?? '';
	$_POST['castcontext'] = $_POST['castcontext'] ?? 'e';
	$_POST['comment'] = $_POST['comment'] ?? '';

	$misc->printTrail('database');
	$misc->printTabs('database', 'casts');
	$misc->printTitle($lang['strcreatecast']);
	$misc->printMsg($msg);

	$types = $typeActions->getTypes(true, false, true);
	$typesArr = $types ? $types->getArray() : [];
	$fnCandidates = $castActions->getCastFunctionCandidates();
	$fnRows = $fnCandidates ? $fnCandidates->getArray() : [];
	//var_dump($fnRows);

	// Provide candidates to JavaScript (dynamic filtering)
	?>
	<script type="text/javascript">
		window.phpPgAdminCastFunctions = <?php echo json_encode($fnRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	</script>
	<script type="text/javascript" src="js/casts.js"></script>
	<form action="casts.php" method="post">
		<table>
			<tr>
				<th class="data left required"><?php echo $lang['strsourcetype']; ?></th>
				<td class="data1">
					<select name="castsourceoid" id="castsourceoid">
						<option value="">(CHOOSE)</option>
						<?php foreach ($typesArr as $t) {
							$oid = (string) ($t['oid'] ?? '');
							$label = htmlspecialchars($t['typname'] ?? '');
							$sel = ($oid === (string) $_POST['castsourceoid']) ? ' selected' : '';
							?>
							<option value="<?php echo html_esc($oid); ?>" <?php echo $sel; ?>><?php echo $label; ?></option>
						<?php } ?>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left required"><?php echo $lang['strtargettype']; ?></th>
				<td class="data1">
					<select name="casttargetoid" id="casttargetoid">
						<option value="">(CHOOSE)</option>
						<?php foreach ($typesArr as $t) {
							$oid = (string) ($t['oid'] ?? '');
							$label = htmlspecialchars($t['typname'] ?? '');
							$sel = ($oid === (string) $_POST['casttargetoid']) ? ' selected' : '';
							?>
							<option value="<?php echo html_esc($oid); ?>" <?php echo $sel; ?>><?php echo $label; ?></option>
						<?php } ?>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left required"><?php echo $lang['strcastmethod']; ?></th>
				<td class="data1">
					<?php
					$method = $_POST['method'];
					$checked = function ($v) use ($method) {
						return ($method === $v) ? ' checked' : '';
					};
					?>
					<label><input type="radio" name="method" value="with_function" <?php echo $checked('with_function'); ?> /> <?php echo $lang['strwithfunction']; ?></label><br />
					<label><input type="radio" name="method" value="without_function" <?php echo $checked('without_function'); ?> /> <?php echo $lang['strwithoutfunction']; ?></label><br />
					<label><input type="radio" name="method" value="with_inout" <?php echo $checked('with_inout'); ?> />
						<?php echo $lang['strwithinout']; ?></label>
				</td>
			</tr>
			<tr id="cast_function_row">
				<th class="data left"><?php echo $lang['strfunction']; ?></th>
				<td class="data1">
					<select name="function_oid" id="function_oid"
						data-selected="<?php echo html_esc((string) $_POST['function_oid']); ?>">
						<option value="">(CHOOSE)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left"><?php echo $lang['strcastcontext']; ?></th>
				<td class="data1">
					<?php
					$ctx = $_POST['castcontext'];
					$ctxChecked = function ($v) use ($ctx) {
						return ($ctx === $v) ? ' checked' : '';
					};
					?>
					<label><input type="radio" name="castcontext" value="e" <?php echo $ctxChecked('e'); ?> />
						<?php echo $lang['strexplicit']; ?></label><br />
					<label><input type="radio" name="castcontext" value="a" <?php echo $ctxChecked('a'); ?> />
						<?php echo $lang['strassignment']; ?></label><br />
					<label><input type="radio" name="castcontext" value="i" <?php echo $ctxChecked('i'); ?> />
						<?php echo $lang['strimplicit']; ?></label>
				</td>
			</tr>
			<tr>
				<th class="data left"><?php echo $lang['strcomment']; ?></th>
				<td class="data1"><textarea name="comment" rows="3"
						cols="40"><?php echo html_esc($_POST['comment']); ?></textarea></td>
			</tr>
		</table>
		<p>
			<input type="hidden" name="action" value="save_create" />
			<?php echo $misc->form; ?>
			<input type="submit" name="create" value="<?php echo $lang['strcreate']; ?>" />
			<input type="submit" name="cancel" value="<?php echo $lang['strcancel']; ?>" />
		</p>
	</form>
	<?php
}

function doSaveCreate()
{
	//var_dump($_POST);
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$castActions = new CastActions($pg);

	$sourceOid = $_POST['castsourceoid'] ?? '';
	$targetOid = $_POST['casttargetoid'] ?? '';
	$method = $_POST['method'] ?? 'with_function';
	$functionOid = $_POST['function_oid'] ?? '';
	$context = $_POST['castcontext'] ?? 'e';
	$comment = $_POST['comment'] ?? '';

	if ($sourceOid === '' || $targetOid === '') {
		doCreate($lang['strcastcreatedbad']);
		return;
	}

	if ($method === 'with_function' && $functionOid === '') {
		doCreate($lang['strcastcreatedbad']);
		return;
	}

	$status = $castActions->createCast(
		$sourceOid,
		$targetOid,
		$method,
		($functionOid === '') ? null : $functionOid,
		$context,
		$comment
	);

	if ($status == 0) {
		AppContainer::setShouldReloadTree(true);
		doDefault($lang['strcastcreated']);
	} else {
		doCreate($lang['strcastcreatedbad']);
	}
}

function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$castActions = new CastActions($pg);

	if ($confirm) {
		$misc->printTrail('database');
		$misc->printTabs('database', 'casts');
		$misc->printTitle($lang['strdropcast']);

		$castLabel = html_esc(($_REQUEST['castsource'] ?? '') . ' AS ' . ($_REQUEST['casttarget'] ?? ''));
		?>
		<p><?php echo sprintf($lang['strconfdropcast'], $castLabel); ?></p>
		<form action="casts.php" method="post">
			<p><input type="checkbox" id="cascade" name="cascade" /> <label
					for="cascade"><?php echo $lang['strcascade']; ?></label></p>
			<p><input type="hidden" name="action" value="drop" />
				<input type="hidden" name="castsourceoid" value="<?php echo html_esc($_REQUEST['castsourceoid'] ?? ''); ?>" />
				<input type="hidden" name="casttargetoid" value="<?php echo html_esc($_REQUEST['casttargetoid'] ?? ''); ?>" />
				<?php echo $misc->form; ?>
				<input type="submit" name="drop" value="<?php echo $lang['strdrop']; ?>" />
				<input type="submit" name="cancel" value="<?php echo $lang['strcancel']; ?>" />
			</p>
		</form>
		<?php
	} else {
		$status = $castActions->dropCast(
			$_POST['castsourceoid'] ?? 0,
			$_POST['casttargetoid'] ?? 0,
			isset($_POST['cascade'])
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strcastdropped']);
		} else {
			doDefault($lang['strcastdroppedbad']);
		}
	}
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$castActions = new CastActions($pg);

	$casts = $castActions->getCasts();

	$proto = concat(field('castsource'), ' AS ', field('casttarget'));

	$attrs = [
		'text' => $proto,
		'icon' => 'Cast'
	];

	$misc->printTree($casts, $attrs, 'casts');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';


if ($action == 'tree')
	doTree();

$misc->printHeader($lang['strcasts']);
$misc->printBody();

switch ($action) {
	case 'save_create':
		if (isset($_POST['cancel'])) {
			doDefault();
		} else {
			doSaveCreate();
		}
		break;
	case 'create':
		doCreate();
		break;
	case 'drop':
		if (isset($_POST['cancel'])) {
			doDefault();
		} else {
			doDrop(false);
		}
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	case 'tree':
		doTree();
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
