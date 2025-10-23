<?php

use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Html\XHtmlButton;
use PhpPgAdmin\Html\XHtmlOption;
use PhpPgAdmin\Html\XHtmlSelect;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RowActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\TablespaceActions;

/**
 * List constraints on a table
 *
 * $Id: constraints.php,v 1.56 2007/12/31 16:46:07 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Confirm and then actually add a FOREIGN KEY constraint
 */
function addForeignKey($stage, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$schemaActions = new SchemaActions($pg);
	$tableActions = new TableActions($pg);
	$constraintActions = new ConstraintActions($pg);

	if (!isset($_POST['name']))
		$_POST['name'] = '';
	if (!isset($_POST['target']))
		$_POST['target'] = '';

	if ($stage == 2) {

		// Check that they've given at least one source column
		if (!isset($_REQUEST['SourceColumnList']) && (!isset($_POST['IndexColumnList']) || !is_array($_POST['IndexColumnList']) || sizeof($_POST['IndexColumnList']) == 0)) {
			addForeignKey(1, $lang['strfkneedscols']);
			return;
		}

		// Copy the IndexColumnList variable from stage 1
		if (isset($_REQUEST['IndexColumnList']) && !isset($_REQUEST['SourceColumnList']))
			$_REQUEST['SourceColumnList'] = serialize($_REQUEST['IndexColumnList']);

		// Initialise variables
		if (!isset($_POST['upd_action']))
			$_POST['upd_action'] = null;
		if (!isset($_POST['del_action']))
			$_POST['del_action'] = null;
		if (!isset($_POST['match']))
			$_POST['match'] = null;
		if (!isset($_POST['deferrable']))
			$_POST['deferrable'] = null;
		if (!isset($_POST['initially']))
			$_POST['initially'] = null;
		$_REQUEST['target'] = unserialize($_REQUEST['target']);

		$misc->printTrail('table');
		$misc->printTitle($lang['straddfk'], 'pg.constraint.foreign_key');
		$misc->printMsg($msg);

		// Unserialize target and fetch appropriate table. This is a bit messy
		// because the table could be in another schema.
		$schemaActions->setSchema($_REQUEST['target']['schemaname']);
		$attrs = $tableActions->getTableAttributes($_REQUEST['target']['tablename']);
		$schemaActions->setSchema($_REQUEST['schema']);

		$selColumns = new XHtmlSelect('TableColumnList', true, 10);
		$selColumns->set_style('width: 15em;');

		if ($attrs->recordCount() > 0) {
			while (!$attrs->EOF) {
				$XHtmlOption = new XHtmlOption($attrs->fields['attname']);
				$selColumns->add($XHtmlOption);
				$attrs->moveNext();
			}
		}

		$selIndex = new XHtmlSelect('IndexColumnList[]', true, 10);
		$selIndex->set_style('width: 15em;');
		$selIndex->set_attribute('id', 'IndexColumnList');
		$buttonAdd = new XHtmlButton('add', '>>');
		$buttonAdd->set_attribute('onclick', 'buttonPressed(this);');
		$buttonAdd->set_attribute('type', 'button');

		$buttonRemove = new XHtmlButton('remove', '<<');
		$buttonRemove->set_attribute('onclick', 'buttonPressed(this);');
		$buttonRemove->set_attribute('type', 'button');

		echo "<form onsubmit=\"doSelectAll();\" name=\"formIndex\" action=\"constraints.php\" method=\"post\">\n";

		echo "<table>\n";
		echo "<tr><th class=\"data\" colspan=\"3\">{$lang['strfktarget']}</th></tr>";
		echo "<tr><th class=\"data\">{$lang['strtablecolumnlist']}</th><th class=\"data\">&nbsp;</th><th class=data>{$lang['strfkcolumnlist']}</th></tr>\n";
		echo "<tr><td class=\"data1\">" . $selColumns->fetch() . "</td>\n";
		echo "<td class=\"data1\" style=\"text-align: center\">" . $buttonRemove->fetch() . $buttonAdd->fetch() . "</td>";
		echo "<td class=\"data1\">" . $selIndex->fetch() . "</td></tr>\n";
		echo "<tr><th class=\"data\" colspan=\"3\">{$lang['stractions']}</th></tr>";
		echo "<tr>";
		echo "<td class=\"data1\" colspan=\"3\">\n";
		// ON SELECT actions
		echo "{$lang['stronupdate']} <select name=\"upd_action\">";
		foreach (ConstraintActions::FK_ACTIONS as $v)
			echo "<option value=\"{$v}\"", ($_POST['upd_action'] == $v) ? ' selected="selected"' : '', ">{$v}</option>\n";
		echo "</select><br />\n";

		// ON DELETE actions
		echo "{$lang['strondelete']} <select name=\"del_action\">";
		foreach (ConstraintActions::FK_ACTIONS as $v)
			echo "<option value=\"{$v}\"", ($_POST['del_action'] == $v) ? ' selected="selected"' : '', ">{$v}</option>\n";
		echo "</select><br />\n";

		// MATCH options
		echo "<select name=\"match\">";
		foreach (ConstraintActions::FK_MATCHES as $v)
			echo "<option value=\"{$v}\"", ($_POST['match'] == $v) ? ' selected="selected"' : '', ">{$v}</option>\n";
		echo "</select><br />\n";

		// DEFERRABLE options
		echo "<select name=\"deferrable\">";
		foreach (ConstraintActions::FK_DEFERRABLE as $v)
			echo "<option value=\"{$v}\"", ($_POST['deferrable'] == $v) ? ' selected="selected"' : '', ">{$v}</option>\n";
		echo "</select><br />\n";

		// INITIALLY options
		echo "<select name=\"initially\">";
		foreach (ConstraintActions::FK_INITIALLY as $v)
			echo "<option value=\"{$v}\"", ($_POST['initially'] == $v) ? ' selected="selected"' : '', ">{$v}</option>\n";
		echo "</select>\n";
		echo "</td></tr>\n";
		echo "</table>\n";

		echo "<p><input type=\"hidden\" name=\"action\" value=\"save_add_foreign_key\" />\n";
		echo $misc->form;
		echo "<input type=\"hidden\" name=\"subject\" value=\"table\" />\n";
		echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		echo "<input type=\"hidden\" name=\"name\" value=\"", html_esc($_REQUEST['name']), "\" />\n";
		echo "<input type=\"hidden\" name=\"target\" value=\"", html_esc(serialize($_REQUEST['target'])), "\" />\n";
		echo "<input type=\"hidden\" name=\"SourceColumnList\" value=\"", html_esc($_REQUEST['SourceColumnList']), "\" />\n";
		echo "<input type=\"hidden\" name=\"stage\" value=\"3\" />\n";
		echo "<input type=\"submit\" value=\"{$lang['stradd']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";

	} elseif ($stage == 3) {

		// Unserialize target
		$_POST['target'] = unserialize($_POST['target']);

		// Check that they've given at least one column
		if (isset($_POST['SourceColumnList']))
			$temp = unserialize($_POST['SourceColumnList']);
		if (
			!isset($_POST['IndexColumnList']) || !is_array($_POST['IndexColumnList'])
			|| sizeof($_POST['IndexColumnList']) == 0 || !isset($temp)
			|| !is_array($temp) || sizeof($temp) == 0
		)
			addForeignKey(2, $lang['strfkneedscols']);
		else {
			$status = $constraintActions->addForeignKey(
				$_POST['table'],
				$_POST['target']['schemaname'],
				$_POST['target']['tablename'],
				unserialize($_POST['SourceColumnList']),
				$_POST['IndexColumnList'],
				$_POST['upd_action'],
				$_POST['del_action'],
				$_POST['match'],
				$_POST['deferrable'],
				$_POST['initially'],
				$_POST['name']
			);
			if ($status == 0)
				doDefault($lang['strfkadded']);
			else
				addForeignKey(2, $lang['strfkaddedbad']);
		}

	} else {

		// Default stage 1
		$misc->printTrail('table');
		$misc->printTitle($lang['straddfk'], 'pg.constraint.foreign_key');
		$misc->printMsg($msg);

		$attrs = $tableActions->getTableAttributes($_REQUEST['table']);
		$tables = $tableActions->getTables(true);

		$selColumns = new XHtmlSelect('TableColumnList', true, 10);
		$selColumns->set_style('width: 15em;');

		if ($attrs->recordCount() > 0) {
			while (!$attrs->EOF) {
				$XHtmlOption = new XHtmlOption($attrs->fields['attname']);
				$selColumns->add($XHtmlOption);
				$attrs->moveNext();
			}
		}

		$selIndex = new XHtmlSelect('IndexColumnList[]', true, 10);
		$selIndex->set_style('width: 15em;');
		$selIndex->set_attribute('id', 'IndexColumnList');
		$buttonAdd = new XHtmlButton('add', '>>');
		$buttonAdd->set_attribute('onclick', 'buttonPressed(this);');
		$buttonAdd->set_attribute('type', 'button');

		$buttonRemove = new XHtmlButton('remove', '<<');
		$buttonRemove->set_attribute('onclick', 'buttonPressed(this);');
		$buttonRemove->set_attribute('type', 'button');

		echo "<form onsubmit=\"doSelectAll();\" name=\"formIndex\" action=\"constraints.php\" method=\"post\">\n";

		echo "<table>\n";
		echo "<tr><th class=\"data\" colspan=\"3\">{$lang['strname']}</th></tr>\n";
		echo "<tr><td class=\"data1\" colspan=\"3\"><input type=\"text\" name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" /></td></tr>\n";
		echo "<tr><th class=\"data\">{$lang['strtablecolumnlist']}</th><th class=\"data\">&nbsp;</th><th class=\"data required\">{$lang['strfkcolumnlist']}</th></tr>\n";
		echo "<tr><td class=\"data1\">" . $selColumns->fetch() . "</td>\n";
		echo "<td class=\"data1\" style=\"text-align: center\">" . $buttonRemove->fetch() . $buttonAdd->fetch() . "</td>\n";
		echo "<td class=data1>" . $selIndex->fetch() . "</td></tr>\n";
		echo "<tr><th class=\"data\" colspan=\"3\">{$lang['strfktarget']}</th></tr>";
		echo "<tr>";
		echo "<td class=\"data1\" colspan=\"3\"><select name=\"target\">";
		while (!$tables->EOF) {
			$key = ['schemaname' => $tables->fields['nspname'], 'tablename' => $tables->fields['relname']];
			$key = serialize($key);
			echo "<option value=\"", html_esc($key), "\">";
			if ($tables->fields['nspname'] != $_REQUEST['schema']) {
				echo html_esc($tables->fields['nspname']), '.';
			}
			echo html_esc($tables->fields['relname']), "</option>\n";
			$tables->moveNext();
		}
		echo "</select>\n";
		echo "</td></tr>";
		echo "</table>\n";

		echo "<p><input type=\"hidden\" name=\"action\" value=\"save_add_foreign_key\" />\n";
		echo $misc->form;
		echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		echo "<input type=\"hidden\" name=\"stage\" value=\"2\" />\n";
		echo "<input type=\"submit\" value=\"{$lang['stradd']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	}


}

/**
 * Confirm and then actually add a PRIMARY KEY or UNIQUE constraint
 */
function addPrimaryOrUniqueKey($type, $confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$tableSpaceActions = new TablespaceActions($pg);
	$constraintActions = new ConstraintActions($pg);

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	if (!isset($_POST['name']))
		$_POST['name'] = '';

	if ($confirm) {
		if (!isset($_POST['name']))
			$_POST['name'] = '';
		if (!isset($_POST['tablespace']))
			$_POST['tablespace'] = '';

		$misc->printTrail($subject);

		switch ($type) {
			case 'primary':
				$misc->printTitle($lang['straddpk'], 'pg.constraint.primary_key');
				$suffix = 'pkey';
				break;
			case 'unique':
				$misc->printTitle($lang['stradduniq'], 'pg.constraint.unique_key');
				$suffix = 'unique';
				break;
			default:
				doDefault($lang['strinvalidparam']);
				return;
		}

		$misc->printMsg($msg);

		$attrs = $tableActions->getTableAttributes($table);
		// Fetch all tablespaces from the database
		if ($pg->hasTablespaces())
			$tablespaces = $tableSpaceActions->getTablespaces();

		$selColumns = new XHtmlSelect('TableColumnList', true, 10);
		$selColumns->set_style('width: 15em;');

		if ($attrs->recordCount() > 0) {
			while (!$attrs->EOF) {
				$XHTML_Option = new XHtmlOption($attrs->fields['attname']);
				$selColumns->add($XHTML_Option);
				$attrs->moveNext();
			}
		}

		$selIndex = new XHtmlSelect('IndexColumnList[]', true, 10);
		$selIndex->set_style('width: 15em;');
		$selIndex->set_attribute('id', 'IndexColumnList');
		$buttonAdd = new XHtmlButton('add', '>>');
		$buttonAdd->set_attribute('onclick', 'buttonPressed(this);');
		$buttonAdd->set_attribute('type', 'button');

		$buttonRemove = new XHtmlButton('remove', '<<');
		$buttonRemove->set_attribute('onclick', 'buttonPressed(this);');
		$buttonRemove->set_attribute('type', 'button');

		echo "<form onsubmit=\"doSelectAll();\" name=\"formIndex\" action=\"constraints.php\" method=\"post\">\n";
		?>

		<table>
			<tr>
				<th class="data" colspan="3"><?= $lang['strname']; ?></th>
			</tr>
			<tr>
				<td class="data1" colspan="3">
					<input type="text" name="name" value="<?= html_esc($_POST['name']); ?>" id="formIndexName" size="32"
						data-suffix="<?= $suffix ?>" maxlength="<?= $pg->_maxNameLen; ?>" />
				</td>
			</tr>
			<tr>
				<th class="data"><?= $lang['strtablecolumnlist']; ?></th>
				<th class="data">&nbsp;</th>
				<th class="data required"><?= $lang['strindexcolumnlist']; ?></th>
			</tr>
			<tr>
				<td class="data1"><?= $selColumns->fetch(); ?></td>
				<td class="data1" style="text-align: center"><?= $buttonRemove->fetch() . $buttonAdd->fetch(); ?>
				</td>
				<td class="data1"><?= $selIndex->fetch(); ?></td>
			</tr>

			<?php if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0): ?>
				<tr>
					<th class="data" colspan="3"><?= $lang['strtablespace']; ?></th>
				</tr>
				<tr>
					<td class="data1" colspan="3"><select name="tablespace">
							<option value="" <?= ($_POST['tablespace'] == '') ? ' selected="selected"' : ''; ?>></option>
							<?php while (!$tablespaces->EOF):
								$spcname = html_esc($tablespaces->fields['spcname']); ?>
								<option value="<?= $spcname; ?>" <?= ($spcname == $_POST['tablespace']) ? ' selected="selected"' : ''; ?>>
									<?= $spcname; ?>
								</option>
								<?php $tablespaces->moveNext(); endwhile; ?>
						</select></td>
				</tr>
			<?php endif; ?>

		</table>

		<p><input type="hidden" name="action" value="save_add_primary_key" />
			<?= $misc->form; ?>
			<input type="hidden" name="subject" value="<?= htmlspecialchars($subject); ?>" />
			<input type="hidden" name="<?= htmlspecialchars($subject); ?>" value="<?= htmlspecialchars($table); ?>" />
			<input type="hidden" name="type" value="<?= html_esc($type); ?>" />
			<input type="submit" value="<?= $lang['stradd']; ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
		</p>

		<?php
		echo "</form>\n";
	} else {
		// Default tablespace to empty if it isn't set
		if (!isset($_POST['tablespace']))
			$_POST['tablespace'] = '';

		if ($_POST['type'] == 'primary') {
			// Check that they've given at least one column
			if (
				!isset($_POST['IndexColumnList']) || !is_array($_POST['IndexColumnList'])
				|| sizeof($_POST['IndexColumnList']) == 0
			)
				addPrimaryOrUniqueKey($_POST['type'], true, $lang['strpkneedscols']);
			else {
				$status = $constraintActions->addPrimaryKey(
					$table,
					$_POST['IndexColumnList'],
					$_POST['name'],
					$_POST['tablespace']
				);
				if ($status == 0)
					doDefault($lang['strpkadded']);
				else
					addPrimaryOrUniqueKey($_POST['type'], true, $lang['strpkaddedbad']);
			}
		} elseif ($_POST['type'] == 'unique') {
			// Check that they've given at least one column
			if (
				!isset($_POST['IndexColumnList']) || !is_array($_POST['IndexColumnList'])
				|| sizeof($_POST['IndexColumnList']) == 0
			)
				addPrimaryOrUniqueKey($_POST['type'], true, $lang['struniqneedscols']);
			else {
				$status = $constraintActions->addUniqueKey(
					$table,
					$_POST['IndexColumnList'],
					$_POST['name'],
					$_POST['tablespace']
				);
				if ($status == 0)
					doDefault($lang['struniqadded']);
				else
					addPrimaryOrUniqueKey($_POST['type'], true, $lang['struniqaddedbad']);
			}
		} else
			doDefault($lang['strinvalidparam']);
	}
}

