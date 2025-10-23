<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ColumnActions;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Gui\ColumnFormRenderer;

/**
 * List Columns properties in tables
 *
 * $Id: colproperties.php
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Displays a screen where they can alter a column
 */
function doAlter($msg = '')
{

	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$columnActions = new ColumnActions($pg);


	$misc->printTrail('column');
	$misc->printTitle($lang['stralter'], 'pg.column.alter');
	$misc->printMsg($msg);

	$column = $tableActions->getTableAttributes($_REQUEST['table'], $_REQUEST['column']);
	$column->fields['attnotnull'] = $pg->phpBool($column->fields['attnotnull']);

	// Check if this is a generated column (PG12+)
	$isGenerated = isset($column->fields['attgenerated']) && $column->fields['attgenerated'] === 's';

	// Show info message for generated columns with appropriate options
	if ($isGenerated) {
		$genExpr = $column->fields['adsrc'] ?? '';
		$canEditExpression = $pg->hasEditableGeneratedColumns();
		$canDropExpression = $pg->hasDropGeneratedExpression();

		echo "<div class=\"message\">";
		echo "<strong>{$lang['strgeneratedcolumn']}:</strong> ";
		echo "GENERATED ALWAYS AS (" . html_esc($genExpr) . ") {$lang['strgeneratedstored']}";
		echo "<br><small><em>{$lang['strgeneratedcolumninfo']}</em></small>";
		echo "</div>";

		// Show form to edit generated expression (PG17+)
		if ($canEditExpression) {
			?>
			<h3><?= $lang['strsetexpression'] ?></h3>
			<form action="colproperties.php" method="post">
				<table>
					<tr>
						<th class="data left"><?= $lang['strgeneratedexpression'] ?></th>
						<td class="data1">
							<input type="text" name="new_expression" size="60" value="<?= html_esc($genExpr) ?>"
								placeholder="e.g., price * quantity" />
						</td>
					</tr>
				</table>
				<p>
					<input type="hidden" name="action" value="save_expression" />
					<?= $misc->form ?>
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
					<input type="hidden" name="column" value="<?= html_esc($_REQUEST['column']) ?>" />
					<input type="submit" name="save_expr" value="<?= $lang['strsetexpression'] ?>" />
				</p>
			</form>
			<?php
		}

		// Show option to drop expression (PG13+)
		if ($canDropExpression) {
			?>
			<h3><?= $lang['strdropexpression'] ?></h3>
			<form action="colproperties.php" method="post">
				<p><?= $lang['strdropexpressionconfirm'] ?></p>
				<p>
					<input type="hidden" name="action" value="drop_expression" />
					<?= $misc->form ?>
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
					<input type="hidden" name="column" value="<?= html_esc($_REQUEST['column']) ?>" />
					<input type="submit" name="drop_expr" value="<?= $lang['strdropexpression'] ?>" />
					<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
				</p>
			</form>
			<?php
		}

		// For generated columns, don't show the standard alter form
		// unless we're on older PG versions without SET/DROP EXPRESSION support
		if (!$canEditExpression && !$canDropExpression) {
			echo "<p><em>" . $lang['strgeneratedcolumnnoalter'] . "</em></p>";
		}

		return; // Don't show regular alter form for generated columns
	}

	?>
	<form action="colproperties.php" method="post">

		<?php
		$length = '';
		if (
			isset($column->fields['type'], $column->fields['base_type'])
			&& $column->fields['type'] != $column->fields['base_type']
			&& preg_match('/\\(([0-9, ]*)\\)/', $column->fields['type'], $bits)
		) {
			$length = $bits[1];
		}

		$renderer = new ColumnFormRenderer();
		$columns = [
			[
				'attname' => $column->fields['attname'],
				'base_type' => $column->fields['base_type'],
				'length' => $length,
				'attnotnull' => $column->fields['attnotnull'],
				'adsrc' => $column->fields['adsrc'],
				'comment' => $column->fields['comment'],
				'attgenerated' => $column->fields['attgenerated'] ?? '',
			]
		];

		// Pass isAlter option to hide Generated checkbox (cannot alter generated status)
		$renderer->renderTable($columns, $_REQUEST, ['isAlter' => true, 'isGenerated' => false]);
		?>
		<p><input type="hidden" name="action" value="save_properties" />
			<?= $misc->form ?>
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<input type="hidden" name="column" value="<?= html_esc($_REQUEST['column']) ?>" />
			<input type="hidden" name="olddefault" value="<?= html_esc($column->fields['adsrc']) ?>" />
			<input type="hidden" name="is_generated" value="" />
			<?php if ($column->fields['attnotnull']): ?>
				<input type="hidden" name="oldnotnull" value="on" />
			<?php endif; ?>
			<input type="hidden" name="oldtype"
				value="<?= html_esc($pg->formatType($column->fields['type'], $column->fields['atttypmod'])) ?>" />
			<input type="submit" name="save" value="<?= $lang['stralter'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?= $renderer->renderJavaScriptInit(1) ?>
	<?php
}

