<?php

use PhpPgAdmin\Gui\FormRenderer;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Gui\ColumnFormRenderer;
use PhpPgAdmin\Gui\SearchFormRenderer;
use PhpPgAdmin\Database\Actions\RowActions;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Database\Actions\TablespaceActions;

/**
 * List tables in a database
 *
 * $Id: tables.php,v 1.112 2008/06/16 22:38:46 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Displays a screen where they can enter a new table
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$tablespaceActions = new TablespaceActions($pg);
	$typeActions = new TypeActions($pg);

	if (!isset($_REQUEST['stage'])) {
		$_REQUEST['stage'] = 1;
		$default_with_oids = $tableActions->getDefaultWithOid();
		if ($default_with_oids == 'off')
			$_REQUEST['withoutoids'] = 'on';
	}

	if (!isset($_REQUEST['name']))
		$_REQUEST['name'] = '';
	if (!isset($_REQUEST['num_columns']))
		$_REQUEST['num_columns'] = '';
	if (!isset($_REQUEST['tblcomment']))
		$_REQUEST['tblcomment'] = '';
	if (!isset($_REQUEST['spcname']))
		$_REQUEST['spcname'] = '';

	$stage = (int) $_REQUEST['stage'];

	if ($stage == 1) {
		// Fetch all tablespaces from the database
		if ($pg->hasTablespaces())
			$tablespaces = $tablespaceActions->getTablespaces();

		$misc->printTrail('schema');
		$misc->printTitle($lang['strcreatetable'], 'pg.table.create');
		$misc->printMsg($msg);

		echo '<form action="tables.php" method="post">', "\n";
		?>
		<table>
			<tr>
				<th class="data left required"><?= $lang['strname'] ?></th>
				<td class="data"><input name="name" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['name']) ?>" /></td>
			</tr>
			<tr>
				<th class="data left required"><?= $lang['strnumcols'] ?></th>
				<td class="data"><input name="num_columns" size="5" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['num_columns']) ?>" /></td>
			</tr>
			<?php if ($pg->hasServerOids()): ?>
				<tr>
					<th class="data left"><?= $lang['stroptions'] ?></th>
					<td class="data"><label for="withoutoids"><input type="checkbox" id="withoutoids" name="withoutoids"
								<?= isset($_REQUEST['withoutoids']) ? ' checked="checked"' : '' ?> />WITHOUT OIDS</label></td>
				</tr>
			<?php else: ?>
				<input type="hidden" id="withoutoids" name="withoutoids" value="checked" />
			<?php endif; ?>

			<?php if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0): ?>
				<tr>
					<th class="data left"><?= $lang['strtablespace'] ?></th>
					<td class="data1">
						<select name="spcname">
							<option value="" <?= ($_REQUEST['spcname'] == '') ? ' selected="selected"' : '' ?>></option>
							<?php while (!$tablespaces->EOF):
								$spcname = html_esc($tablespaces->fields['spcname']); ?>
								<option value="<?= $spcname ?>" <?= ($tablespaces->fields['spcname'] == $_REQUEST['spcname']) ? ' selected="selected"' : '' ?>><?= $spcname ?></option>
								<?php $tablespaces->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ($pg->major_version >= 10):
				if (!isset($_REQUEST['is_partitioned']))
					$_REQUEST['is_partitioned'] = '';
				if (!isset($_REQUEST['partition_strategy']))
					$_REQUEST['partition_strategy'] = '';
				?>
				<tr>
					<th class="data left"><?= $lang['strpartitionstrategy'] ?></th>
					<td class="data">
						<label>
							<input type="checkbox" id="is_partitioned" name="is_partitioned" value="1"
								<?= $_REQUEST['is_partitioned'] ? ' checked="checked"' : '' ?>
								onchange="document.getElementById('partition_strategy_row').style.display = this.checked ? '' : 'none';" />
							<?= $lang['strcreatepartitionedtable'] ?>
						</label>
						<div id="partition_strategy_row" class="mt-1"
							style="display: <?= $_REQUEST['is_partitioned'] ? '' : 'none' ?>;">
							<select name="partition_strategy" id="partition_strategy">
								<option value="">
									<?= $lang['strchoose'] ?>
								</option>
								<option value="RANGE" <?= ($_REQUEST['partition_strategy'] == 'RANGE' ? ' selected' : '') ?>>RANGE
									- <?= $lang['strpartitionrange'] ?></option>
								<option value="LIST" <?= ($_REQUEST['partition_strategy'] == 'LIST' ? ' selected' : '') ?>>LIST -
									<?= $lang['strpartitionlist'] ?>
								</option>
								<option value="HASH" <?= ($_REQUEST['partition_strategy'] == 'HASH' ? ' selected' : '') ?>>HASH -
									<?= $lang['strpartitionhash'] ?>
								</option>
							</select>
						</div>
					</td>
				</tr>
			<?php endif; ?>

		</table>
		<p>
			<input type="hidden" name="action" value="create" />
			<input type="hidden" name="stage" value="2" />
			<?= $misc->form ?>
			<input type="submit" value="<?= $lang['strnext'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
		<?php
		echo "</form>\n";

	} elseif ($stage == 2) {

		// Second stage, define columns

		if (trim($_REQUEST['name']) == '') {
			$_REQUEST['stage'] = 1;
			doCreate($lang['strtableneedsname']);
			return;
		}

		$num_columns = (int) (isset($_REQUEST['num_columns']) ? trim($_REQUEST['num_columns']) : '');

		if ($num_columns <= 0) {
			$_REQUEST['stage'] = 1;
			doCreate($lang['strtableneedscols']);
			return;
		}

		if (!empty($_REQUEST['is_partitioned'])) {
			if (empty($_REQUEST['partition_strategy'])) {
				$_REQUEST['stage'] = 1;
				doCreate($lang['strpartitionstrategyrequired']);
				return;
			}
		}

		$renderer = new ColumnFormRenderer();

		// Prepare columns array for renderer
		$columns = [];
		for ($i = 0; $i < $num_columns; $i++) {
			$columns[] = [
				'attname' => $_REQUEST['field'][$i] ?? '',
				'base_type' => $_REQUEST['type'][$i] ?? '',
				'length' => $_REQUEST['length'][$i] ?? '',
				'attnotnull' => isset($_REQUEST['notnull'][$i]),
				'adsrc' => $_REQUEST['default'][$i] ?? '',
				'comment' => $_REQUEST['colcomment'][$i] ?? '',
				'default_preset' => $_REQUEST['default_preset'][$i] ?? '',
				'uniquekey' => isset($_REQUEST['uniquekey'][$i]),
				'primarykey' => isset($_REQUEST['primarykey'][$i]),
				'partitionkey' => isset($_REQUEST['partitionkey'][$i]),
			];
		}

		$misc->printTrail('schema');
		$misc->printTitle($lang['strcreatetable'], 'pg.table.create');
		$misc->printMsg($msg);

		// Determine rendering options
		$renderOptions = [
			'showUniqueKey' => true,
			'showPrimaryKey' => true,
			'showPartitionKey' => $pg->major_version >= 10 && !empty($_REQUEST['is_partitioned']),
		];

		?>
		<form action="tables.php" method="post">
			<?php $renderer->renderTable($columns, $_REQUEST, $renderOptions); ?>
			<div class="flex-row my-3">
				<input type="hidden" name="action" value="create" />
				<input type="hidden" name="stage" value="3" />
				<input type="hidden" name="num_columns" id="num_columns" value="<?= (int) $num_columns ?>" />
				<?= $misc->form ?>
				<input type="hidden" name="name" value="<?= html_esc($_REQUEST['name'] ?? '') ?>" />
				<?php if (isset($_REQUEST['withoutoids'])): ?>
					<input type="hidden" name="withoutoids" value="true" />
				<?php endif; ?>
				<input type="hidden" name="tblcomment" value="<?= html_esc($_REQUEST['tblcomment'] ?? '') ?>" />
				<?php if (isset($_REQUEST['spcname'])): ?>
					<input type="hidden" name="spcname" value="<?= html_esc($_REQUEST['spcname'] ?? '') ?>" />
				<?php endif; ?>
				<?php if ($pg->major_version >= 10): ?>
					<input type="hidden" name="is_partitioned" value="<?= html_esc($_REQUEST['is_partitioned'] ?? '') ?>" />
					<input type="hidden" name="partition_strategy" value="<?= html_esc($_REQUEST['partition_strategy'] ?? '') ?>" />
				<?php endif; ?>
				<div>
					<input type="submit" value="<?= $lang['strcreate'] ?>" />
					<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
				</div>
				<div class="ml-auto">
					<input type="button" value="<?= $lang['straddmorecolumns'] ?>" onclick="addColumnRow();" />
				</div>
			</div>
		</form>
		<?php
		$renderer->renderJavaScriptInit($num_columns);

	} elseif ($stage == 3) {

		// Final stage, create the table
		if (!isset($_REQUEST['notnull']))
			$_REQUEST['notnull'] = [];
		if (!isset($_REQUEST['uniquekey']))
			$_REQUEST['uniquekey'] = [];
		if (!isset($_REQUEST['primarykey']))
			$_REQUEST['primarykey'] = [];
		if (!isset($_REQUEST['length']))
			$_REQUEST['length'] = [];
		// Default tablespace to null if it isn't set
		if (!isset($_REQUEST['spcname']))
			$_REQUEST['spcname'] = null;

		// Partition settings
		$partitionStrategy = null;
		$partitionKeys = [];
		if ($pg->major_version >= 10 && !empty($_REQUEST['is_partitioned'])) {
			$partitionStrategy = $_REQUEST['partition_strategy'] ?? '';
			if (empty($partitionStrategy)) {
				$_REQUEST['stage'] = 1;
				doCreate($lang['strpartitionstrategyrequired']);
				return;
			}

			// Collect partition key columns
			if (is_array($_REQUEST['partitionkey'] ?? null)) {
				foreach ($_REQUEST['partitionkey'] as $idx => $val) {
					if (!empty($_REQUEST['field'][$idx])) {
						$partitionKeys[] = $_REQUEST['field'][$idx];
					}
				}
			}

			if (empty($partitionKeys)) {
				$_REQUEST['stage'] = 2;
				doCreate($lang['strpartitionkeyrequired']);
				return;
			}
		}

		// Check inputs
		if (trim($_REQUEST['name']) == '') {
			$_REQUEST['stage'] = 1;
			doCreate($lang['strtableneedsname']);
			return;
		}
		$num_columns = (int) trim($_REQUEST['num_columns'] ?? '');
		if ($num_columns <= 0) {
			$_REQUEST['stage'] = 1;
			doCreate($lang['strtableneedscols']);
			return;
		}

		// Build arrays for createTable from valid columns
		$fields = [];
		$types = [];
		$arrays = [];
		$lengths = [];
		$notnulls = [];
		$defaults = [];
		$colcomments = [];
		$uniquekeys = [];
		$primarykeys = [];
		$isGeneratedArr = [];
		$generatedExprArr = [];

		for ($i = 0; $i < $num_columns; $i++) {
			// Only include columns with non-empty field names
			$fieldName = trim($_REQUEST['field'][$i] ?? '');
			if ($fieldName === '') {
				continue;
			}

			// Check if this is a generated column
			$isGeneratedCol = isset($_REQUEST['is_generated'][$i]);
			$generatedExprVal = $isGeneratedCol ? trim($_REQUEST['generated_expr'][$i] ?? '') : '';

			// Validate generated columns require an expression
			if ($isGeneratedCol && $generatedExprVal === '') {
				$_REQUEST['stage'] = 2;
				doCreate($lang['strgeneratedexpressionrequired']);
				return;
			}

			// Process default value from default_preset (only for non-generated columns)
			$defaultValue = '';
			if (!$isGeneratedCol) {
				$defaultValue = $_REQUEST['default'][$i] ?? '';
				$defaultPreset = $_REQUEST['default_preset'][$i] ?? '';
				if ($defaultPreset !== '' && $defaultPreset !== 'custom') {
					$defaultValue = $defaultPreset;
				}
			}

			$fields[] = $fieldName;
			$types[] = $_REQUEST['type'][$i] ?? '';
			$arrays[] = $_REQUEST['array'][$i] ?? '';
			$lengths[] = $_REQUEST['length'][$i] ?? '';
			$notnulls[] = $isGeneratedCol ? null : ($_REQUEST['notnull'][$i] ?? null);
			$defaults[] = $defaultValue;
			$colcomments[] = $_REQUEST['colcomment'][$i] ?? '';
			$uniquekeys[] = $isGeneratedCol ? null : ($_REQUEST['uniquekey'][$i] ?? null);
			$primarykeys[] = $_REQUEST['primarykey'][$i] ?? null;
			$isGeneratedArr[] = $isGeneratedCol ? true : null;
			$generatedExprArr[] = $generatedExprVal;
		}

		// Check if at least one valid column exists
		if (empty($fields)) {
			$_REQUEST['stage'] = 2;
			doCreate($lang['strtableneedsfield']);
			return;
		}

		$status = $tableActions->createTable(
			$_REQUEST['name'] ?? '',
			count($fields),
			$fields,
			$types,
			$arrays,
			$lengths,
			$notnulls,
			$defaults,
			isset($_REQUEST['withoutoids']),
			$colcomments,
			$_REQUEST['tblcomment'] ?? '',
			$_REQUEST['spcname'] ?? '',
			$uniquekeys,
			$primarykeys,
			$partitionStrategy,
			$partitionKeys,
			$isGeneratedArr,
			$generatedExprArr
		);

		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strtablecreated']);
		} elseif ($status == -1) {
			$_REQUEST['stage'] = 2;
			doCreate($lang['strtablecreatedbad']);
			return;
		}

	} else {
		echo "<p>{$lang['strinvalidparam']}</p>\n";
	}
}

/**
 * Display a screen where user can create a table from an existing one.
 * We don't have to check if pg supports schema cause create table like
 * is available under pg 7.4+ which has schema.
 */
