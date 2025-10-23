<?php

use PhpPgAdmin\Gui\FormRenderer;
use PhpPgAdmin\Gui\SearchFormRenderer;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\ViewActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\IndexActions;

/**
 * Manage views in a database
 *
 * $Id: views.php,v 1.75 2007/12/15 22:57:43 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Ask for select parameters and perform select
 */
function doSelectRows($confirm, $msg = '')
{
	SearchFormRenderer::renderSelectRowsForm(
		$confirm,
		$msg,
		'view',
		$_REQUEST['view'],
		'schema'
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
	$viewActions = new ViewActions($pg);

	if (empty($_REQUEST['view']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifyviewtodrop']);
		exit();
	}

	if ($confirm) {
		$misc->printTrail('view');
		$misc->printTitle($lang['strdrop'], 'pg.view.drop');

		echo "<form action=\"views.php\" method=\"post\">\n";

		//If multi drop
		if (isset($_REQUEST['ma'])) {
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				echo "<p>", sprintf($lang['strconfdropview'], $misc->formatVal($a['view'])), "</p>\n";
				echo '<input type="hidden" name="view[]" value="', html_esc($a['view']), "\" />\n";
			}
		} else {
			echo "<p>", sprintf($lang['strconfdropview'], $misc->formatVal($_REQUEST['view'])), "</p>\n";
			echo "<input type=\"hidden\" name=\"view\" value=\"", html_esc($_REQUEST['view']), "\" />\n";
		}

		echo "<input type=\"hidden\" name=\"action\" value=\"drop\" />\n";

		echo $misc->form;
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} else {
		if (is_array($_POST['view'])) {
			$msg = '';
			$status = $pg->beginTransaction();
			if ($status == 0) {
				foreach ($_POST['view'] as $s) {
					$status = $viewActions->dropView($s, isset($_POST['cascade']));
					if ($status == 0)
						$msg .= sprintf('%s: %s<br />', htmlentities($s, ENT_QUOTES, 'UTF-8'), $lang['strviewdropped']);
					else {
						$pg->endTransaction();
						doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($s, ENT_QUOTES, 'UTF-8'), $lang['strviewdroppedbad']));
						return;
					}
				}
			}
			if ($pg->endTransaction() == 0) {
				// Everything went fine, back to the Default page....
				AppContainer::setShouldReloadTree(true);
				doDefault($msg);
			} else
				doDefault($lang['strviewdroppedbad']);
		} else {
			$status = $viewActions->dropView($_POST['view'], isset($_POST['cascade']));
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strviewdropped']);
			} else
				doDefault($lang['strviewdroppedbad']);
		}
	}
}

/**
 * Show confirmation of refresh and perform actual refresh for materialized views
 */