function doSaveAlter()
{

	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$columnActions = new ColumnActions($pg);


	// Check inputs
	$field = $_REQUEST['field'][0] ?? '';
	$type = $_REQUEST['type'][0] ?? '';
	$length = $_REQUEST['length'][0] ?? '';
	$array = $_REQUEST['array'][0] ?? '';
	$comment = $_REQUEST['comment'][0] ?? '';
	$notnull = isset($_REQUEST['notnull'][0]);

	if (trim($field) == '') {
		doAlter($lang['strcolneedsname']);
		return;
	}

	// Determine the actual default value from preset
	$defaultValue = '';
	$default_preset = $_REQUEST['default_preset'][0] ?? '';
	$default = $_REQUEST['default'][0] ?? '';

	if ($default_preset) {
		if ($default_preset === 'custom') {
			$defaultValue = $default;
		} elseif ($default_preset !== '') {
			$defaultValue = $default_preset;
		}
	} else {
		$defaultValue = $default;
	}

	$status = $columnActions->alterColumn(
		$_REQUEST['table'],
		$_REQUEST['column'],
		$field,
		$notnull,
		isset($_REQUEST['oldnotnull']),
		$defaultValue,
		$_REQUEST['olddefault'],
		$type,
		$length,
		$array,
		$_REQUEST['oldtype'],
		$comment
	);
	if ($status == 0) {
		if ($_REQUEST['column'] != $field) {
			$_REQUEST['column'] = $field;
			AppContainer::setShouldReloadTree(true);
		}
		doDefault($lang['strcolumnaltered']);
	} else {
		doAlter($lang['strcolumnalteredbad']);
		return;
	}

}

/**
 * Save a new generated expression for a generated column (PG17+)
 */
function doSaveExpression()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$columnActions = new ColumnActions($pg);

	$expression = trim($_REQUEST['new_expression'] ?? '');

	if ($expression === '') {
		doAlter($lang['strgeneratedexpressionrequired']);
		return;
	}

	$status = $columnActions->setGeneratedExpression(
		$_REQUEST['table'],
		$_REQUEST['column'],
		$expression
	);

	if ($status == 0) {
		doDefault($lang['strsetexpressionsuccess']);
	} else {
		doAlter($lang['strsetexpressionfailed']);
	}
}

/**
 * Drop the generated expression, converting the column to a regular column (PG13+)
 */
function doDropExpression()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$columnActions = new ColumnActions($pg);

	$status = $columnActions->dropGeneratedExpression(
		$_REQUEST['table'],
		$_REQUEST['column']
	);

	if ($status == 0) {
		AppContainer::setShouldReloadTree(true);
		doDefault($lang['strdropexpressionsuccess']);
	} else {
		doAlter($lang['strdropexpressionfailed']);
	}
}

/**
 * Show default list of columns in the table
 */
