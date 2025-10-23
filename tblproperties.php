<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Gui\ColumnFormRenderer;
use PhpPgAdmin\Gui\ImportFormRenderer;
use PhpPgAdmin\Gui\QueryExportRenderer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ColumnActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\TablespaceActions;

/**
 * List tables in a database
 *
 * $Id: tblproperties.php,v 1.92 2008/01/19 13:46:15 ioguix Exp $
 */

// Include application functions
include_once './libraries/bootstrap.php';

/**
 * Function to save after altering a table
 */
function doSaveAlter()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$misc = AppContainer::getMisc();
	$tableActions = new TableActions($pg);

	// For databases that don't allow owner change
	if (!isset($_POST['owner'])) {
		$_POST['owner'] = '';
	}

	// Default tablespace to null if it isn't set
	if (!isset($_POST['tablespace'])) {
		$_POST['tablespace'] = null;
	}

	if (!isset($_POST['newschema'])) {
		$_POST['newschema'] = null;
	}

	$status = $tableActions->alterTable(
		$_POST['table'],
		$_POST['name'],
		$_POST['owner'],
		$_POST['newschema'],
		$_POST['comment'],
		$_POST['tablespace']
	);

	if ($status != 0) {
		doAlter($lang['strtablealteredbad']);
		return;
	}

	// If table has been renamed, need to change to the new name and
	// reload the browser frame.
	if ($_POST['table'] != $_POST['name']) {
		// Jump them to the new table name
		$_REQUEST['table'] = $_POST['name'];
		// Force a browser reload
		AppContainer::setShouldReloadTree(true);
	}
	// If schema has changed, need to change to the new schema and reload the browser
	if (!empty($_POST['newschema']) && ($_POST['newschema'] != $pg->_schema)) {
		// Jump them to the new sequence schema
		$misc->setCurrentSchema($_POST['newschema']);
		AppContainer::setShouldReloadTree(true);
	}
	doDefault($lang['strtablealtered']);

}

/**
 * Function to allow altering of a table
 */
function doAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);
	$tableActions = new TableActions($pg);
	$schemaActions = new SchemaActions($pg);
	$tablespaceActions = new TablespaceActions($pg);

	$misc->printTrail('table');
	$misc->printTitle($lang['stralter'], 'pg.table.alter');
	$misc->printMsg($msg);

	// Fetch table info
	$table = $tableActions->getTable($_REQUEST['table']);
	// Fetch all users
	$users = $roleActions->getUsers();
	// Fetch all tablespaces from the database
	if ($pg->hasTablespaces()) {
		$tablespaces = $tablespaceActions->getTablespaces(true);
	}

	if ($table->recordCount() == 0) {
		echo "<p class=\"empty\">{$lang['strnodata']}</p>\n";
		return;
	}

	if (!isset($_POST['name'])) {
		$_POST['name'] = $table->fields['relname'];
	}

	if (!isset($_POST['owner'])) {
		$_POST['owner'] = $table->fields['relowner'];
	}

	if (!isset($_POST['newschema'])) {
		$_POST['newschema'] = $table->fields['nspname'];
	}

	if (!isset($_POST['comment'])) {
		$_POST['comment'] = $table->fields['relcomment'];
	}

	if ($pg->hasTablespaces() && !isset($_POST['tablespace'])) {
		$_POST['tablespace'] = $table->fields['tablespace'];
	}

	?>
	<form action="tblproperties.php" method="post">
		<table>
			<tr>
				<th class="data left required">
					<?= $lang['strname'] ?>
				</th>
				<td class="data1">
					<input name="name" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_POST['name'], ENT_QUOTES) ?>" />
				</td>
			</tr>

			<?php if ($roleActions->isSuperUser()): ?>
				<tr>
					<th class="data left required">
						<?= $lang['strowner'] ?>
					</th>
					<td class="data1"><select name="owner">
							<?php while (!$users->EOF):
								$uname = $users->fields['usename'];
								$selectAtr = ($uname == $_POST['owner']) ? ' selected="selected"' : ''; ?>
								<option value="<?= html_esc($uname) ?>" <?= $selectAtr ?>>
									<?= html_esc($uname) ?>
								</option>
								<?php $users->moveNext(); endwhile; ?>
						</select></td>
				</tr>
			<?php endif; ?>

			<?php if ($pg->hasAlterTableSchema()): ?>
				<?php $schemas = $schemaActions->getSchemas(); ?>
				<tr>
					<th class="data left required">
						<?= $lang['strschema'] ?>
					</th>
					<td class="data1"><select name="newschema">
							<?php while (!$schemas->EOF):
								$schema = $schemas->fields['nspname'];
								$selectAtr = ($schema == $_POST['newschema']) ? ' selected="selected"' : ''; ?>
								<option value="<?= html_esc($schema) ?>" <?= $selectAtr ?>>
									<?= html_esc($schema) ?>
								</option>
								<?php $schemas->moveNext(); endwhile; ?>
						</select></td>
				</tr>
			<?php endif; ?>

			<?php if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0): ?>
				<tr>
					<th class="data left">
						<?= $lang['strtablespace'] ?>
					</th>
					<td class="data1">
						<select name="tablespace">
							<option value="" <?= ($_POST['tablespace'] == '') ? ' selected="selected"' : '' ?>></option>
							<?php while (!$tablespaces->EOF):
								$spcname = html_esc($tablespaces->fields['spcname']);
								$selectAtr = ($spcname == $_POST['tablespace']) ? ' selected="selected"' : ''; ?>
								<option value="<?= $spcname ?>" <?= $selectAtr ?>>
									<?= $spcname ?>
								</option>
								<?php $tablespaces->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<tr>
				<th class="data left">
					<?= $lang['strcomment'] ?>
				</th>
				<td class="data1">
					<textarea rows="3" cols="32" name="comment"><?= html_esc($_POST['comment'] ?? '') ?></textarea>
				</td>
			</tr>
		</table>
		<p><input type="hidden" name="action" value="alter" />
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<?= $misc->form ?>
			<input type="submit" name="alter" value="<?= $lang['stralter'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