function doRefresh($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$viewActions = new ViewActions($pg);
	$indexActions = new IndexActions($pg);

	if (empty($_REQUEST['view'])) {
		doDefault($lang['strspecifyviewtorefresh']);
		exit();
	}

	if ($confirm) {
		$misc->printTrail('view');
		$misc->printTitle($lang['strrefreshmaterializedview'], 'pg.matview.alter');

		echo "<form action=\"views.php\" method=\"post\">\n";
		echo "<p>", sprintf($lang['strconfrefreshmaterializedview'], $misc->formatVal($_REQUEST['view'])), "</p>\n";

		// Check if CONCURRENTLY option is available
		$canUseConcurrently = false;
		if ($pg->major_version >= 9.4) {
			$uniqueIndexes = $indexActions->getIndexes($_REQUEST['view'], true);
			$canUseConcurrently = !$uniqueIndexes->EOF;
		}

		if ($canUseConcurrently) {
			echo "<p><input type=\"checkbox\" id=\"concurrent\" name=\"concurrent\" /> <label for=\"concurrent\">{$lang['strconcurrently']}</label></p>\n";
			echo "<p class=\"message\">{$lang['strconcurrentlyrequiresunique']}</p>\n";
		} elseif ($pg->major_version >= 9.4) {
			echo "<p class=\"message\">{$lang['strconcurrentlyneedsunique']}</p>\n";
		}

		echo "<input type=\"hidden\" name=\"action\" value=\"refresh\" />\n";
		echo "<input type=\"hidden\" name=\"view\" value=\"", html_esc($_REQUEST['view']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"refresh\" value=\"{$lang['strrefresh']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} else {
		$status = $viewActions->refreshMaterializedView($_POST['view'], isset($_POST['concurrent']));
		if ($status == 0) {
			doDefault($lang['strmaterializedviewrefreshed']);
		} else {
			doDefault($lang['strmaterializedviewrefreshedbad']);
		}
	}
}

/**
 * Sets up choices for table linkage, and which fields to select for the view we're creating
 */
function doSetParamsCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$formRenderer = new FormRenderer();
	$constraintActions = new ConstraintActions($pg);
	$schemaActions = new SchemaActions($pg);
	$tableActions = new TableActions($pg);

	// Check that they've chosen tables for the view definition
	if (!isset($_POST['formTables']))
		doWizardCreate($lang['strviewneedsdef']);
	else {
		// Initialise variables
		if (!isset($_REQUEST['formView']))
			$_REQUEST['formView'] = '';
		if (!isset($_REQUEST['formComment']))
			$_REQUEST['formComment'] = '';

		$misc->printTrail('schema');
		$misc->printTitle($lang['strcreateviewwiz'], 'pg.view.create');
		$misc->printMsg($msg);

		$tblCount = sizeof($_POST['formTables']);
		//unserialize our schema/table information and store in arrSelTables
		for ($i = 0; $i < $tblCount; $i++) {
			$arrSelTables[] = unserialize($_POST['formTables'][$i]);
		}

		$linkCount = $tblCount;

		//get linking keys
		$rsLinkKeys = $constraintActions->getLinkingKeys($arrSelTables);
		$linkCount = $rsLinkKeys->recordCount() > $tblCount ? $rsLinkKeys->recordCount() : $tblCount;

		$arrFields = []; //array that will hold all our table/field names

		//if we have schemas we need to specify the correct schema for each table we're retrieiving
		//with getTableAttributes
		$curSchema = $pg->_schema;
		for ($i = 0; $i < $tblCount; $i++) {
			if ($pg->_schema != $arrSelTables[$i]['schemaname']) {
				$schemaActions->setSchema($arrSelTables[$i]['schemaname']);
			}

			$attrs = $tableActions->getTableAttributes($arrSelTables[$i]['tablename']);
			while (!$attrs->EOF) {
				$arrFields["{$arrSelTables[$i]['schemaname']}.{$arrSelTables[$i]['tablename']}.{$attrs->fields['attname']}"] = serialize(
					[
						'schemaname' => $arrSelTables[$i]['schemaname'],
						'tablename' => $arrSelTables[$i]['tablename'],
						'fieldname' => $attrs->fields['attname']
					]
				);
				$attrs->moveNext();
			}

			$schemaActions->setSchema($curSchema);
		}
		asort($arrFields);

		?>
		<form action="views.php" method="post">
			<table>
				<tr>
					<th class="data">
						<?= $lang['strviewname'] ?>
					</th>
				</tr>
				<tr>
					<td class="data1">
						<input name="formView" value="<?= html_esc($_REQUEST['formView']) ?>" size="32"
							maxlength="<?= $pg->_maxNameLen ?>" />
					</td>
				</tr>
				<?php if ($pg->hasMatViews()): ?>
					<tr>
						<th class="data">
							<?= $lang['strviewtype'] ?>

						</th>
					</tr>
					<tr>
						<td class="data1">
							<select name="formViewType">
								<option value="view" selected="selected"><?= $lang['strnormalview'] ?></option>
								<option value="materialized_with_data"><?= $lang['strmaterializedview'] ?> -
									<?= $lang['strwithdata'] ?>
								</option>
								<option value="materialized_no_data"><?= $lang['strmaterializedview'] ?> -
									<?= $lang['strwithnodata'] ?>
								</option>
							</select>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th class="data">
						<?= $lang['strcomment'] ?>
					</th>
				</tr>
				<tr>
					<td class="data1">
						<textarea name="formComment" rows="3" cols="32"><?= html_esc($_REQUEST['formComment']) ?></textarea>
					</td>
				</tr>
			</table>

			<table>
				<tr>
					<th class="data">
						<?= $lang['strcolumns'] ?>
					</th>
				</tr>
				<tr>
					<td class="data1">
						<?= $formRenderer->printCombo($arrFields, 'formFields[]', false, '', true) ?>
					</td>
				</tr>
				<tr>
					<td>
						<input type="radio" name="dblFldMeth" id="dblFldMeth1" value="rename" />&nbsp;<label for="dblFldMeth1">
							<?= $lang['strrenamedupfields'] ?>
						</label>
						<br />
						<input type="radio" name="dblFldMeth" id="dblFldMeth2" value="drop" />&nbsp;<label for="dblFldMeth2">
							<?= $lang['strdropdupfields'] ?>
						</label>
						<br />
						<input type="radio" name="dblFldMeth" id="dblFldMeth3" value="" checked="checked" />&nbsp;<label
							for="dblFldMeth3">
							<?= $lang['strerrordupfields'] ?>
						</label>
					</td>
				</tr>
			</table>
			<br />

			<table>
				<tr>
					<th class="data">
						<?= $lang['strviewlink'] ?>
					</th>
				</tr>
				<?php
				$rowClass = 'data1';
				for ($i = 0; $i < $linkCount; $i++):
					if (!isset($formLink[$i]['operator']))
						$formLink[$i]['operator'] = 'INNER JOIN';

					if (!$rsLinkKeys->EOF) {
						$curLeftLink = html_esc(serialize(['schemaname' => $rsLinkKeys->fields['p_schema'], 'tablename' => $rsLinkKeys->fields['p_table'], 'fieldname' => $rsLinkKeys->fields['p_field']]));
						$curRightLink = html_esc(serialize(['schemaname' => $rsLinkKeys->fields['f_schema'], 'tablename' => $rsLinkKeys->fields['f_table'], 'fieldname' => $rsLinkKeys->fields['f_field']]));
						$rsLinkKeys->moveNext();
					} else {
						$curLeftLink = '';
						$curRightLink = '';
					}
					?>
					<tr>
						<td class="<?= $rowClass ?>">
							<?= $formRenderer->printCombo($arrFields, "formLink[$i][leftlink]", true, $curLeftLink, false) ?>
							<?= $formRenderer->printCombo($pg->joinOps, "formLink[$i][operator]", true, $formLink[$i]['operator']) ?>
							<?= $formRenderer->printCombo($arrFields, "formLink[$i][rightlink]", true, $curRightLink, false) ?>
						</td>
					</tr>
					<?php
					$rowClass = $rowClass == 'data1' ? 'data2' : 'data1';
				endfor;
				?>
			</table>
			<br />

			<?php
			// Build list of available operators (infix only)
			$arrOperators = [];
			foreach ($pg->selectOps as $k => $v) {
				if ($v == 'i')
					$arrOperators[$k] = $k;
			}
			?>

			<table>
				<tr>
					<th class="data">
						<?= $lang['strviewconditions'] ?>
					</th>
				</tr>
				<?php
				$rowClass = 'data1';
				for ($i = 0; $i < $linkCount; $i++):
					?>
					<tr>
						<td class="<?= $rowClass ?>">
							<?= $formRenderer->printCombo($arrFields, "formCondition[$i][field]") ?>
							<?= $formRenderer->printCombo($arrOperators, "formCondition[$i][operator]", false, false) ?>
							<input type="text" name="formCondition[<?= $i ?>][txt]" />
						</td>
					</tr>
					<?php
					$rowClass = $rowClass == 'data1' ? 'data2' : 'data1';
				endfor;
				?>
			</table>
			<p>
				<input type="hidden" name="action" value="save_create_wiz" />
				<?php foreach ($arrSelTables as $curTable): ?>
					<input type="hidden" name="formTables[]" value="<?= html_esc(serialize($curTable)) ?>" />
				<?php endforeach; ?>
				<?= $misc->form ?>
				<input type="submit" value="<?= $lang['strcreate'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</p>
		</form>
		<?php
	}
}