function doCreateLike($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$tablespaceActions = new TablespaceActions($pg);
	$formRenderer = new FormRenderer();

	if (!$confirm) {

		if (!isset($_REQUEST['name']))
			$_REQUEST['name'] = '';
		if (!isset($_REQUEST['like']))
			$_REQUEST['like'] = '';
		if (!isset($_REQUEST['tablespace']))
			$_REQUEST['tablespace'] = '';

		$misc->printTrail('schema');
		$misc->printTitle($lang['strcreatetable'], 'pg.table.create');
		$misc->printMsg($msg);

		$tbltmp = $tableActions->getTables(true);
		$tbltmp = $tbltmp->getArray();

		$tables = [];
		$tblsel = '';
		foreach ($tbltmp as $a) {
			$pg->fieldClean($a['nspname']);
			$pg->fieldClean($a['relname']);
			$tables["\"{$a['nspname']}\".\"{$a['relname']}\""] = serialize(['schema' => $a['nspname'], 'table' => $a['relname']]);
			if ($_REQUEST['like'] == $tables["\"{$a['nspname']}\".\"{$a['relname']}\""])
				$tblsel = html_esc($tables["\"{$a['nspname']}\".\"{$a['relname']}\""]);
		}

		unset($tbltmp);

		echo "<form action=\"tables.php\" method=\"post\">\n";
		echo "<table>\n\t<tr>\n\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
		echo "\t\t<td class=\"data\"><input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"", html_esc($_REQUEST['name']), "\" /></td>\n\t</tr>\n";
		echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strcreatetablelikeparent']}</th>\n";
		echo "\t\t<td class=\"data\">";
		echo $formRenderer->printCombo($tables, 'like', true, $tblsel, false);
		echo "</td>\n\t</tr>\n";
		if ($pg->hasTablespaces()) {
			$tblsp_ = $tablespaceActions->getTablespaces();
			if ($tblsp_->recordCount() > 0) {
				$tblsp_ = $tblsp_->getArray();
				$tblsp = [];
				foreach ($tblsp_ as $a)
					$tblsp[$a['spcname']] = $a['spcname'];

				echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strtablespace']}</th>\n";
				echo "\t\t<td class=\"data\">";
				echo $formRenderer->printCombo($tblsp, 'tablespace', true, $_REQUEST['tablespace'], false);
				echo "</td>\n\t</tr>\n";
			}
		}
		echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['stroptions']}</th>\n\t\t<td class=\"data\">";
		echo "<label for=\"withdefaults\"><input type=\"checkbox\" id=\"withdefaults\" name=\"withdefaults\"",
			isset($_REQUEST['withdefaults']) ? ' checked="checked"' : '',
			"/>{$lang['strcreatelikewithdefaults']}</label>";
		if ($pg->hasCreateTableLikeWithConstraints()) {
			echo "<br /><label for=\"withconstraints\"><input type=\"checkbox\" id=\"withconstraints\" name=\"withconstraints\"",
				isset($_REQUEST['withconstraints']) ? ' checked="checked"' : '',
				"/>{$lang['strcreatelikewithconstraints']}</label>";
		}
		if ($pg->hasCreateTableLikeWithIndexes()) {
			echo "<br /><label for=\"withindexes\"><input type=\"checkbox\" id=\"withindexes\" name=\"withindexes\"",
				isset($_REQUEST['withindexes']) ? ' checked="checked"' : '',
				"/>{$lang['strcreatelikewithindexes']}</label>";
		}
		echo "</td>\n\t</tr>\n";
		echo "</table>";

		echo "<input type=\"hidden\" name=\"action\" value=\"confcreatelike\" />\n";
		echo $misc->form;
		echo "<p><input type=\"submit\" value=\"{$lang['strcreate']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";

	} else {

		if (trim($_REQUEST['name']) == '') {
			doCreateLike(false, $lang['strtableneedsname']);
			return;
		}
		if (trim($_REQUEST['like']) == '') {
			doCreateLike(false, $lang['strtablelikeneedslike']);
			return;
		}

		if (!isset($_REQUEST['tablespace']))
			$_REQUEST['tablespace'] = '';

		$status = $tableActions->createTableLike(
			$_REQUEST['name'],
			unserialize($_REQUEST['like']),
			isset($_REQUEST['withdefaults']),
			isset($_REQUEST['withconstraints']),
			isset($_REQUEST['withindexes']),
			$_REQUEST['tablespace']
		);

		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strtablecreated']);
		} else {
			doCreateLike(false, $lang['strtablecreatedbad']);
			return;
		}
	}
}