function doExport($msg = '')
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();

	$misc->printTrail('table');
	$misc->printTabs('table', 'export');
	$misc->printMsg($msg);

	$schema = $pg->escapeIdentifier($_REQUEST['schema']);
	$table = $pg->escapeIdentifier($_REQUEST['table']);
	$query = "SELECT * FROM {$schema}.{$table}";
	$queryExportRenderer = new QueryExportRenderer();
	$queryExportRenderer->renderExportForm($query, [
		'subject' => 'table',
		'table' => $_REQUEST['table'],
	]);
}

function doImport($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$tableActions = new TableActions($pg);
	$rs = $tableActions->getTable($_REQUEST['table']);
	if ($rs->recordCount() == 0 || $rs->fields['relkind'] != 'r') {
		doDefault($lang['strnotable']);
		return;
	}

	$schemaActions = new SchemaActions($pg);
	if ($schemaActions->isSystemSchema($pg->_schema)) {
		$_SESSION['webdbLastTab']['table'] = '';
		doDefault($lang['strsystemobjectdenied']);
		return;
	}

	$misc->printTrail('table');
	$misc->printTabs('table', 'import');
	$misc->printMsg($msg);

	$renderer = new ImportFormRenderer();
	$renderer->renderDataImportForm('table', [
		'table' => $_REQUEST['table'],
	]);
}

function doAddColumn($msg = '')
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$misc = AppContainer::getMisc();
	$renderer = new ColumnFormRenderer();

	// Determine number of rows to display
	if (!isset($_POST['num_columns'])) {
		$numColumns = 10;
	} else {
		$numColumns = (int) $_POST['num_columns'];
	}

	// Prepare empty columns array for rendering
	$columns = array_fill(0, $numColumns, [
		'attname' => '',
		'base_type' => '',
		'length' => '',
		'attnotnull' => false,
		'adsrc' => '',
		'comment' => '',
		'default_preset' => '',
	]);

	$misc->printTrail('table');
	$misc->printTitle($lang['straddcolumn'], 'pg.column.add');
	$misc->printMsg($msg);

	?>
	<form action="tblproperties.php" method="post">
		<?= $renderer->renderTable($columns, $_POST) ?>
		<div class="flex-row my-3">
			<input type="hidden" name="action" value="save_add_column" />
			<input type="hidden" name="num_columns" id="num_columns" value="<?= $numColumns ?>" />
			<?= $misc->form ?>
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<div>
				<input type="submit" name="add" value="<?= $lang['strsave'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</div>
			<div class="ml-auto">
				<input type="button" value="<?= $lang['straddmorecolumns'] ?>" onclick="addColumnRow();" />
			</div>
		</div>
	</form>
	<?= $renderer->renderJavaScriptInit($numColumns) ?>
	<?php
}