/**
 * Display a wizard where they can enter a new view
 */
function doWizardCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$formRenderer = new FormRenderer();

	$tables = $tableActions->getTables(true);

	$misc->printTrail('schema');
	$misc->printTitle($lang['strcreateviewwiz'], 'pg.view.create');
	$misc->printMsg($msg);

	$arrTables = [];
	while (!$tables->EOF) {
		$arrTmp = [];
		$arrTmp['schemaname'] = $tables->fields['nspname'];
		$arrTmp['tablename'] = $tables->fields['relname'];
		$arrTables[$tables->fields['nspname'] . '.' . $tables->fields['relname']] = serialize($arrTmp);
		$tables->moveNext();
	}
	?>
	<form action="views.php" method="post">
		<table>
			<tr>
				<th class="data">
					<?= $lang['strtables'] ?>
				</th>
			</tr>
			<tr>
				<td class="data1">
					<?= $formRenderer->printCombo($arrTables, 'formTables[]', false, '', true) ?>
				</td>
			</tr>
		</table>
		<p>
			<input type="hidden" name="action" value="set_params_create" />
			<?= $misc->form ?>
			<input type="submit" value="<?= $lang['strnext'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

/**
 * Displays a screen where they can enter a new view
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	if (!isset($_REQUEST['formView']))
		$_REQUEST['formView'] = '';
	if (!isset($_REQUEST['formDefinition'])) {
		if (isset($_SESSION['sqlquery']) && is_result_set_query($_SESSION['sqlquery']))
			$_REQUEST['formDefinition'] = $_SESSION['sqlquery'];
		else
			$_REQUEST['formDefinition'] = 'SELECT ';
	}
	if (!isset($_REQUEST['formComment']))
		$_REQUEST['formComment'] = '';

	$misc->printTrail('schema');
	$misc->printTitle($lang['strcreateview'], 'pg.view.create');
	$misc->printMsg($msg);

	?>
	<form action="views.php" method="post">
		<table style="width: 100%">
			<tr>

				<th class="data left required">
					<?= $lang['strname'] ?>
				</th>
				<td class="data1"><input name="formView" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_REQUEST['formView']) ?>" /></td>
			</tr>
			<?php if ($pg->hasMatViews()): ?>
				<tr>
					<th class="data left required">
						<?= $lang['strviewtype'] ?>
					</th>
					<td class="data1">
						<select name="formViewType">
							<option value="view" selected="selected"><?= $lang['strnormalview'] ?></option>
							<option value="materialized_with_data"><?= $lang['strmaterializedview'] ?> -
								<?= $lang['strwithdata'] ?>
							</option>
							<option value="materialized_no_data"><?= $lang['strmaterializedview'] ?> -
								<?= $lang['strwithnodata'] ?>
							</option>
						</select>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th class="data left required">
					<?= $lang['strdefinition'] ?>
				</th>
				<td class="data1">
					<textarea class="sql-editor frame big resizable" style="width:100%;" rows="10" cols="50"
						name="formDefinition"><?= html_esc($_REQUEST['formDefinition']) ?></textarea>
				</td>
			</tr>
			<tr>
				<th class="data left">
					<?= $lang['strcomment'] ?>
				</th>
				<td class="data1"><textarea name="formComment" rows="3"
						cols="32"><?= html_esc($_REQUEST['formComment']) ?></textarea></td>
			</tr>
		</table>
		<p><input type="hidden" name="action" value="save_create" />
			<?= $misc->form ?>
			<input type="submit" value="<?= $lang['strcreate'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

/**
 * Actually creates the new view in the database
 */