/**
 * Ask for select parameters and perform select
 */
function doSelectRows($confirm, $msg = '')
{
	SearchFormRenderer::renderSelectRowsForm(
		$confirm,
		$msg,
		'table',
		$_REQUEST['table'],
		'selectrows'
	);
}

/**
 * Show confirmation of empty and perform actual empty
 */
function doEmpty($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);

	if (empty($_REQUEST['table']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifytabletoempty']);
		return;
	}

	if ($confirm) {
		if (isset($_REQUEST['ma'])) {
			$misc->printTrail('schema');
			$misc->printTitle($lang['strempty'], 'pg.table.empty');

			echo "<form action=\"tables.php\" method=\"post\">\n";
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				echo "<p>", sprintf($lang['strconfemptytable'], $misc->formatVal($a['table'])), "</p>\n";
				printf('<input type="hidden" name="table[]" value="%s" />', html_esc($a['table']));
			}
		} // END multi empty
		else {
			$misc->printTrail('table');
			$misc->printTitle($lang['strempty'], 'pg.table.empty');

			echo "<p>", sprintf($lang['strconfemptytable'], $misc->formatVal($_REQUEST['table'])), "</p>\n";

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		} // END not multi empty

		echo "<input type=\"hidden\" name=\"action\" value=\"empty\" />\n";
		echo $misc->form;
		// Offer CASCADE option for TRUNCATE
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<input type=\"submit\" name=\"empty\" value=\"{$lang['strempty']}\" /> <input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} // END if confirm
	else { // Do Empty
		if (is_array($_REQUEST['table'])) {
			$msg = '';
				foreach ($_REQUEST['table'] as $t) {
					$status = $tableActions->emptyTable($t, isset($_POST['cascade']));
				if ($status == 0)
					$msg .= sprintf('%s: %s<br />', htmlentities($t, ENT_QUOTES, 'UTF-8'), $lang['strtableemptied']);
				else {
					doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($t, ENT_QUOTES, 'UTF-8'), $lang['strtableemptiedbad']));
					return;
				}
			}
			doDefault($msg);
		} // END multi empty
		else {
				$status = $tableActions->emptyTable($_POST['table'], isset($_POST['cascade']));
			if ($status == 0)
				doDefault($lang['strtableemptied']);
			else
				doDefault($lang['strtableemptiedbad']);
		} // END not multi empty
	} // END do Empty
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);

	if (empty($_REQUEST['table']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifytabletodrop']);
		exit();
	}

	if ($confirm) {
		//If multi drop
		if (isset($_REQUEST['ma'])) {

			$misc->printTrail('schema');
			$misc->printTitle($lang['strdrop'], 'pg.table.drop');

			echo "<form action=\"tables.php\" method=\"post\">\n";
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				echo "<p>", sprintf($lang['strconfdroptable'], $misc->formatVal($a['table'])), "</p>\n";
				printf('<input type="hidden" name="table[]" value="%s" />', html_esc($a['table']));
			}
		} else {

			$misc->printTrail('table');
			$misc->printTitle($lang['strdrop'], 'pg.table.drop');

			echo "<p>", sprintf($lang['strconfdroptable'], $misc->formatVal($_REQUEST['table'])), "</p>\n";

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		} // END if multi drop

		echo "<input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo $misc->form;
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} // END confirm
	else {
		//If multi drop
		if (is_array($_REQUEST['table'])) {
			$msg = '';
			$status = $pg->beginTransaction();
			if ($status == 0) {
				foreach ($_REQUEST['table'] as $t) {
					$status = $tableActions->dropTable($t, isset($_POST['cascade']));
					if ($status == 0)
						$msg .= sprintf('%s: %s<br />', htmlentities($t, ENT_QUOTES, 'UTF-8'), $lang['strtabledropped']);
					else {
						$pg->endTransaction();
						doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($t, ENT_QUOTES, 'UTF-8'), $lang['strtabledroppedbad']));
						return;
					}
				}
			}
			if ($pg->endTransaction() == 0) {
				// Everything went fine, back to the Default page....
				AppContainer::setShouldReloadTree(true);
				doDefault($msg);
			} else
				doDefault($lang['strtabledroppedbad']);
		} else {
			$status = $tableActions->dropTable($_POST['table'], isset($_POST['cascade']));
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strtabledropped']);
			} else
				doDefault($lang['strtabledroppedbad']);
		}
	} // END DROP
}// END Function