function doSaveAddColumn()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$columnActions = new ColumnActions($pg);

	// Determine number of columns
	$numColumns = isset($_POST['num_columns']) ? (int) $_POST['num_columns'] : 1;

	// Collect valid columns (non-empty field names)
	$validColumns = [];
	for ($i = 0; $i < $numColumns; $i++) {
		if (isset($_POST['field'][$i]) && trim($_POST['field'][$i]) != '') {
			// Check if this is a generated column
			$isGenerated = isset($_POST['is_generated'][$i]);

			// Determine the actual default value or generation expression
			$defaultValue = '';
			if ($isGenerated) {
				// For generated columns, get expression from generated_expr field
				$defaultValue = isset($_POST['generated_expr'][$i]) ? $_POST['generated_expr'][$i] : '';
			} else {
				// For regular columns, get default from default_preset/default fields
				if (isset($_POST['default_preset'][$i])) {
					$preset = $_POST['default_preset'][$i];
					if ($preset === 'custom') {
						$defaultValue = isset($_POST['default'][$i]) ? $_POST['default'][$i] : '';
					} elseif ($preset !== '') {
						$defaultValue = $preset;
					}
				} elseif (isset($_POST['default'][$i])) {
					$defaultValue = $_POST['default'][$i];
				}
			}

			$validColumns[] = [
				'index' => $i,
				'field' => trim($_POST['field'][$i]),
				'type' => isset($_POST['type'][$i]) ? $_POST['type'][$i] : '',
				'array' => $_POST['array'][$i] ?? '',
				'length' => isset($_POST['length'][$i]) ? $_POST['length'][$i] : '',
				'notnull' => isset($_POST['notnull'][$i]),
				'default' => $defaultValue,
				'comment' => isset($_POST['comment'][$i]) ? $_POST['comment'][$i] : '',
				'is_generated' => $isGenerated
			];
		}
	}

	// Check if at least one column is provided
	if (empty($validColumns)) {
		doAddColumn($lang['strcolneedsname']);
		return;
	}

	// Begin transaction
	$status = $pg->beginTransaction();
	if ($status != 0) {
		doAddColumn($lang['strcolumnaddedbad']);
		return;
	}

	// Add each column
	$addedCount = 0;
	$failedColumn = null;
	$errorMsg = '';

	foreach ($validColumns as $col) {
		$status = $columnActions->addColumn(
			$_POST['table'],
			$col['field'],
			$col['type'],
			$col['array'],
			$col['length'],
			$col['notnull'],
			$col['default'],
			$col['comment'],
			$col['is_generated']
		);

		if ($status != 0) {
			// Rollback transaction on error
			$pg->rollbackTransaction();
			$failedColumn = $col;
			$errorMsg = sprintf(
				$lang['strcolumnaddedbad'] . ' - Row %d (%s): %s',
				$col['index'] + 1,
				html_esc($col['field']),
				$lang['strcolumnaddedbad']
			);
			// Redisplay form with error
			doAddColumn($errorMsg);
			return;
		}
		$addedCount++;
	}

	// Commit transaction
	$status = $pg->endTransaction();
	if ($status != 0) {
		doAddColumn($lang['strcolumnaddedbad']);
		return;
	}

	// Success
	AppContainer::setShouldReloadTree(true);
	$msg = format_string(
		$lang['strcolumnsadded'],
		[
			'count' => $addedCount
		]
	);
	doDefault($msg);
}