function doSaveCreate()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$viewActions = new ViewActions($pg);

	// Check that they've given a name and a definition
	if ($_POST['formView'] == '')
		doCreate($lang['strviewneedsname']);
	elseif ($_POST['formDefinition'] == '')
		doCreate($lang['strviewneedsdef']);
	else {
		// Determine view type from radio button
		$viewType = $_POST['formViewType'] ?? 'view';

		if ($viewType == 'view') {
			// Create normal view
			$status = $viewActions->createView($_POST['formView'], $_POST['formDefinition'], false, $_POST['formComment']);
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strviewcreated']);
			} else
				doCreate($lang['strviewcreatedbad']);
		} else {
			// Create materialized view
			$withData = ($viewType == 'materialized_with_data');
			$status = $viewActions->createMaterializedView($_POST['formView'], $_POST['formDefinition'], $_POST['formComment'], $withData);
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strviewcreated']);
			} else
				doCreate($lang['strviewcreatedbad']);
		}
	}
}

/**
 * Actually creates the new wizard view in the database
 */
function doSaveCreateWiz()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$viewActions = new ViewActions($pg);

	// Check that they've given a name and fields they want to select		

	if (!strlen($_POST['formView'])) {
		doSetParamsCreate($lang['strviewneedsname']);
		return;
	}
	if (!isset($_POST['formFields']) || !count($_POST['formFields'])) {
		doSetParamsCreate($lang['strviewneedsfields']);
		return;
	}
	$selFields = '';

	if (!empty($_POST['dblFldMeth']))
		$tmpHsh = [];

	foreach ($_POST['formFields'] as $curField) {
		$arrTmp = unserialize($curField);
		$pg->fieldArrayClean($arrTmp);
		if (!empty($_POST['dblFldMeth'])) { // doublon control
			if (empty($tmpHsh[$arrTmp['fieldname']])) { // field does not exist
				$selFields .= "\"{$arrTmp['schemaname']}\".\"{$arrTmp['tablename']}\".\"{$arrTmp['fieldname']}\", ";
				$tmpHsh[$arrTmp['fieldname']] = 1;
			} else if ($_POST['dblFldMeth'] == 'rename') { // field exist and must be renamed
				$tmpHsh[$arrTmp['fieldname']]++;
				$selFields .= "\"{$arrTmp['schemaname']}\".\"{$arrTmp['tablename']}\".\"{$arrTmp['fieldname']}\" AS \"{$arrTmp['schemaname']}_{$arrTmp['tablename']}_{$arrTmp['fieldname']}{$tmpHsh[$arrTmp['fieldname']]}\", ";
			}
			// field already exist, just ignore this one
		} else {
			// no doublon control
			$selFields .= "\"{$arrTmp['schemaname']}\".\"{$arrTmp['tablename']}\".\"{$arrTmp['fieldname']}\", ";
		}
	}

	$selFields = substr($selFields, 0, -2);
	unset($arrTmp, $tmpHsh);
	$linkFields = '';

	// If we have links, out put the JOIN ... ON statements
	if (is_array($_POST['formLink'])) {
		// Filter out invalid/blank entries for our links
		$arrLinks = [];
		foreach ($_POST['formLink'] as $curLink) {
			if (strlen($curLink['leftlink']) && strlen($curLink['rightlink']) && strlen($curLink['operator'])) {
				$arrLinks[] = $curLink;
			}
		}
		// We must perform some magic to make sure that we have a valid join order
		$count = sizeof($arrLinks);
		$arrJoined = [];
		$arrUsedTbls = [];

		$processLink = function ($curLink) use (&$arrJoined, &$arrUsedTbls, &$linkFields, $pg, $count) {
			$arrLeftLink = unserialize($curLink['leftlink']);
			$arrRightLink = unserialize($curLink['rightlink']);
			$pg->fieldArrayClean($arrLeftLink);
			$pg->fieldArrayClean($arrRightLink);

			$tbl1 = "\"{$arrLeftLink['schemaname']}\".\"{$arrLeftLink['tablename']}\"";
			$tbl2 = "\"{$arrRightLink['schemaname']}\".\"{$arrRightLink['tablename']}\"";

			// If we already have joined tables and this link is irrelevant, skip it early
			if ($count > 0 && (in_array($curLink, $arrJoined) || !in_array($tbl1, $arrUsedTbls))) {
				return;
			}

			// Make sure for multi-column foreign keys that we use a table alias tables joined to more than once
			// This can (and should be) more optimized for multi-column foreign keys
			$adj_tbl2 = in_array($tbl2, $arrUsedTbls) ? "$tbl2 AS alias_ppa_" . time() : $tbl2;

			if (strlen($linkFields)) {
				$linkFields .= "{$curLink['operator']} $adj_tbl2 ON (\"{$arrLeftLink['schemaname']}\".\"{$arrLeftLink['tablename']}\".\"{$arrLeftLink['fieldname']}\" = \"{$arrRightLink['schemaname']}\".\"{$arrRightLink['tablename']}\".\"{$arrRightLink['fieldname']}\") ";
			} else {
				$linkFields = "$tbl1 {$curLink['operator']} $adj_tbl2 ON (\"{$arrLeftLink['schemaname']}\".\"{$arrLeftLink['tablename']}\".\"{$arrLeftLink['fieldname']}\" = \"{$arrRightLink['schemaname']}\".\"{$arrRightLink['tablename']}\".\"{$arrRightLink['fieldname']}\") ";
			}

			$arrJoined[] = $curLink;
			if (!in_array($tbl1, $arrUsedTbls))
				$arrUsedTbls[] = $tbl1;
			if (!in_array($tbl2, $arrUsedTbls))
				$arrUsedTbls[] = $tbl2;
		};

		// If we have at least one join condition, output it
		if ($count > 0) {
			for ($j = 0; $j < $count; $j++) {
				foreach ($arrLinks as $curLink) {
					$processLink($curLink);
				}
			}
		}
	}

	//if linkfields has no length then either _POST['formLink'] was not set, or there were no join conditions 
	//just select from all selected tables - a cartesian join do a
	if (!strlen($linkFields)) {
		foreach ($_POST['formTables'] as $curTable) {
			$arrTmp = unserialize($curTable);
			$pg->fieldArrayClean($arrTmp);
			$linkFields .= strlen($linkFields) ? ", \"{$arrTmp['schemaname']}\".\"{$arrTmp['tablename']}\"" : "\"{$arrTmp['schemaname']}\".\"{$arrTmp['tablename']}\"";
		}
	}

	$addConditions = '';
	if (is_array($_POST['formCondition'])) {
		foreach ($_POST['formCondition'] as $curCondition) {
			if (strlen($curCondition['field']) && strlen($curCondition['txt'])) {
				$arrTmp = unserialize($curCondition['field']);
				$pg->fieldArrayClean($arrTmp);
				$addConditions .= strlen($addConditions) ? " AND \"{$arrTmp['schemaname']}\".\"{$arrTmp['tablename']}\".\"{$arrTmp['fieldname']}\" {$curCondition['operator']} '{$curCondition['txt']}' "
					: " \"{$arrTmp['schemaname']}\".\"{$arrTmp['tablename']}\".\"{$arrTmp['fieldname']}\" {$curCondition['operator']} '{$curCondition['txt']}' ";
			}
		}
	}

	$viewQuery = "SELECT $selFields FROM $linkFields ";

	//add where from additional conditions
	if (strlen($addConditions))
		$viewQuery .= ' WHERE ' . $addConditions;

	// Determine view type from radio button
	$viewType = $_POST['formViewType'] ?? 'view';

	if ($viewType == 'view') {
		// Create normal view
		$status = $viewActions->createView($_POST['formView'], $viewQuery, false, $_POST['formComment']);
	} else {
		// Create materialized view
		$withData = ($viewType == 'materialized_with_data');
		$status = $viewActions->createMaterializedView($_POST['formView'], $viewQuery, $_POST['formComment'], $withData);
	}

	if ($status == 0) {
		AppContainer::setShouldReloadTree(true);
		doDefault($lang['strviewcreated']);
	} else
		doSetParamsCreate($lang['strviewcreatedbad']);
}