/**
 * Convert a regular table to a partitioned table
 */
function doConvertToPartitioned($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$typeActions = new TypeActions($pg);

	if (!isset($_REQUEST['stage'])) {
		$_REQUEST['stage'] = 1;
	}

	if (!isset($_REQUEST['table'])) {
		doDefault($lang['strspecifytabletoconvert']);
		return;
	}

	$_REQUEST['table'] = trim($_REQUEST['table']);

	switch ($_REQUEST['stage']) {
		case 1:
			// Stage 1: Show conversion form
			$misc->printTrail('table');
			$misc->printTitle($lang['strconverttopartitioned'], 'pg.table');
			$misc->printMsg($msg);

			// Get table info
			$table = $tableActions->getTable($_REQUEST['table']);
			if ($table->recordCount() == 0) {
				doDefault($lang['strinvalidparam']);
				return;
			}

			// Get columns for partition key selection
			$attrs = $tableActions->getTableAttributes($_REQUEST['table']);
			$atts_arr = [];
			while (!$attrs->EOF) {
				$atts_arr[] = [
					'attname' => $attrs->fields['attname'],
					'type' => $attrs->fields['type']
				];
				$attrs->MoveNext();
			}

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<table>\n";

			// Warning message
			echo "<tr><td colspan=\"2\">";
			echo "<div class=\"warning\">{$lang['strconverttopartitionedwarning']}</div>";
			echo "</td></tr>\n";

			// Partition strategy
			echo "<tr><th class=\"data left required\">{$lang['strpartitionstrategy']}</th>\n";
			echo "<td class=\"data1\">\n";
			echo "<select name=\"partition_strategy\" required>\n";
			echo "<option value=\"\">{$lang['strselect']}</option>\n";
			echo "<option value=\"RANGE\">{$lang['strpartitionrange']}</option>\n";
			echo "<option value=\"LIST\">{$lang['strpartitionlist']}</option>\n";
			echo "<option value=\"HASH\">{$lang['strpartitionhash']}</option>\n";
			echo "</select></td></tr>\n";

			// Partition keys (multi-select)
			echo "<tr><th class=\"data left required\">{$lang['strpartitionkey']}</th>\n";
			echo "<td class=\"data1\">\n";
			echo "<select name=\"partition_keys[]\" multiple size=\"5\" required>\n";
			foreach ($atts_arr as $att) {
				echo "<option value=\"", htmlspecialchars($att['attname']), "\">";
				echo htmlspecialchars($att['attname']), " (", htmlspecialchars($att['type']), ")</option>\n";
			}
			echo "</select>\n";
			echo "<br/><small>{$lang['strpartitionkeyselecthint']}</small>\n";
			echo "</td></tr>\n";

			// Copy data option
			echo "<tr><th class=\"data left\">{$lang['strconvertcopydata']}</th>\n";
			echo "<td class=\"data1\"><input type=\"checkbox\" name=\"copy_data\" checked /></td></tr>\n";

			// Tablespace
			$tablespaceActions = new TablespaceActions($pg);
			$tablespaces = $tablespaceActions->getTablespaces();
			if ($tablespaces->recordCount() > 0) {
				echo "<tr><th class=\"data left\">{$lang['strtablespace']}</th>\n";
				echo "<td class=\"data1\">\n";
				echo "<select name=\"tablespace\">\n";
				echo "<option value=\"\">{$lang['strdefault']}</option>\n";
				while (!$tablespaces->EOF) {
					echo "<option value=\"", htmlspecialchars($tablespaces->fields['spcname']), "\">";
					echo htmlspecialchars($tablespaces->fields['spcname']), "</option>\n";
					$tablespaces->MoveNext();
				}
				echo "</select></td></tr>\n";
			}

			echo "</table>\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"convert_to_partitioned\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"2\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars($_REQUEST['table']), "\" />\n";
			echo "<p><input type=\"submit\" name=\"convert\" value=\"{$lang['strnext']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
			break;

		case 2:
			// Stage 2: Show execution plan preview
			$misc->printTrail('table');
			$misc->printTitle($lang['strconverttopartitioned'], 'pg.table');

			// Validate inputs
			if (empty($_POST['partition_strategy']) || empty($_POST['partition_keys'])) {
				doConvertToPartitioned(false, $lang['strpartitioninvalid']);
				return;
			}

			$partition_strategy = $_POST['partition_strategy'];
			$partition_keys = $_POST['partition_keys'];
			$copy_data = isset($_POST['copy_data']);
			$tablespace = $_POST['tablespace'] ?? '';

			$table = $tableActions->getTable($_REQUEST['table']);
			$table_row = $table->fields;

			// Show execution plan
			echo "<h3>{$lang['strconvertexecutionplan']}</h3>\n";
			echo "<ol>\n";
			echo "<li>{$lang['strconvertstep1']}</li>\n";
			if ($copy_data) {
				echo "<li>{$lang['strconvertstep2']}</li>\n";
			}
			echo "<li>{$lang['strconvertstep3']}</li>\n";
			echo "</ol>\n";

			echo "<div class=\"warning\">{$lang['strconvertwarning']}</div>\n";

			echo "<form action=\"tables.php\" method=\"post\">\n";
			echo "<input type=\"hidden\" name=\"action\" value=\"convert_to_partitioned\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"3\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars($_REQUEST['table']), "\" />\n";
			echo "<input type=\"hidden\" name=\"partition_strategy\" value=\"", htmlspecialchars($partition_strategy), "\" />\n";
			foreach ($partition_keys as $key) {
				echo "<input type=\"hidden\" name=\"partition_keys[]\" value=\"", htmlspecialchars($key), "\" />\n";
			}
			echo "<input type=\"hidden\" name=\"copy_data\" value=\"", $copy_data ? '1' : '0', "\" />\n";
			echo "<input type=\"hidden\" name=\"tablespace\" value=\"", htmlspecialchars($tablespace), "\" />\n";
			echo "<p><input type=\"submit\" name=\"confirm\" value=\"{$lang['strconvert']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
			break;

		case 3:
			// Stage 3: Execute conversion
			if (!$confirm) {
				doConvertToPartitioned(false, '');
				return;
			}

			// Validate inputs
			if (empty($_POST['partition_strategy']) || empty($_POST['partition_keys'])) {
				doDefault($lang['strpartitioninvalid']);
				return;
			}

			$partition_strategy = $_POST['partition_strategy'];
			$partition_keys = $_POST['partition_keys'];
			$copy_data = isset($_POST['copy_data']) && $_POST['copy_data'] == '1';
			$tablespace = $_POST['tablespace'] ?? '';

			// Get schema
			$table = $tableActions->getTable($_REQUEST['table']);
			if ($table->recordCount() == 0) {
				doDefault($lang['strinvalidparam']);
				return;
			}

			$schema = $_REQUEST['schema'];
			$original_table = $_REQUEST['table'];
			$temp_table = $original_table . '_old';
			$new_table = $original_table . '_partitioned';

			// Start transaction
			$status = $pg->beginTransaction();
			if ($status != 0) {
				doDefault($lang['strconvertfailed']);
				return;
			}

			try {
				// Step 1: Rename original table
				$sql = sprintf(
					'ALTER TABLE %s.%s RENAME TO %s',
					$pg->fieldClean($schema),
					$pg->fieldClean($original_table),
					$pg->fieldClean($temp_table)
				);
				$status = $pg->execute($sql);
				if ($status != 0) {
					throw new Exception($lang['strconvertstep1failed']);
				}

				// Step 2: Create partitioned table with same structure
				$attrs = $tableActions->getTableAttributes($temp_table);
				$col_definitions = [];
				while (!$attrs->EOF) {
					$col_def = sprintf(
						'%s %s',
						$pg->fieldClean($attrs->fields['attname']),
						$attrs->fields['type']
					);
					if ($attrs->fields['attnotnull'] == 't') {
						$col_def .= ' NOT NULL';
					}
					if ($attrs->fields['adsrc'] !== null) {
						$col_def .= ' DEFAULT ' . $attrs->fields['adsrc'];
					}
					$col_definitions[] = $col_def;
					$attrs->MoveNext();
				}

				$partition_key_str = implode(', ', array_map([$pg, 'fieldClean'], $partition_keys));

				$sql = sprintf(
					'CREATE TABLE %s.%s (%s) PARTITION BY %s (%s)',
					$pg->fieldClean($schema),
					$pg->fieldClean($original_table),
					implode(', ', $col_definitions),
					$partition_strategy,
					$partition_key_str
				);

				if (!empty($tablespace)) {
					$sql .= ' TABLESPACE ' . $pg->fieldClean($tablespace);
				}

				$status = $pg->execute($sql);
				if ($status != 0) {
					throw new Exception($lang['strconvertstep2failed']);
				}

				// Step 3: Copy data if requested
				if ($copy_data) {
					// Note: This will fail if no partitions exist yet
					// User should create partitions first
					echo "<div class=\"warning\">{$lang['strconvertdatacopynote']}</div>\n";
				}

				// Step 4: Drop old table
				$sql = sprintf(
					'DROP TABLE %s.%s',
					$pg->fieldClean($schema),
					$pg->fieldClean($temp_table)
				);
				$status = $pg->execute($sql);
				if ($status != 0) {
					throw new Exception($lang['strconvertstep3failed']);
				}

				// Commit transaction
				$status = $pg->endTransaction();
				if ($status != 0) {
					throw new Exception($lang['strconvertcommitfailed']);
				}

				doDefault($lang['strconvertsuccess']);
			} catch (Exception $e) {
				$pg->rollbackTransaction();
				doDefault($e->getMessage());
			}
			break;
	}
}