/**
 * Show confirmation of drop column and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$columnActions = new ColumnActions($pg);

	if ($confirm) {
		$misc->printTrail('column');
		$misc->printTitle($lang['strdrop'], 'pg.column.drop');
		?>
		<p>
			<?= sprintf($lang['strconfdropcolumn'], $misc->formatVal($_REQUEST['column']), $misc->formatVal($_REQUEST['table'])) ?>
		</p>
		<form action="tblproperties.php" method="post">
			<input type="hidden" name="action" value="drop" />
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<input type="hidden" name="column" value="<?= html_esc($_REQUEST['column']) ?>" />
			<?= $misc->form ?>
			<p><input type="checkbox" id="cascade" name="cascade"> <label for="cascade">
					<?= $lang['strcascade'] ?>
				</label></p>
			<input type="submit" name="drop" value="<?= $lang['strdrop'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</form>
		<?php
	} else {
		$status = $columnActions->dropColumn($_POST['table'], $_POST['column'], isset($_POST['cascade']));
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strcolumndropped']);
		} else {
			doDefault($lang['strcolumndroppedbad']);
		}

	}
}

/**
 * Show default list of columns in the table
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$constraintActions = new ConstraintActions($pg);

	$attPre = function ($rowdata, $actions) {
		$pg = AppContainer::getPostgres();
		$misc = AppContainer::getMisc();
		$lang = AppContainer::getLang();
		$rowdata->fields['+type'] = $pg->formatType($rowdata->fields['type'], $rowdata->fields['atttypmod']);
		$attname = $rowdata->fields['attname'];
		$table = $_REQUEST['table'];
		$pg->fieldClean($attname);
		$pg->fieldClean($table);

		// Pre-compute formatted default/generated value for display
		$adsrc = $rowdata->fields['adsrc'] ?? null;
		$attgenerated = $rowdata->fields['attgenerated'] ?? '';
		if ($adsrc !== null && $adsrc !== '') {
			$escaped = htmlspecialchars($adsrc);
			if ($attgenerated === 's') {
				// This is a generated column
				$rowdata->fields['+default'] = '<span class="generated-column" title="' . htmlspecialchars($lang['strgeneratedcolumn']) . '">'
					. '<em>GENERATED:</em> '
					. '<span class="sql-viewer">' . $escaped . '</span>'
					. '</span>';
			} else {
				// Regular default value
				$rowdata->fields['+default'] = '<span class="sql-viewer">' . $escaped . '</span>';
			}
		} else {
			$rowdata->fields['+default'] = '';
		}

		$sequenceName = $rowdata->fields['sequence_name'] ?? '';
		$sequenceSchema = $rowdata->fields['sequence_schema'] ?? $_REQUEST['schema'];
		$rowdata->fields['+sequence'] = null;
		if ($sequenceName !== '') {
			$rowdata->fields['+sequence'] = !empty($sequenceSchema)
				? "{$sequenceSchema}.{$sequenceName}"
				: $sequenceName;

			$actions['sequence'] = [
				'icon' => $misc->icon('Sequence'),
				'content' => $lang['strsequence'],
				'attr' => [
					'href' => [
						'url' => 'sequences.php',
						'urlvars' => [
							'subject' => 'sequence',
							'action' => 'properties',
							'schema' => $sequenceSchema,
							'sequence' => $sequenceName,
						],
					],
				],
			];
		}

		$actions['browse']['attr']['href']['urlvars']['query'] =
			"SELECT \"{$attname}\", count(*) AS \"count\"
				FROM \"{$table}\" GROUP BY \"{$attname}\" ORDER BY \"{$attname}\"";

		return $actions;
	};

	$cstrRender = function ($s, $p) {
		$misc = AppContainer::getMisc();
		$pg = AppContainer::getPostgres();
		$tableActions = new TableActions($pg);

		$str = '';
		foreach ($p['keys'] as $k => $c) {

			if (is_null($p['keys'][$k]['consrc'])) {
				$atts = $tableActions->getAttributeNames($_REQUEST['table'], explode(' ', $p['keys'][$k]['indkey']));
				$c['consrc'] = ($c['contype'] == 'u' ? "UNIQUE (" : "PRIMARY KEY (") . join(',', $atts) . ')';
			}

			if ($c['p_field'] != $s) {
				continue;
			}

			switch ($c['contype']) {
				case 'p':
					$str .= '<a href="constraints.php?' . $misc->href . "&amp;table=" . urlencode($c['p_table']) . "&amp;schema=" . urlencode($c['p_schema']) . "\"><img src=\"" .
						$misc->icon('PrimaryKey') . '" alt="[pk]" title="' . htmlentities($c['consrc'], ENT_QUOTES, 'UTF-8') . '" /></a>';
					break;
				case 'f':
					$str .= '<a href="tblproperties.php?' . $misc->href . "&amp;table=" . urlencode($c['f_table']) . "&amp;schema=" . urlencode($c['f_schema']) . "\"><img src=\"" .
						$misc->icon('ForeignKey') . '" alt="[fk]" title="' . htmlentities($c['consrc'], ENT_QUOTES, 'UTF-8') . '" /></a>';
					break;
				case 'u':
					$str .= '<a href="constraints.php?' . $misc->href . "&amp;table=" . urlencode($c['p_table']) . "&amp;schema=" . urlencode($c['p_schema']) . "\"><img src=\"" .
						$misc->icon('UniqueConstraint') . '" alt="[uniq]" title="' . htmlentities($c['consrc'], ENT_QUOTES, 'UTF-8') . '" /></a>';
					break;
				case 'c':
					$str .= '<a href="constraints.php?' . $misc->href . "&amp;table=" . urlencode($c['p_table']) . "&amp;schema=" . urlencode($c['p_schema']) . "\"><img src=\"" .
						$misc->icon('CheckConstraint') . '" alt="[check]" title="' . htmlentities($c['consrc'], ENT_QUOTES, 'UTF-8') . '" /></a>';
			}

		}

		return $str;
	};

	$misc->printTrail('table');
	$misc->printTabs('table', 'columns');
	$misc->printMsg($msg);

	// Get table
	$tdata = $tableActions->getTable($_REQUEST['table']);

	// Check if this is a partition or partitioned table (PG 10+)
	$is_partition = ($tdata->fields['relispartition'] ?? '') === 't';
	$is_partitioned = ($tdata->fields['relkind'] ?? 'r') === 'p';
	$parent_table = $tdata->fields['parent_table'] ?? null;

	// Display partition information
	if ($is_partition) {
		echo "<div class=\"info text-center my-2\">\n";
		echo "<div>\n";
		echo "{$lang['strpartitionof']}: ";
		echo "<a href=\"tblproperties.php?{$misc->href}&amp;table=";
		echo urlencode($parent_table) . "\">";
		$misc->printIcon('Table');
		echo '&nbsp;', htmlspecialchars($parent_table) . "</a>";
		echo "</div>\n";

		if ($tdata->fields['partition_bound']) {
			echo "<div>\n";
			echo " <span class=\"sql-viewer\">";
			echo htmlspecialchars($tdata->fields['partition_bound']);
			echo "</span>";
			echo "</div>\n";
		}

		// Link to view all partitions
		echo "<a href=\"partitions.php?{$misc->href}&amp;table=";
		echo urlencode($parent_table) . "\">";
		$misc->printIcon('Partitions');
		echo '&nbsp;', $lang['strviewallpartitions'] . "</a>";
		echo "</div>\n";
	}

	// Display partitioned table information
	if ($is_partitioned) {
		// Map strategy code to readable name
		$strategy = PartitionActions::PARTITION_STRATEGY_MAP[$tdata->fields['partstrat']] ?? $tdata->fields['partstrat'];

		// Format partition keys array
		$partition_keys = trim($tdata->fields['partition_keys'], '{}');

		echo "<div class=\"info text-center my-2\">\n";
		echo "<div>\n";
		echo "{$lang['strpartitionedby']}: ";
		echo "<span class=\"sql-viewer\">";
		echo htmlspecialchars($strategy);
		echo " ON (";
		echo htmlspecialchars($partition_keys);
		echo ")";
		echo "</span>";
		echo "</div>\n";

		// Link to manage partitions
		echo "<a href=\"partitions.php?{$misc->href}&amp;table=";
		echo urlencode($_REQUEST['table']) . "\">";
		$misc->printIcon('Partitions', $lang['strpartitions']);
		echo " {$lang['strmanagepartitions']}</a>";
		echo "</div>\n";
	}

	// Get columns
	$attrs = $tableActions->getTableAttributes($_REQUEST['table']);
	// Get constraints keys
	$ck = $constraintActions->getConstraintsWithFields($_REQUEST['table']);

	// Show comment if any
	if ($tdata->fields['relcomment'] !== null) {
		?>
		<p class="comment">
			<?= $misc->formatVal($tdata->fields['relcomment']) ?>
		</p>
		<?php
	}

	$columns = [
		'column' => [
			'title' => $lang['strcolumn'],
			'field' => field('attname'),
			'url' => "colproperties.php?subject=column&amp;{$misc->href}&amp;table=" . urlencode($_REQUEST['table']) . "&amp;",
			'vars' => ['column' => 'attname'],
			'icon' => $misc->icon('Column'),
			'class' => 'no-wrap',
		],
		'type' => [
			'title' => $lang['strtype'],
			'field' => field('+type'),
		],
		'notnull' => [
			'title' => $lang['strnotnull'],
			'field' => field('attnotnull'),
			'type' => 'bool',
			'params' => ['true' => 'NOT NULL', 'false' => ''],
		],
		'default' => [
			'title' => $lang['strdefault'],
			'field' => field('+default'),
			'type' => 'verbatim',
		],
		/*
		'sequence' => [
			'title' => $lang['strsequence'],
			'field' => field('+sequence'),
			'url' => "sequences.php?action=properties&amp;{$misc->href}&amp;",
			'vars' => [
				'schema' => 'sequence_schema',
				'sequence' => 'sequence_name',
			],
			'icon' => 'Sequence',
			'class' => 'no-wrap',
		],
		*/
		'keyprop' => [
			'title' => $lang['strconstraints'],
			'class' => 'constraint_cell',
			'field' => field('attname'),
			'type' => 'callback',
			'params' => [
				'function' => $cstrRender,
				'keys' => $ck->getArray(),
			],
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('comment'),
		],
	];

	$actions = [
		'multiactions' => [
			'keycols' => ['column' => 'attname'],
			'url' => 'tblproperties.php',
			'vars' => [
				'subject' => 'column',
				'table' => $_REQUEST['table'],
			],
		],
		'browse' => [
			'schema' => field('sequence_schema'),
			'sequence' => field('sequence_name'),
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'table' => $_REQUEST['table'],
						'subject' => 'column',
						'return' => 'table',
						'column' => field('attname'),
					],
				],
			],
		],
		'alter' => [
			'multiaction' => 'edit_columns',
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit'],
			'attr' => [
				'href' => [
					'url' => 'colproperties.php',
					'urlvars' => [
						'subject' => 'column',
						'action' => 'properties',
						'table' => $_REQUEST['table'],
						'column' => field('attname'),
					],
				],
			],
		],
		'privileges' => [
			'icon' => $misc->icon('Privileges'),
			'content' => $lang['strprivileges'],
			'attr' => [
				'href' => [
					'url' => 'privileges.php',
					'urlvars' => [
						'subject' => 'column',
						'table' => $_REQUEST['table'],
						'column' => field('attname'),
					],
				],
			],
		],
		'drop' => [
			'multiaction' => 'confirm_drop_columns',
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'tblproperties.php',
					'urlvars' => [
						'subject' => 'column',
						'action' => 'confirm_drop',
						'table' => $_REQUEST['table'],
						'column' => field('attname'),
					],
				],
			],
		],
	];

	if ($is_partition) {
		unset($actions['multiactions'], $actions['alter'], $actions['drop']);
	}

	$schemaActions = new SchemaActions($pg);
	$isSystemSchema = $schemaActions->isSystemSchema($_REQUEST['schema']);
	if ($isSystemSchema) {
		$actions = [];
		unset($columns['actions']);
	}

	$misc->printTable($attrs, $columns, $actions, 'tblproperties-tblproperties', null, $attPre);

	$navlinks = [
		'browse' => [
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
						'subject' => 'table',
						'return' => 'table',
					],
				],
			],
			'icon' => $misc->icon('Table'),
			'content' => $lang['strbrowse'],
		],
		'select' => [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confselectrows',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Search'),
			'content' => $lang['strselect'],
		],
		'insert' => [
			'attr' => [
				'href' => [
					'url' => 'display.php',
					'urlvars' => [
						'action' => 'confinsertrow',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
						'subject' => 'table',
					],
				],
			],
			'icon' => $misc->icon('Add'),
			'content' => $lang['strinsert'],
		],
		'empty' => [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_empty',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Shredder'),
			'content' => $lang['strempty'],
		],
		'drop' => [
			'attr' => [
				'href' => [
					'url' => 'tables.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
		],
		'addcolumn' => [
			'attr' => [
				'href' => [
					'url' => 'tblproperties.php',
					'urlvars' => [
						'action' => 'add_column',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('AddColumn'),
			'content' => $lang['straddcolumn'],
		],
		'alter' => [
			'attr' => [
				'href' => [
					'url' => 'tblproperties.php',
					'urlvars' => [
						'action' => 'confirm_alter',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit'],
		],
	];

	if ($is_partition) {
		$navlinks = array_intersect_key(
			$navlinks,
			array_flip(['browse', 'select', 'insert'])
		);
	}

	if ($is_partition || $is_partitioned) {
		$navlinks['partitions'] = [
			'attr' => [
				'href' => [
					'url' => 'partitions.php',
					'urlvars' => [
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'table' => $is_partition ? $parent_table : $_REQUEST['table'],
					],
				],
			],
			'icon' => $misc->icon('Partitions'),
			'content' => $lang['strpartitions'],
		];
	}

	if ($isSystemSchema) {
		$navlinks = array_intersect_key(
			$navlinks,
			array_flip(['browse', 'select'])
		);
	}

	$misc->printNavLinks(
		$navlinks,
		'tblproperties-tblproperties',
		get_defined_vars()
	);
}

/**
 * Edit multiple columns at once
 */
function doEditColumns($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$columnActions = new ColumnActions($pg);
	$renderer = new ColumnFormRenderer();

	if ($confirm) {

		// Get selected columns from multi-action
		$selectedColumns = [];
		if (isset($_REQUEST['ma'])) {
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				$selectedColumns[] = $a['column'];
			}
		} elseif (isset($_REQUEST['column'])) {
			// Single column or already decoded array
			$selectedColumns = is_array($_REQUEST['column']) ? $_REQUEST['column'] : [$_REQUEST['column']];
		}

		if (empty($selectedColumns)) {
			doDefault($lang['strnoobjects']);
			return;
		}

		$misc->printTrail('table');
		$misc->printTitle($lang['stredit'], 'pg.column.alter');
		$misc->printMsg($msg);

		// Load column data for each selected column
		$columns = [];
		foreach ($selectedColumns as $colname) {
			$attrs = $tableActions->getTableAttributes($_REQUEST['table'], $colname);
			if ($attrs->recordCount() > 0) {
				$col = $attrs->fields;
				$col['attnotnull'] = $pg->phpBool($col['attnotnull']);

				// Extract length from type if present
				$length = '';
				if ($col['type'] != $col['base_type'] && preg_match('/\\(([0-9, ]*)\\)/', $col['type'], $bits)) {
					$length = $bits[1];
				}

				$columns[] = [
					'attname' => $col['attname'],
					'base_type' => $col['base_type'],
					'length' => $length,
					'attnotnull' => $col['attnotnull'],
					'adsrc' => $col['adsrc'],
					'comment' => $col['comment'],
				];
			}
		}

		?>
		<form action="tblproperties.php" method="post">
			<?php $renderer->renderTable($columns, $_POST) ?>
			<p>
				<input type="hidden" name="action" value="edit_columns" />
				<input type="hidden" name="num_columns" value="<?= count($columns) ?>" />
				<?= $misc->form ?>
				<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
				<?php foreach ($selectedColumns as $colname): ?>
					<input type="hidden" name="original_columns[]" value="<?= html_esc($colname) ?>" />
				<?php endforeach; ?>
				<input type="submit" name="save" value="<?= $lang['strsave'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</p>
		</form>
		<?= $renderer->renderJavaScriptInit(count($columns)) ?>
		<?php
	} else {
		// Process the edit
		$numColumns = isset($_POST['num_columns']) ? (int) $_POST['num_columns'] : 0;
		$originalColumns = $_POST['original_columns'] ?? [];

		if ($numColumns == 0 || empty($originalColumns)) {
			doDefault($lang['strinvalidparam']);
			return;
		}

		// Begin transaction
		$status = $pg->beginTransaction();
		if ($status != 0) {
			doEditColumns(true, $lang['strcolumnalteredbad']);
			return;
		}

		$alteredCount = 0;
		$errors = [];

		for ($i = 0; $i < $numColumns; $i++) {
			if (!isset($originalColumns[$i])) {
				continue;
			}

			$originalName = $originalColumns[$i];
			$newName = isset($_POST['field'][$i]) ? trim($_POST['field'][$i]) : '';

			if ($newName == '') {
				$errors[] = sprintf($lang['strcolneedsname'] . ' - Row %d', $i + 1);
				continue;
			}

			// Get original column data
			$attrs = $tableActions->getTableAttributes($_REQUEST['table'], $originalName);
			if ($attrs->recordCount() == 0) {
				continue;
			}
			$originalCol = $attrs->fields;

			// Determine default value
			$defaultValue = '';
			if (isset($_POST['default_preset'][$i])) {
				$preset = $_POST['default_preset'][$i];
				if ($preset === 'custom') {
					$defaultValue = $_POST['default'][$i] ?? '';
				} elseif ($preset !== '') {
					$defaultValue = $preset;
				}
			} elseif (isset($_POST['default'][$i])) {
				$defaultValue = $_POST['default'][$i];
			}

			$type = $_POST['type'][$i] ?? $originalCol['base_type'];
			$length = $_POST['length'][$i] ?? '';
			$array = $_POST['array'][$i] ?? '';
			$notnull = isset($_POST['notnull'][$i]);
			$comment = $_POST['comment'][$i] ?? '';

			// Build old type format
			$oldType = $pg->formatType($originalCol['type'], $originalCol['atttypmod']);

			$status = $columnActions->alterColumn(
				$_POST['table'],
				$originalName,
				$newName,
				$notnull,
				$pg->phpBool($originalCol['attnotnull']),
				$defaultValue,
				$originalCol['adsrc'],
				$type,
				$length,
				$array,
				$oldType,
				$comment
			);

			if ($status != 0) {
				$errors[] = sprintf('%s: %s', html_esc($originalName), $lang['strcolumnalteredbad']);
			} else {
				$alteredCount++;
			}
		}

		if (!empty($errors)) {
			$pg->rollbackTransaction();
			doEditColumns(true, implode('<br />', $errors));
			return;
		}

		// Commit transaction
		$status = $pg->endTransaction();
		if ($status != 0) {
			doEditColumns(true, $lang['strcolumnalteredbad']);
			return;
		}

		// Success
		AppContainer::setShouldReloadTree(true);
		$msg = format_string(
			$lang['strcolumnsaltered'],
			[
				'count' => $alteredCount
			]
		);
		doDefault($msg);
	}
}

/**
 * Drop multiple columns with confirmation
 */
function doDropMultiple($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$columnActions = new ColumnActions($pg);

	if ($confirm) {

		// Get selected columns
		$selectedColumns = [];
		if (isset($_REQUEST['ma'])) {
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				$selectedColumns[] = $a['column'];
			}
		} elseif (isset($_REQUEST['column'])) {
			$selectedColumns = is_array($_REQUEST['column']) ? $_REQUEST['column'] : [$_REQUEST['column']];
		}

		if (empty($selectedColumns)) {
			doDefault($lang['strnoobjects']);
			return;
		}

		$misc->printTrail('table');
		$misc->printTitle($lang['strdrop'], 'pg.column.drop');

		?>
		<p>
			<?= format_string(
				$lang['strconfdropcolumns'],
				[
					'count' => count($selectedColumns),
					'table' => $misc->formatVal($_REQUEST['table'])
				]
			) ?>
		</p>
		<ul>
			<?php foreach ($selectedColumns as $colname): ?>
				<li>
					<?= $misc->formatVal($colname) ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<form action="tblproperties.php" method="post">
			<input type="hidden" name="action" value="confirm_drop_columns" />
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
			<?php foreach ($selectedColumns as $colname): ?>
				<input type="hidden" name="column[]" value="<?= html_esc($colname) ?>" />
			<?php endforeach; ?>
			<?= $misc->form ?>
			<p><input type="checkbox" id="cascade" name="cascade"> <label for="cascade">
					<?= $lang['strcascade'] ?>
				</label></p>
			<input type="submit" name="drop" value="<?= $lang['strdrop'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</form>
		<?php
	} else {
		// Execute drop
		$columns = $_POST['column'] ?? [];
		$cascade = isset($_POST['cascade']);

		if (empty($columns)) {
			doDefault($lang['strnoobjects']);
			return;
		}

		$droppedCount = 0;
		$errors = [];

		foreach ($columns as $colname) {
			$status = $columnActions->dropColumn($_POST['table'], $colname, $cascade);
			if ($status == 0) {
				$droppedCount++;
			} else {
				$errors[] = $colname;
			}
		}

		if (!empty($errors)) {
			doDefault(sprintf($lang['strcolumndroppedbad'] . ' (%s)', implode(', ', $errors)));
		} else {
			AppContainer::setShouldReloadTree(true);
			$msg = format_string(
				$lang['strcolumnsdeleted'],
				[
					'count' => $droppedCount
				]
			);
			doDefault($msg);
		}
	}
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$tableActions = new TableActions($pg);

	$columns = $tableActions->getTableAttributes($_REQUEST['table']);
	$reqvars = $misc->getRequestVars('column');

	$attrs = [
		'text' => field('attname'),
		'action' => url(
			'colproperties.php',
			$reqvars,
			[
				'table' => $_REQUEST['table'],
				'column' => field('attname'),
			]
		),
		'icon' => 'Column',
		'iconAction' => url(
			'display.php',
			$reqvars,
			[
				'table' => $_REQUEST['table'],
				'column' => field('attname'),
				'query' => replace(
					'SELECT "%column%", count(*) AS "count" FROM "%table%" GROUP BY "%column%" ORDER BY "%column%"',
					[
						'%column%' => field('attname'),
						'%table%' => $_REQUEST['table'],
					]
				),
			]
		),
		'toolTip' => field('comment'),
	];

	$misc->printTree($columns, $attrs, 'tblcolumns');

	exit;
}

// Main program

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree') {
	doTree();
}

$misc = AppContainer::getMisc();

$misc->printHeader($lang['strtables'] . ' - ' . $_REQUEST['table']);
$misc->printBody();

switch ($action) {
	case 'alter':
		if (isset($_POST['alter'])) {
			doSaveAlter();
		} else {
			doDefault();
		}
		break;
	case 'confirm_alter':
		doAlter();
		break;
	case 'import':
		doImport();
		break;
	case 'export':
		doExport();
		break;
	case 'add_column':
		doAddColumn();
		break;
	case 'save_add_column':
		if (isset($_POST['add'])) {
			doSaveAddColumn();
		} else {
			doDefault();
		}
		break;
	case 'edit_columns':
		if (isset($_POST['save'])) {
			doEditColumns(false);
		} elseif (isset($_POST['cancel'])) {
			doDefault();
		} else {
			doEditColumns(true);
		}
		break;
	case 'confirm_drop_columns':
		if (isset($_POST['drop'])) {
			doDropMultiple(false);
		} elseif (isset($_POST['cancel'])) {
			doDefault();
		} else {
			doDropMultiple(true);
		}
		break;
	case 'properties':
		if (isset($_POST['cancel'])) {
			doDefault();
		} else {
			doProperties();
		}
		break;
	case 'drop':
		if (isset($_POST['drop'])) {
			doDrop(false);
		} else {
			doDefault();
		}
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