/**
 * Show default list of views in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$conf = AppContainer::getConf();
	$lang = AppContainer::getLang();
	$viewActions = new ViewActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'views');
	$misc->printMsg($msg);

	// Get both normal and materialized views
	$views = $viewActions->getViews(true, true);

	$columns = [
		'view' => [
			'title' => $lang['strview'],
			'field' => field('relname'),
			'url' => "redirect.php?subject=view&amp;{$misc->href}&amp;",
			'vars' => ['view' => 'relname'],
			'icon' => callback(function ($row) use ($misc) {
				return ($row['relkind'] == 'm') ? $misc->icon('MaterializedView') : $misc->icon('View');
			}),
			'class' => 'nowrap',
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('relowner'),
		],
		'type' => [
			'title' => $lang['strtype'],
			'field' => callback(function ($row) use ($lang) {
				return ($row['relkind'] == 'm') ? $lang['strmaterializedview'] : $lang['strview'];
			}),
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
		'view' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strviews']}",
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 2,
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
			'keycols' => ['view' => 'relname'],
			'url' => 'views.php',
		],
		'browse' => [
			'icon' => $misc->icon('Table'),
			'content' => $lang['strbrowse'],
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'action' => 'confselectrows',
						'subject' => 'view',
						'return' => 'schema',
						'view' => field('relname')
					]
				]
			]
		],
		'select' => [
			'icon' => $misc->icon('Search'),
			'content' => $lang['strselect'],
			'attr' => [
				'href' => [
					'url' => 'views.php',
					'urlvars' => [
						'action' => 'confselectrows',
						'view' => field('relname')
					]
				]
			]
		],

		// Insert is possible if the relevant rule for the view has been created.
		//			'insert' => array(
		//				'title'	=> $lang['strinsert'],
		//				'url'	=> "views.php?action=confinsertrow&amp;{$misc->href}&amp;",
		//				'vars'	=> array('view' => 'relname'),
		//			),

		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'viewproperties.php',
					'urlvars' => [
						'action' => 'confirm_alter',
						'view' => field('relname')
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
					'url' => 'views.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'view' => field('relname')
					]
				]
			]
		],
		'refresh' => [
			'icon' => $misc->icon('Refresh'),
			'content' => $lang['strrefresh'],
			'attr' => [
				'href' => [
					'url' => 'views.php',
					'urlvars' => [
						'action' => 'confirm_refresh',
						'view' => field('relname')
					]
				]
			],
			'disable' => callback(function ($row) {
				// Only show refresh for materialized views
				return $row['relkind'] != 'm';
			}),
		],
	];

	$isCatalog = $misc->isCatalogSchema();
	if ($isCatalog) {
		$actions = array_intersect_key(
			$actions,
			array_flip(['browse', 'select'])
		);
	}

	$misc->printTable(
		$views,
		$columns,
		$actions,
		'views-views',
		$lang['strnoviews'],
		null,
		$footer
	);

	$navlinks = [
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'views.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateView'),
			'content' => $lang['strcreateview']
		],
		'createwiz' => [
			'attr' => [
				'href' => [
					'url' => 'views.php',
					'urlvars' => [
						'action' => 'wiz_create',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateViewWizard'),
			'content' => $lang['strcreateviewwiz']
		]
	];
	if (!$isCatalog) {
		$misc->printNavLinks($navlinks, 'views-views', get_defined_vars());
	}
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$viewActions = new ViewActions($pg);

	// Get both normal and materialized views
	$views = $viewActions->getViews(true, true);

	$reqvars = $misc->getRequestVars('view');

	$attrs = [
		'text' => field('relname'),
		'icon' => callback(function ($row) {
			return ($row['relkind'] == 'm') ? 'MaterializedView' : 'View';
		}),
		'iconAction' => url('display.php', $reqvars, ['view' => field('relname')]),
		'toolTip' => field('relcomment'),
		'action' => url('redirect.php', $reqvars, ['view' => field('relname')]),
		'branch' => url(
			'views.php',
			$reqvars,
			[
				'action' => 'subtree',
				'view' => field('relname')
			]
		)
	];

	$misc->printTree($views, $attrs, 'views');
	exit;
}

function doSubTree()
{
	$misc = AppContainer::getMisc();

	$tabs = $misc->getNavTabs('view');
	$items = $misc->adjustTabsForTree($tabs);
	$reqvars = $misc->getRequestVars('view');

	$attrs = [
		'text' => field('title'),
		'icon' => field('icon'),
		'action' => url(field('url'), $reqvars, field('urlvars'), ['view' => $_REQUEST['view']]),
		'branch' => ifempty(
			field('branch'),
			'',
			url(
				field('url'),
				field('urlvars'),
				$reqvars,
				[
					'action' => 'tree',
					'view' => $_REQUEST['view']
				]
			)
		),
	];

	$misc->printTree($items, $attrs, 'view');
	exit;
}

$action = $_REQUEST['action'] ?? '';
if (!isset($msg))
	$msg = '';

if ($action == 'tree')
	doTree();
if ($action == 'subtree')
	dosubTree();

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$misc->printHeader($lang['strviews']);
$misc->printBody();

switch ($action) {
	case 'selectrows':
		if (isset($_REQUEST['cancel']))
			doDefault();
		else
			doSelectRows(false);
		break;
	case 'confselectrows':
		doSelectRows(true);
		break;
	case 'save_create_wiz':
		if (isset($_REQUEST['cancel']))
			doDefault();
		else
			doSaveCreateWiz();
		break;
	case 'wiz_create':
		doWizardCreate();
		break;
	case 'set_params_create':
		if (isset($_REQUEST['cancel']))
			doDefault();
		else
			doSetParamsCreate();
		break;
	case 'save_create':
		if (isset($_REQUEST['cancel']))
			doDefault();
		else
			doSaveCreate();
		break;
	case 'create':
		doCreate();
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
	case 'refresh':
		if (isset($_POST['refresh']))
			doRefresh(false);
		else
			doDefault();
		break;
	case 'confirm_refresh':
		doRefresh(true);
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