function doDefault($msg = '', $isTable = true)
{
	global $tableName;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);

	$attPre = function (&$rowdata) use ($pg) {
		$rowdata->fields['+type'] = $pg->formatType($rowdata->fields['type'], $rowdata->fields['atttypmod']);
	};

	if (empty($_REQUEST['column']))
		$msg .= "<br>{$lang['strnoobjects']}";

	$misc->printTrail('column');
	//$misc->printTitle($lang['strcolprop']);
	$misc->printTabs('column', 'properties');
	$misc->printMsg($msg);

	if (!empty($_REQUEST['column'])) {
		// Get table
		$tdata = $tableActions->getTable($tableName);
		// Get columns
		$attrs = $tableActions->getTableAttributes($tableName, $_REQUEST['column']);
		$type = $attrs->fields['type'];

		// Show comment if any
		if ($attrs->fields['comment'] !== null):
			?>
			<p class="comment"><?= $misc->formatVal($attrs->fields['comment']) ?></p>
			<?php
		endif;

		$column = [
			'column' => [
				'title' => $lang['strcolumn'],
				'field' => field('attname'),
				'icon' => 'Column',
			],
			'type' => [
				'title' => $lang['strtype'],
				'field' => field('type'),
			],
			'notnull' => [
				'title' => $lang['strnotnull'],
				'field' => field('attnotnull'),
				'type' => 'bool',
				'params' => ['true' => 'NOT NULL', 'false' => '']
			],
			'default' => [
				'title' => $lang['strdefault'],
				'field' => field('adsrc'),
			],
			/*
			'comment' => [
				'title' => $lang['strcomment'],
				'field' => field('comment'),
				'type' => 'comment',
			],
			*/
		];

		if (!$isTable) {
			unset($column['notnull'], $column['default']);
		}

		$actions = [];
		$misc->printTable($attrs, $column, $actions, 'colproperties-colproperties', null, $attPre);

		?>
		<br>
		<?php

		$f_attname = $_REQUEST['column'];
		$f_table = $tableName;
		$f_schema = $pg->_schema;
		$pg->fieldClean($f_attname);
		$pg->fieldClean($f_table);
		$pg->fieldClean($f_schema);

		if (isset(TypeActions::NON_SORTABLE_TYPES[$type])) {
			$order_clause = '';
		} else {
			$order_clause = " ORDER BY \"{$f_attname}\"";
		}

		$query = "SELECT \"{$f_attname}\", count(*) AS \"count\" FROM \"{$f_schema}\".\"{$f_table}\" GROUP BY \"{$f_attname}\"{$order_clause}";

		if ($isTable) {

			/* Browse link */
			/* FIXME browsing a col should somehow be a action so we don't
			 * send an ugly SQL in the URL */

			$navlinks = [
				'browse' => [
					'attr' => [
						'href' => [
							'url' => 'display.php',
							'urlvars' => [
								'subject' => 'column',
								'server' => $_REQUEST['server'],
								'database' => $_REQUEST['database'],
								'schema' => $_REQUEST['schema'],
								'table' => $tableName,
								'column' => $_REQUEST['column'],
								'return' => 'column',
								'query' => $query
							]
						]
					],
					'icon' => $misc->icon('Table'),
					'content' => $lang['strbrowse'],
				],
				'alter' => [
					'attr' => [
						'href' => [
							'url' => 'colproperties.php',
							'urlvars' => [
								'action' => 'properties',
								'server' => $_REQUEST['server'],
								'database' => $_REQUEST['database'],
								'schema' => $_REQUEST['schema'],
								'table' => $tableName,
								'column' => $_REQUEST['column'],
							]
						]
					],
					'icon' => $misc->icon('Edit'),
					'content' => $lang['stralter'],
				],
				'drop' => [
					'attr' => [
						'href' => [
							'url' => 'tblproperties.php',
							'urlvars' => [
								'action' => 'confirm_drop',
								'server' => $_REQUEST['server'],
								'database' => $_REQUEST['database'],
								'schema' => $_REQUEST['schema'],
								'table' => $tableName,
								'column' => $_REQUEST['column'],
							]
						]
					],
					'icon' => $misc->icon('Delete'),
					'content' => $lang['strdrop'],
				]
			];
		} else {
			/* Browse link */
			$navlinks = [
				'browse' => [
					'attr' => [
						'href' => [
							'url' => 'display.php',
							'urlvars' => [
								'subject' => 'column',
								'server' => $_REQUEST['server'],
								'database' => $_REQUEST['database'],
								'schema' => $_REQUEST['schema'],
								'view' => $tableName,
								'column' => $_REQUEST['column'],
								'return' => 'column',
								'query' => $query
							]
						]
					],
					'icon' => $misc->icon('View'),
					'content' => $lang['strbrowse']
				]
			];
		}

		if (isset(TypeActions::NON_COMPARABLE_TYPES[$type])) {
			unset($navlinks['browse']);
		}


		$misc->printNavLinks($navlinks, 'colproperties-colproperties', get_defined_vars());
	}
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';
if (isset($_REQUEST['table']))
	$tableName = &$_REQUEST['table'];
elseif (isset($_REQUEST['view']))
	$tableName = &$_REQUEST['view'];
else
	die($lang['strnotableprovided']);


$misc->printHeader($lang['strtables'] . ' - ' . $tableName);
$misc->printBody();

switch ($action) {
	case 'alter':
	case 'properties':
		doAlter();
		break;
	case 'save_properties':
		if (isset($_POST['save']))
			doSaveAlter();
		else
			doDefault();
		break;
	case 'save_expression':
		if (isset($_POST['save_expr']))
			doSaveExpression();
		else
			doAlter();
		break;
	case 'drop_expression':
		if (isset($_POST['drop_expr']))
			doDropExpression();
		else
			doAlter();
		break;
	default:
		doDefault(null, !isset($_REQUEST['view']));
		break;
}

$misc->printFooter();