/**
 * Confirm and then actually add a CHECK constraint
 */
function addCheck($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$constraintActions = new ConstraintActions($pg);

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	if (!isset($_POST['name']))
		$_POST['name'] = '';
	if (!isset($_POST['definition']))
		$_POST['definition'] = '';

	if ($confirm) {
		$misc->printTrail($subject);
		$misc->printTitle($lang['straddcheck'], 'pg.constraint.check');
		$misc->printMsg($msg);

		echo "<form action=\"constraints.php\" method=\"post\">\n";
		echo "<table>\n";
		echo "<tr><th class=\"data\">{$lang['strname']}</th>\n";
		echo "<th class=\"data required\">{$lang['strdefinition']}</th></tr>\n";

		echo "<tr><td class=\"data1\"><input name=\"name\" size=\"16\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
			html_esc($_POST['name']), "\" /></td>\n";

		echo "<td class=\"data1\">(<input name=\"definition\" size=\"32\" value=\"",
			html_esc($_POST['definition']), "\" />)</td></tr>\n";
		echo "</table>\n";

		echo "<input type=\"hidden\" name=\"action\" value=\"save_add_check\" />\n";
		echo "<input type=\"hidden\" name=\"subject\" value=\"", htmlspecialchars($subject), "\" />\n";
		echo "<input type=\"hidden\" name=\"", htmlspecialchars($subject), "\" value=\"", htmlspecialchars($table), "\" />\n";
		echo $misc->form;
		echo "<p><input type=\"submit\" name=\"ok\" value=\"{$lang['stradd']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";

	} else {
		if (trim($_POST['definition']) == '')
			addCheck(true, $lang['strcheckneedsdefinition']);
		else {
			$status = $constraintActions->addCheckConstraint(
				$table,
				$_POST['definition'],
				$_POST['name']
			);
			if ($status == 0)
				doDefault($lang['strcheckadded']);
			else
				addCheck(true, $lang['strcheckaddedbad']);
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
	$constraintActions = new ConstraintActions($pg);

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	if ($confirm) {
		$misc->printTrail('constraint');
		$misc->printTitle($lang['strdrop'], 'pg.constraint.drop');

		echo "<p>", sprintf(
			$lang['strconfdropconstraint'],
			$misc->formatVal($_REQUEST['constraint']),
			$misc->formatVal($table)
		), "</p>\n";

		echo "<form action=\"constraints.php\" method=\"post\">\n";
		echo "<input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo "<input type=\"hidden\" name=\"subject\" value=\"", html_esc($subject), "\" />\n";
		echo "<input type=\"hidden\" name=\"", html_esc($subject), "\" value=\"", html_esc($table), "\" />\n";
		echo "<input type=\"hidden\" name=\"constraint\" value=\"", html_esc($_REQUEST['constraint']), "\" />\n";
		echo "<input type=\"hidden\" name=\"type\" value=\"", html_esc($_REQUEST['type']), "\" />\n";
		echo $misc->form;
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		echo "</form>\n";
	} else {
		$status = $constraintActions->dropConstraint(
			$_POST['constraint'],
			$subject,
			$_POST['type'],
			isset($_POST['cascade'])
		);
		if ($status == 0)
			doDefault($lang['strconstraintdropped']);
		else
			doDefault($lang['strconstraintdroppedbad']);
	}
}

/**
 * List all the constraints on the table
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$partitionActions = new PartitionActions($pg);
	$constraintActions = new ConstraintActions($pg);

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	$cnPre = function (&$rowdata) use ($tableActions, $pg, $lang, $table) {
		if (is_null($rowdata->fields['consrc'])) {
			$atts = $tableActions->getAttributeNames($table, explode(' ', $rowdata->fields['indkey']));
			$rowdata->fields['+definition'] = ($rowdata->fields['contype'] == 'u' ? "UNIQUE (" : "PRIMARY KEY (") . join(',', $atts) . ')';
		} else {
			$rowdata->fields['+definition'] = $rowdata->fields['consrc'];
		}
		$rowdata->fields['icon'] = getConstraintIcon($rowdata->fields['contype']);

		// Determine scope (for partitions PG 10+)
		if ($pg->major_version >= 10) {
			$conislocal = $rowdata->fields['conislocal'] ?? 't';
			$coninhcount = $rowdata->fields['coninhcount'] ?? 0;

			if ($conislocal == 'f' || $coninhcount > 0) {
				$rowdata->fields['+scope'] = '<span class="constraint-inherited">' . $lang['strconstraintinherited'] . '</span>';
				$rowdata->fields['+data-scope'] = 'inherited';
			} else {
				$rowdata->fields['+scope'] = '<span class="constraint-local">' . $lang['strconstraintlocal'] . '</span>';
				$rowdata->fields['+data-scope'] = 'local';
			}
		}
	};

	$misc->printTrail($subject);
	$misc->printTabs($subject, 'constraints');
	$misc->printMsg($msg);

	$constraints = $constraintActions->getConstraints($table);

	// Check if this is a partition (PG 10+)
	$is_partition = $partitionActions->isPartition($table);

	// Add filter controls for partitions
	if ($is_partition && $constraints->recordCount() > 0) {
		?>
		<div class="constraint-filter" style="margin-bottom: 10px;">
			<strong><?= $lang['strfilter']; ?>:</strong>
			<button type="button" onclick="filterConstraints('all')" id="filter-all"><?= $lang['strshowall']; ?></button>
			<button type="button" onclick="filterConstraints('inherited')"
				id="filter-inherited"><?= $lang['strshowinherited']; ?></button>
			<button type="button" onclick="filterConstraints('local')" id="filter-local"><?= $lang['strshowlocal']; ?></button>
		</div>

		<script type="text/javascript">
			(function () {
				function filterConstraints(type) {
					var rows = document.querySelectorAll('.constraints-constraints tbody tr');
					for (var i = 0; i < rows.length; i++) {
						var scopeCell = rows[i].cells[2]; // Assuming Scope is 3rd column
						if (!scopeCell) continue;
						var scopeText = scopeCell.textContent.trim().toLowerCase();
						if (type === 'all') {
							rows[i].style.display = '';
						} else if (type === 'inherited' && scopeText.indexOf('inherited') >= 0) {
							rows[i].style.display = '';
						} else if (type === 'local' && scopeText.indexOf('local') >= 0) {
							rows[i].style.display = '';
						} else {
							rows[i].style.display = 'none';
						}
					}
				}
				window.filterConstraints = filterConstraints;
			})();
		</script>
		<?php
	}

	$columns = [
		'constraint' => [
			'title' => $lang['strname'],
			'field' => field('conname'),
			'icon' => field('icon'),
		],
		'definition' => [
			'title' => $lang['strdefinition'],
			'field' => field('+definition'),
			'type' => 'sql',
		],
	];

	// Add Scope column for partitions (PG 10+)
	if ($is_partition) {
		$columns['scope'] = [
			'title' => $lang['strscope'],
			'field' => field('+scope'),
		];
	}

	$columns['actions'] = [
		'title' => $lang['stractions'],
	];
	$columns['comment'] = [
		'title' => $lang['strcomment'],
		'field' => field('constcomment'),
	];

	$actions = [
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'constraints.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'subject' => $subject,
						$subject => $table,
						'constraint' => field('conname'),
						'type' => field('contype')
					]
				]
			]
		]
	];

	// Disable drop action for inherited constraints in partitions
	if ($is_partition) {
		$actions['drop']['disable'] = function ($row) use ($pg) {
			if ($pg->major_version >= 10) {
				$conislocal = $row['conislocal'] ?? 't';
				$coninhcount = $row['coninhcount'] ?? 0;
				// Disable if inherited (not local or has inheritance count)
				return ($conislocal == 'f' || $coninhcount > 0);
			}
			return false;
		};
	}

	$misc->printTable($constraints, $columns, $actions, 'constraints-constraints', $lang['strnoconstraints'], $cnPre);

	$navlinks = [
		'addcheck' => [
			'attr' => [
				'href' => [
					'url' => 'constraints.php',
					'urlvars' => [
						'action' => 'add_check',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'subject' => $subject,
						$subject => $table,
					]
				]
			],
			'icon' => $misc->icon('AddCheckConstraint'),
			'content' => $lang['straddcheck'],
		],
		'adduniq' => [
			'attr' => [
				'href' => [
					'url' => 'constraints.php',
					'urlvars' => [
						'action' => 'add_unique_key',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'subject' => $subject,
						$subject => $table,
					]
				]
			],
			'icon' => $misc->icon('AddUniqueConstraint'),
			'content' => $lang['stradduniq'],
		],
		'addpk' => [
			'attr' => [
				'href' => [
					'url' => 'constraints.php',
					'urlvars' => [
						'action' => 'add_primary_key',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'subject' => $subject,
						$subject => $table,
					]
				]
			],
			'icon' => $misc->icon('AddPrimaryKey'),
			'content' => $lang['straddpk'],
		],
		'addfk' => [
			'attr' => [
				'href' => [
					'url' => 'constraints.php',
					'urlvars' => [
						'action' => 'add_foreign_key',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema'],
						'subject' => $subject,
						$subject => $table,
					]
				]
			],
			'icon' => $misc->icon('AddForeignKey'),
			'content' => $lang['straddfk']
		]
	];

	if ($subject == 'view') {
		// Remove add foreign key link for views
		unset($navlinks['addfk']);
	}

	$misc->printNavLinks($navlinks, 'constraints-constraints', get_defined_vars());
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$constraintActions = new ConstraintActions($pg);

	$table = $_REQUEST['table'] ?? $_REQUEST['view'] ?? '';

	$constraints = $constraintActions->getConstraints($table);

	//$reqvars = $misc->getRequestVars('schema');

	$getIcon = function ($f) {
		//var_dump($f);
		return getConstraintIcon($f['contype']);
	};

	$attrs = [
		'text' => field('conname'),
		'icon' => callback($getIcon),
	];

	$misc->printTree($constraints, $attrs, 'constraints');
	exit;
}

function getConstraintIcon($type)
{
	//var_dump($type);
	switch ($type) {
		case 'u':
			return 'UniqueConstraint';
		case 'c':
			return 'CheckConstraint';
		case 'f':
			return 'ForeignKey';
		case 'p':
			return 'PrimaryKey';
		case 'n':
			return 'NotNull';

	}
}


// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

$table = $_REQUEST['table'] ?? $_REQUEST['view'] ?? '';

if ($action == 'tree')
	doTree();

$misc->printHeader(
	$table . ' - ' . $lang['strconstraints'],
	"<script defer src=\"js/indexes.js\" type=\"text/javascript\"></script>"
);

$misc->printBody();

switch ($action) {
	case 'add_foreign_key':
		addForeignKey(1);
		break;
	case 'save_add_foreign_key':
		if (isset($_POST['cancel']))
			doDefault();
		else
			addForeignKey($_REQUEST['stage']);
		break;
	case 'add_unique_key':
		addPrimaryOrUniqueKey('unique', true);
		break;
	case 'save_add_unique_key':
		if (isset($_POST['cancel']))
			doDefault();
		else
			addPrimaryOrUniqueKey('unique', false);
		break;
	case 'add_primary_key':
		addPrimaryOrUniqueKey('primary', true);
		break;
	case 'save_add_primary_key':
		if (isset($_POST['cancel']))
			doDefault();
		else
			addPrimaryOrUniqueKey('primary', false);
		break;
	case 'add_check':
		addCheck(true);
		break;
	case 'save_add_check':
		if (isset($_POST['cancel']))
			doDefault();
		else
			addCheck(false);
		break;
	case 'save_create':
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
	default:
		doDefault();
		break;
}

$misc->printFooter();