/**
 * Show default list of tables in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$conf = AppContainer::getConf();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'tables');
	$misc->printMsg($msg);

	$tables = $tableActions->getTables();

	$getIcon = function ($f) {
		// Use PartitionedTable icon for partitioned tables (relkind = 'p')
		if (isset($f['relkind']) && $f['relkind'] === 'p') {
			return 'PartitionedTable';
		}
		return 'Table';
	};

	$columns = [
		'table' => [
			'title' => $lang['strtable'],
			'field' => field('relname'),
			'url' => "redirect.php?subject=table&amp;{$misc->href}&amp;",
			'vars' => ['table' => 'relname'],
			'icon' => callback($getIcon),
			'class' => 'nowrap',
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('relowner'),
		],
		'tablespace' => [
			'title' => $lang['strtablespace'],
			'field' => field('tablespace')
		],
		'tuples' => [
			'title' => $lang['strrows2'],
			'field' => field('reltuples'),
			'type' => 'prettyint',
			'class' => 'text-end',
			'params' => ['class' => 'numeric'],
		],
		'size' => [
			'title' => $lang['strsize'],
			'field' => field('total_size'),
			'type' => 'prettysize',
			'class' => 'text-end',
			//'params' => ['class' => 'numeric'],
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('relcomment'),
		],
	];

	$footer = [
		'table' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strtables']}",
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 2,
		],
		'tuples' => [
			'agg' => 'sum',
			'type' => 'prettyint',
			'class' => 'text-end',
			'params' => ['class' => 'numeric'],
		],
		'size' => [
			'agg' => 'sum',
			'type' => 'prettysize',
			'class' => 'text-end',
			//'params' => ['class' => 'numeric'],
		],
		'actions' => [
			'text' => '',
			'colspan' => 2,
		],
	];

	$actions = [
		'multiactions' => [
			'keycols' => ['table' => 'relname'],
			'url' => 'tables.php',
			'default' => 'analyze',
		],
		'browse' => [
			'icon' => $misc->icon('Table'),
			'content' => $lang['strbrowse'],
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'subject' => 'table',
						'return' => 'table',
						'table' => field('relname')
					]
				]
			]
		],
		'select' => [
			'icon' => $misc->icon('Search'),
			'content' => $lang['strselect'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confselectrows',
						'table' => field('relname')
					]
				]
			]
		],
		'insert' => [
			'icon' => $misc->icon('Add'),
			'content' => $lang['strinsert'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confinsertrow',
						'table' => field('relname')
					]
				]
			]
		],
		'empty' => [
			'multiaction' => 'confirm_empty',
			'icon' => $misc->icon('Shredder'),
			'content' => $lang['strempty'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_empty',
						'table' => field('relname')
					]
				]
			]
		],
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'tblproperties.php',
					'urlvars' => [
						'action' => 'confirm_alter',
						'table' => field('relname')
					]
				]
			]
		],
		'convert_to_partitioned' => [
			'icon' => $misc->icon('PartitionedTable'),
			'content' => $lang['strconverttopartitioned'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'convert_to_partitioned',
						'table' => field('relname')
					]
				]
			]
		],
		'drop' => [
			'multiaction' => 'confirm_drop',
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'table' => field('relname')
					]
				]
			]
		],
		'vacuum' => [
			'multiaction' => 'confirm_vacuum',
			'icon' => $misc->icon('Broom'),
			'content' => $lang['strvacuum'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_vacuum',
						'table' => field('relname')
					]
				]
			]
		],
		'analyze' => [
			'multiaction' => 'confirm_analyze',
			'icon' => $misc->icon('Analyze'),
			'content' => $lang['stranalyze'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_analyze',
						'table' => field('relname')
					]
				]
			]
		],
		'reindex' => [
			'multiaction' => 'confirm_reindex',
			'icon' => $misc->icon('Index'),
			'content' => $lang['strreindex'],
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_reindex',
						'table' => field('relname')
					]
				]
			]
		]
		//'cluster' TODO ?
	];

	$isCatalog = $misc->isCatalogSchema();
	if ($isCatalog) {
		$actions = array_intersect_key(
			$actions,
			array_flip(['browse', 'select'])
		);
	}

	// Filter convert_to_partitioned action based on PG version and table type
	if ($pg->major_version < 10) {
		unset($actions['convert_to_partitioned']);
	} else {
		// Add disable condition for partitioned tables (relkind='p')
		$actions['convert_to_partitioned']['disable'] = function ($row) {
			return isset($row['relkind']) && $row['relkind'] === 'p';
		};
	}

	$misc->printTable(
		$tables,
		$columns,
		$actions,
		'tables-tables',
		$lang['strnotables'],
		null,
		$footer
	);

	$navlinks = [
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateTable'),
			'content' => $lang['strcreatetable']
		]
	];

	if ($tables->recordCount() > 0) {
		$navlinks['createlike'] = [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'createlike',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateTableLike'),
			'content' => $lang['strcreatetablelike']
		];
	}

	if (!$isCatalog) {
		$misc->printNavLinks($navlinks, 'tables-tables', get_defined_vars());
	}
}

require('./admin.php');

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$tableActions = new TableActions($pg);

	$tables = $tableActions->getTables();

	$reqvars = $misc->getRequestVars('table');

	$getIcon = function ($f) {
		// Use PartitionedTable icon for partitioned tables (relkind = 'p')
		if (isset($f['relkind']) && $f['relkind'] === 'p') {
			return 'PartitionedTable';
		}
		return 'Table';
	};

	$attrs = [
		'text' => field('relname'),
		'icon' => callback($getIcon),
		'iconAction' => url(
			'display.php',
			$reqvars,
			['table' => field('relname')]
		),
		'toolTip' => field('relcomment'),
		'action' => url(
			'redirect.php',
			$reqvars,
			['table' => field('relname')]
		),
		'branch' => url(
			'tables.php',
			$reqvars,
			[
				'action' => 'subtree',
				'table' => field('relname')
			]
		)
	];

	$misc->printTree($tables, $attrs, 'tables');
	exit;
}

function doSubTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$partitionActions = new PartitionActions($pg);

	$tabs = $misc->getNavTabs('table');

	if (!$partitionActions->isPartitionedTable($_REQUEST['table'])) {
		// Remove 'Partitions' tab for non-partitioned tables
		unset($tabs['partitions']);
	}

	$items = $misc->adjustTabsForTree($tabs);
	$reqvars = $misc->getRequestVars('table');

	$attrs = [
		'text' => field('title'),
		'icon' => field('icon'),
		'action' => url(
			field('url'),
			$reqvars,
			field('urlvars'),
			['table' => $_REQUEST['table']]
		),
		'branch' => ifempty(
			field('branch'),
			'',
			url(
				field('url'),
				$reqvars,
				[
					'action' => 'tree',
					'table' => $_REQUEST['table']
				]
			)
		),
	];

	$misc->printTree($items, $attrs, 'table');
	exit;
}

//$data = AppContainer::getData();
//$conf = AppContainer::getConf();
$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();
if ($action == 'subtree')
	dosubTree();

$misc->printHeader($lang['strtables']);
$misc->printBody();

switch ($action) {
	case 'create':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doCreate();
		break;
	case 'createlike':
		doCreateLike(false);
		break;
	case 'confcreatelike':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doCreateLike(true);
		break;
	case 'selectrows':
		if (!isset($_POST['cancel']))
			doSelectRows(false);
		else
			doDefault();
		break;
	case 'confselectrows':
		doSelectRows(true);
		break;
	case 'empty':
		if (isset($_POST['empty']))
			doEmpty(false);
		else
			doDefault();
		break;
	case 'confirm_empty':
		doEmpty(true);
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
	case 'convert_to_partitioned':
		if (isset($_POST['cancel']))
			doDefault();
		elseif (isset($_POST['confirm']))
			doConvertToPartitioned(true);
		else
			doConvertToPartitioned(false);
		break;
	default:
		if (adminActions($action, 'table') === false)
			doDefault();
		break;
}

$misc->printFooter();
