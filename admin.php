<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;

// init global value script
// it will contain either tables.php or database.php
$script = '';

/**
 * Show confirmation of cluster and perform cluster
 */
function doCluster($type, $confirm = false)
{
	global $script;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$indexActions = new IndexActions($pg);

	if (($type == 'table') && empty($_REQUEST['table']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifytabletocluster']);
		return;
	}

	if ($confirm) {
		if (isset($_REQUEST['ma'])) {
			$misc->printTrail('schema');
			$misc->printTitle($lang['strclusterindex'], 'pg.index.cluster');
			?>
			<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
				<?php foreach ($_REQUEST['ma'] as $v) {
					$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES)); ?>
					<p><?= sprintf($lang['strconfclustertable'], $misc->formatVal($a['table'])) ?></p>
					<input type="hidden" name="table[]" value="<?= html_esc($a['table']) ?>" />
				<?php } ?>
				<input type="hidden" name="action" value="cluster" />
				<?= $misc->form ?>
				<input type="submit" name="cluster" value="<?= $lang['strcluster'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</form>
			<?php
		} // END if multi cluster
		else {
			$misc->printTrail($type);
			$misc->printTitle($lang['strclusterindex'], 'pg.index.cluster');
			?>
			<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
				<?php if ($type == 'table') { ?>
					<p><?= sprintf($lang['strconfclustertable'], $misc->formatVal($_REQUEST['object'])) ?></p>
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['object']) ?>" />
				<?php } else { ?>
					<p><?= sprintf($lang['strconfclusterdatabase'], $misc->formatVal($_REQUEST['object'])) ?></p>
					<input type="hidden" name="table" value="" />
				<?php } ?>
				<input type="hidden" name="action" value="cluster" />
				<?= $misc->form ?>
				<input type="submit" name="cluster" value="<?= $lang['strcluster'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</form>
			<?php
		}
	} // END single cluster
	else {
		//If multi table cluster
		if ($type == 'table') { // cluster one or more table
			if (is_array($_REQUEST['table'])) {
				$msg = '';
				foreach ($_REQUEST['table'] as $o) {
					$status = $indexActions->clusterIndex($o);
					if ($status == 0)
						$msg .= sprintf('%s: %s<br />', htmlentities($o, ENT_QUOTES, 'UTF-8'), $lang['strclusteredgood']);
					else {
						doDefault($type, sprintf('%s%s: %s<br />', $msg, htmlentities($o, ENT_QUOTES, 'UTF-8'), $lang['strclusteredbad']));
						return;
					}
				}
				// Everything went fine, back to the Default page....
				doDefault($msg);
			} else {
				$status = $indexActions->clusterIndex($_REQUEST['object']);
				if ($status == 0) {
					doAdmin($type, $lang['strclusteredgood']);
				} else
					doAdmin($type, $lang['strclusteredbad']);
			}
		} else { // Cluster all tables in database
			$status = $indexActions->clusterIndex();
			if ($status == 0) {
				doAdmin($type, $lang['strclusteredgood']);
			} else
				doAdmin($type, $lang['strclusteredbad']);
		}
	}
}

/**
 * Show confirmation of reindex and perform reindex
 */
function doReindex($type, $confirm = false)
{
	global $script;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$indexActions = new IndexActions($pg);

	if (($type == 'table') && empty($_REQUEST['table']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifytabletoreindex']);
		return;
	}

	if ($confirm) {
		if (isset($_REQUEST['ma'])) {
			$misc->printTrail('schema');
			$misc->printTitle($lang['strreindex'], 'pg.reindex');
			?>
			<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
				<?php foreach ($_REQUEST['ma'] as $v) {
					$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES)); ?>
					<p><?= sprintf($lang['strconfreindextable'], $misc->formatVal($a['table'])) ?></p>
					<input type="hidden" name="table[]" value="<?= html_esc($a['table']) ?>" />
				<?php } ?>
				<input type="hidden" name="action" value="reindex" />
				<?= $misc->form ?>
				<input type="submit" name="reindex" value="<?= $lang['strreindex'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</form>
			<?php
		} // END if multi reindex
		else {
			$misc->printTrail($type);
			$misc->printTitle($lang['strreindex'], 'pg.reindex');
			?>
			<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
				<?php if ($type == 'table') { ?>
					<p><?= sprintf($lang['strconfreindextable'], $misc->formatVal($_REQUEST['object'])) ?></p>
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['object']) ?>" />
				<?php } else { ?>
					<p><?= sprintf($lang['strconfreindexdatabase'], $misc->formatVal($_REQUEST['object'])) ?></p>
					<input type="hidden" name="table" value="" />
				<?php } ?>
				<input type="hidden" name="action" value="reindex" />
				<?= $misc->form ?>
				<input type="submit" name="reindex" value="<?= $lang['strreindex'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</form>
			<?php
		}
	} // END single reindex
	else {
		//If multi table reindex
		if (($type == 'table') && is_array($_REQUEST['table'])) {
			$msg = '';
			foreach ($_REQUEST['table'] as $o) {
				$status = $indexActions->reindex(
					strtoupper($type),
					$o,
					false
				);
				if ($status == 0)
					$msg .= sprintf('%s: %s<br />', htmlentities($o, ENT_QUOTES, 'UTF-8'), $lang['strreindexgood']);
				else {
					doDefault($type, sprintf('%s%s: %s<br />', $msg, htmlentities($o, ENT_QUOTES, 'UTF-8'), $lang['strreindexbad']));
					return;
				}
			}
			doDefault($msg);
		} else {
			$status = $indexActions->reindex(
				strtoupper($type),
				$_REQUEST['object'],
				false
			);
			if ($status == 0) {
				doAdmin($type, $lang['strreindexgood']);
			} else
				doAdmin($type, $lang['strreindexbad']);
		}
	}
}

/**
 * Show confirmation of analyze and perform analyze
 */
function doAnalyze($type, $confirm = false)
{
	global $script;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$adminActions = new AdminActions($pg);


	if (($type == 'table') && empty($_REQUEST['table']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifytabletoanalyze']);
		return;
	}

	if ($confirm) {
		if (isset($_REQUEST['ma'])) {
			$misc->printTrail('schema');
			$misc->printTitle($lang['stranalyze'], 'pg.analyze');
			?>
			<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
				<?php foreach ($_REQUEST['ma'] as $v) {
					$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES)); ?>
					<p><?= sprintf($lang['strconfanalyzetable'], $misc->formatVal($a['table'])) ?></p>
					<input type="hidden" name="table[]" value="<?= html_esc($a['table']) ?>" />
				<?php } ?>
				<input type="hidden" name="action" value="analyze" />
				<?= $misc->form ?>
				<input type="submit" name="analyze" value="<?= $lang['stranalyze'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</form>
			<?php
		} // END if multi analyze
		else {
			$misc->printTrail($type);
			$misc->printTitle($lang['stranalyze'], 'pg.analyze');
			?>
			<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
				<?php if ($type == 'table') { ?>
					<p><?= sprintf($lang['strconfanalyzetable'], $misc->formatVal($_REQUEST['object'])) ?></p>
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['object']) ?>" />
				<?php } else { ?>
					<p><?= sprintf($lang['strconfanalyzedatabase'], $misc->formatVal($_REQUEST['object'])) ?></p>
					<input type="hidden" name="table" value="" />
				<?php } ?>
				<input type="hidden" name="action" value="analyze" />
				<?= $misc->form ?>
				<input type="submit" name="analyze" value="<?= $lang['stranalyze'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</form>
			<?php
		}
	} // END single analyze
	else {
		//If multi table analyze
		if (($type == 'table') && is_array($_REQUEST['table'])) {
			$msg = '';
			foreach ($_REQUEST['table'] as $table) {
				$status = $adminActions->analyzeDB($table);
				if ($status == 0)
					$msg .= sprintf('%s: %s<br />', htmlentities($table, ENT_QUOTES, 'UTF-8'), $lang['stranalyzegood']);
				else {
					doDefault($type, sprintf('%s%s: %s<br />', $msg, htmlentities($table, ENT_QUOTES, 'UTF-8'), $lang['stranalyzebad']));
					return;
				}
			}
			doDefault($msg);
		} else {
			//we must pass table here. When empty, analyze the whole db
			$status = $adminActions->analyzeDB($_REQUEST['table']);
			if ($status == 0) {
				doAdmin($type, $lang['stranalyzegood']);
			} else
				doAdmin($type, $lang['stranalyzebad']);
		}
	}
}

/**
 * Show confirmation of vacuum and perform actual vacuum
 */
function doVacuum($type, $confirm = false)
{
	global $script;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$adminActions = new AdminActions($pg);

	if (($type == 'table') && empty($_REQUEST['table']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifytabletovacuum']);
		return;
	}

	if ($confirm) {
		if (isset($_REQUEST['ma'])) {
			$misc->printTrail('schema');
			$misc->printTitle($lang['strvacuum'], 'pg.vacuum');
			?>
			<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
				<?php foreach ($_REQUEST['ma'] as $v) {
					$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES)); ?>
					<p><?= sprintf($lang['strconfvacuumtable'], $misc->formatVal($a['table'])) ?></p>
					<input type="hidden" name="table[]" value="<?= html_esc($a['table']) ?>" />
				<?php } ?>
				<input type="hidden" name="action" value="vacuum" />
				<?= $misc->form ?>
				<p><input type="checkbox" id="vacuum_full" name="vacuum_full" /> <label
						for="vacuum_full"><?= $lang['strfull'] ?></label></p>
				<p><input type="checkbox" id="vacuum_analyze" name="vacuum_analyze" /> <label
						for="vacuum_analyze"><?= $lang['stranalyze'] ?></label></p>
				<p><input type="checkbox" id="vacuum_freeze" name="vacuum_freeze" /> <label
						for="vacuum_freeze"><?= $lang['strfreeze'] ?></label></p>
				<input type="submit" name="vacuum" value="<?= $lang['strvacuum'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</form>
			<?php
		} // END if multi vacuum
		else {
			$misc->printTrail($type);
			$misc->printTitle($lang['strvacuum'], 'pg.vacuum');
			?>
			<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
				<?php if ($type == 'table') { ?>
					<p><?= sprintf($lang['strconfvacuumtable'], $misc->formatVal($_REQUEST['object'])) ?></p>
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['object']) ?>" />
				<?php } else { ?>
					<p><?= sprintf($lang['strconfvacuumdatabase'], $misc->formatVal($_REQUEST['object'])) ?></p>
					<input type="hidden" name="table" value="" />
				<?php } ?>
				<input type="hidden" name="action" value="vacuum" />
				<?= $misc->form ?>
				<p><input type="checkbox" id="vacuum_full" name="vacuum_full" /> <label
						for="vacuum_full"><?= $lang['strfull'] ?></label></p>
				<p><input type="checkbox" id="vacuum_analyze" name="vacuum_analyze" /> <label
						for="vacuum_analyze"><?= $lang['stranalyze'] ?></label></p>
				<p><input type="checkbox" id="vacuum_freeze" name="vacuum_freeze" /> <label
						for="vacuum_freeze"><?= $lang['strfreeze'] ?></label></p>
				<input type="submit" name="vacuum" value="<?= $lang['strvacuum'] ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
			</form>
			<?php
		}
	} // END single vacuum
	else {
		//If multi drop
		if (is_array($_REQUEST['table'])) {
			$msg = '';
			foreach ($_REQUEST['table'] as $table) {
				$status = $adminActions->vacuumDB(
					$table,
					isset($_REQUEST['vacuum_analyze']),
					isset($_REQUEST['vacuum_full']),
					isset($_REQUEST['vacuum_freeze'])
				);
				if ($status == 0)
					$msg .= sprintf('%s: %s<br />', htmlentities($table, ENT_QUOTES, 'UTF-8'), $lang['strvacuumgood']);
				else {
					doDefault($type, sprintf('%s%s: %s<br />', $msg, htmlentities($table, ENT_QUOTES, 'UTF-8'), $lang['strvacuumbad']));
					return;
				}
			}
			doDefault($msg);
		} else {
			//we must pass table here. When empty, vacuum the whole db
			$status = $adminActions->vacuumDB(
				$_REQUEST['table'],
				isset($_REQUEST['vacuum_analyze']),
				isset($_REQUEST['vacuum_full']),
				isset($_REQUEST['vacuum_freeze'])
			);
			if ($status == 0) {
				doAdmin($type, $lang['strvacuumgood']);
			} else
				doAdmin($type, $lang['strvacuumbad']);
		}
	}
}

/**
 * Add or Edit autovacuum params and save them
 */
function doEditAutovacuum($type, $confirm, $msg = '')
{
	global $script;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$adminActions = new AdminActions($pg);

	if (empty($_REQUEST['table'])) {
		doAdmin($type, $lang['strspecifyeditvacuumtable']);
		return;
	}

	$script = ($type == 'database') ? 'database.php' : 'tables.php';

	if ($confirm) {
		$misc->printTrail($type);
		$misc->printTitle(sprintf($lang['streditvacuumtable'], $misc->formatVal($_REQUEST['table'])));
		$misc->printMsg(sprintf($msg, $misc->formatVal($_REQUEST['table'])));

		if (empty($_REQUEST['table'])) {
			doAdmin($type, $lang['strspecifyeditvacuumtable']);
			return;
		}

		$old_val = $adminActions->getTableAutovacuum($_REQUEST['table']);
		$defaults = $adminActions->getAutovacuum();
		$old_val = $old_val->fields ?: [];

		if (isset($old_val['autovacuum_enabled']) and ($old_val['autovacuum_enabled'] == 'off')) {
			$enabled = '';
			$disabled = 'checked="checked"';
		} else {
			$enabled = 'checked="checked"';
			$disabled = '';
		}

		if (!isset($old_val['autovacuum_vacuum_threshold']))
			$old_val['autovacuum_vacuum_threshold'] = '';
		if (!isset($old_val['autovacuum_vacuum_scale_factor']))
			$old_val['autovacuum_vacuum_scale_factor'] = '';
		if (!isset($old_val['autovacuum_analyze_threshold']))
			$old_val['autovacuum_analyze_threshold'] = '';
		if (!isset($old_val['autovacuum_analyze_scale_factor']))
			$old_val['autovacuum_analyze_scale_factor'] = '';
		if (!isset($old_val['autovacuum_vacuum_cost_delay']))
			$old_val['autovacuum_vacuum_cost_delay'] = '';
		if (!isset($old_val['autovacuum_vacuum_cost_limit']))
			$old_val['autovacuum_vacuum_cost_limit'] = '';

		?>
		<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
			<?= $misc->form ?>
			<input type="hidden" name="action" value="editautovac" />
			<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />

			<br /><br />
			<table>
				<tr>
					<td>&nbsp;</td>
					<th class="data"><?= $lang['strnewvalues'] ?></th>
					<th class="data"><?= $lang['strdefaultvalues'] ?></th>
				</tr>
				<tr>
					<th class="data left"><?= $lang['strenable'] ?></th>
					<td class="data1">
						<input type="radio" name="autovacuum_enabled" id="on" value="on" <?= $enabled ?> /> <label
							for="on">On</label>
						&nbsp;&nbsp;
						<input type="radio" name="autovacuum_enabled" id="off" value="off" <?= $disabled ?> /> <label
							for="off">Off</label>
					</td>
					<th class="data left"><?= $defaults['autovacuum'] ?></th>
				</tr>

				<tr>
					<th class="data left"><?= $lang['strvacuumbasethreshold'] ?></th>
					<td class="data1"><input type="text" name="autovacuum_vacuum_threshold"
							value="<?= html_esc($old_val['autovacuum_vacuum_threshold']) ?>" /></td>
					<th class="data left"><?= $defaults['autovacuum_vacuum_threshold'] ?></th>
				</tr>

				<tr>
					<th class="data left"><?= $lang['strvacuumscalefactor'] ?></th>
					<td class="data1"><input type="text" name="autovacuum_vacuum_scale_factor"
							value="<?= html_esc($old_val['autovacuum_vacuum_scale_factor']) ?>" /></td>
					<th class="data left"><?= $defaults['autovacuum_vacuum_scale_factor'] ?></th>
				</tr>

				<tr>
					<th class="data left"><?= $lang['stranalybasethreshold'] ?></th>
					<td class="data1"><input type="text" name="autovacuum_analyze_threshold"
							value="<?= html_esc($old_val['autovacuum_analyze_threshold']) ?>" /></td>
					<th class="data left"><?= $defaults['autovacuum_analyze_threshold'] ?></th>
				</tr>

				<tr>
					<th class="data left"><?= $lang['stranalyzescalefactor'] ?></th>
					<td class="data1"><input type="text" name="autovacuum_analyze_scale_factor"
							value="<?= html_esc($old_val['autovacuum_analyze_scale_factor']) ?>" /></td>
					<th class="data left"><?= $defaults['autovacuum_analyze_scale_factor'] ?></th>
				</tr>

				<tr>
					<th class="data left"><?= $lang['strvacuumcostdelay'] ?></th>
					<td class="data1"><input type="text" name="autovacuum_vacuum_cost_delay"
							value="<?= html_esc($old_val['autovacuum_vacuum_cost_delay']) ?>" /></td>
					<th class="data left"><?= $defaults['autovacuum_vacuum_cost_delay'] ?></th>
				</tr>

				<tr>
					<th class="data left"><?= $lang['strvacuumcostlimit'] ?></th>
					<td class="datat1"><input type="text" name="autovacuum_vacuum_cost_limit"
							value="<?= html_esc($old_val['autovacuum_vacuum_cost_limit']) ?>" /></td>
					<th class="data left"><?= $defaults['autovacuum_vacuum_cost_limit'] ?></th>
				</tr>
			</table>
			<br /><br />
			<input type="submit" name="save" value="<?= $lang['strsave'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</form>
		<?php
	} else {
		$status = $adminActions->saveAutovacuum(
			$_REQUEST['table'],
			$_POST['autovacuum_enabled'],
			$_POST['autovacuum_vacuum_threshold'],
			$_POST['autovacuum_vacuum_scale_factor'],
			$_POST['autovacuum_analyze_threshold'],
			$_POST['autovacuum_analyze_scale_factor'],
			$_POST['autovacuum_vacuum_cost_delay'],
			$_POST['autovacuum_vacuum_cost_limit']
		);

		if ($status == 0)
			doAdmin($type, sprintf($lang['strsetvacuumtablesaved'], $_REQUEST['table']));
		else
			doEditAutovacuum($type, true, $lang['strsetvacuumtablefail']);
	}
}

/**
 * confirm drop autovacuum params for a table and drop it
 */
function doDropAutovacuum($type, $confirm)
{
	global $script;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$adminActions = new AdminActions($pg);

	if (empty($_REQUEST['table'])) {
		doAdmin($type, $lang['strspecifydelvacuumtable']);
		return;
	}

	if ($confirm) {
		$misc->printTrail($type);
		$misc->printTabs($type, 'admin');

		$script = ($type == 'database') ? 'database.php' : 'tables.php';
		?>
		<p><?= sprintf($lang['strdelvacuumtable'], html_esc($_REQUEST['table'])) ?></p>

		<div class="flex-row">
			<div>
				<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
					<input type="hidden" name="action" value="delautovac" />
					<?= $misc->form ?>
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
					<input type="hidden" name="rel"
						value="<?= html_esc(serialize([$_REQUEST['schema'], $_REQUEST['table']])) ?>" />
					<input type="submit" name="yes" value="<?= $lang['stryes'] ?>" />
				</form>
			</div>
			<div class="ml-2">
				<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
					<input type="hidden" name="action" value="admin" />
					<input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']) ?>" />
					<?= $misc->form ?>
					<input type="submit" name="no" value="<?= $lang['strno'] ?>" />
				</form>
			</div>
		</div>

		<?php
	} else {

		$status = $adminActions->dropAutovacuum($_POST['table']);

		if ($status == 0) {
			doAdmin($type, sprintf($lang['strvacuumtablereset'], $misc->formatVal($_POST['table'])));
		} else
			doAdmin($type, sprintf($lang['strdelvacuumtablefail'], $misc->formatVal($_POST['table'])));
	}
}

/**
 * database/table administration and tuning tasks
 *
 * $Id: admin.php
 */

function doAdmin($type, $msg = '')
{
	global $script;
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$adminActions = new AdminActions($pg);
	$indexActions = new IndexActions($pg);

	$misc->printTrail($type);
	$misc->printTabs($type, 'admin');
	$misc->printMsg($msg);

	?>
	<?php if ($type == 'database'): ?>
		<p><?= sprintf($lang['stradminondatabase'], html_esc($_REQUEST['object'])) ?></p>
	<?php else: ?>
		<p><?= sprintf($lang['stradminontable'], html_esc($_REQUEST['object'])) ?></p>
	<?php endif ?>

	<table style="width: 50%">
		<tr>
			<th class="data text-center"><?php $misc->printHelp($lang['strvacuum'], 'pg.admin.vacuum'); ?></th>
			<th class="data text-center"><?php $misc->printHelp($lang['stranalyze'], 'pg.admin.analyze'); ?></th>
			<th class="data text-center"><?php $misc->printHelp($lang['strclusterindex'], 'pg.index.cluster'); ?></th>
			<th class="data text-center"><?php $misc->printHelp($lang['strreindex'], 'pg.index.reindex'); ?></th>
		</tr>

		<tr class="row1">
			<td style="text-align: center; vertical-align: bottom">
				<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
					<p><input type="hidden" name="action" value="confirm_vacuum" />
						<?= $misc->form ?>
						<?php if ($type == 'table'): ?>
							<input type="hidden" name="table" value="<?= html_esc($_REQUEST['object']) ?>" />
							<input type="hidden" name="subject" value="table" />
						<?php endif ?>
						<button type="submit"><img class='icon' src='images/themes/default/Broom.png'>
							<?= $lang['strvacuum'] ?></button>
					</p>
				</form>
			</td>

			<td style="text-align: center; vertical-align: bottom">
				<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
					<p><input type="hidden" name="action" value="confirm_analyze" />
						<?= $misc->form ?>
						<?php if ($type == 'table'): ?>
							<input type="hidden" name="table" value="<?= html_esc($_REQUEST['object']) ?>" />
							<input type="hidden" name="subject" value="table" />
						<?php endif ?>
						<button type="submit"><img class='icon' src='images/themes/default/Analyze.png'>
							<?= $lang['stranalyze'] ?></button>
					</p>
				</form>
			</td>

			<?php $disabled = ''; ?>
			<td style="text-align: center; vertical-align: bottom">
				<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
					<?= $misc->form ?>
					<?php if ($type == 'table') {
						echo '<input type="hidden" name="table" value="' . html_esc($_REQUEST['object']) . '" />'
							. '<input type="hidden" name="subject" value="table" />';

						if (!$indexActions->alreadyClustered($_REQUEST['object'])) {
							$disabled = 'disabled="disabled" ';
							echo "{$lang['strnoclusteravailable']}<br />";
						}
					} ?>
					<p><input type="hidden" name="action" value="confirm_cluster" />
						<button type="submit" <?= $disabled ?>><img class='icon' src='images/themes/default/Cluster.png'>
							<?= $lang['strclusterindex'] ?></button>
					</p>
				</form>
			</td>

			<td style="text-align: center; vertical-align: bottom">
				<form action="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" method="post">
					<p><input type="hidden" name="action" value="confirm_reindex" />
						<?= $misc->form ?>
						<?php if ($type == 'table'): ?>
							<input type="hidden" name="table" value="<?= html_esc($_REQUEST['object']) ?>" />
							<input type="hidden" name="subject" value="table" />
						<?php endif ?>
						<button type="submit"><img class='icon' src='images/themes/default/Index.png'>
							<?= $lang['strreindex'] ?></button>
					</p>
				</form>
			</td>
		</tr>
	</table>

	<?php
	// Autovacuum
	$defaults = $adminActions->getAutovacuum();
	if ($type == 'table')
		$autovac = $adminActions->getTableAutovacuum($_REQUEST['table']);
	else
		$autovac = $adminActions->getTableAutovacuum();
	//var_dump($autovac);
	?>

	<br /><br />
	<h2><?= $lang['strvacuumpertable'] ?></h2>
	<p><?= ($defaults['autovacuum'] == 'on') ? $lang['strturnedon'] : $lang['strturnedoff'] ?></p>
	<!--
	<p class="message"><?= $lang['strnotdefaultinred'] ?></p>
	-->

	<?php
	$enlight = function ($f, $p) {
		if (isset($f[$p[0]]) && $f[$p[0]] != $p[1]) {
			$value = $f[$p[0]];
			$class = 'custom';
		} else {
			$value = $p[1];
			$class = 'default';
		}
		$value .= $p['append'] ?? '';
		return "<span class=\"autovac $class\">" . html_esc($value) . "</span>";
	};

	$columns = [
		'namespace' => [
			'title' => $lang['strschema'],
			'field' => field('nspname'),
			'url' => "redirect.php?subject=schema&amp;{$misc->href}&amp;",
			'vars' => ['schema' => 'nspname'],
			'icon' => 'Schema',
			'class' => 'nowrap',
		],
		'relname' => [
			'title' => $lang['strtable'],
			'field' => field('relname'),
			'url' => "redirect.php?subject=table&amp;{$misc->href}&amp;",
			'vars' => ['table' => 'relname', 'schema' => 'nspname'],
			'icon' => 'Table',
			'class' => 'nowrap',
		],
		'autovacuum_enabled' => [
			'title' => $lang['strenabled'],
			'field' => callback($enlight, ['autovacuum_enabled', $defaults['autovacuum']]),
			'type' => 'verbatim'
		],
		'autovacuum_vacuum_threshold' => [
			'title' => $lang['strvacuumbasethreshold'],
			'field' => callback($enlight, ['autovacuum_vacuum_threshold', $defaults['autovacuum_vacuum_threshold']]),
			'type' => 'verbatim'
		],
		'autovacuum_vacuum_scale_factor' => [
			'title' => $lang['strvacuumscalefactor'],
			'field' => callback($enlight, ['autovacuum_vacuum_scale_factor', $defaults['autovacuum_vacuum_scale_factor']]),
			'type' => 'verbatim'
		],
		'autovacuum_analyze_threshold' => [
			'title' => $lang['stranalybasethreshold'],
			'field' => callback($enlight, ['autovacuum_analyze_threshold', $defaults['autovacuum_analyze_threshold']]),
			'type' => 'verbatim'
		],
		'autovacuum_analyze_scale_factor' => [
			'title' => $lang['stranalyzescalefactor'],
			'field' => callback($enlight, ['autovacuum_analyze_scale_factor', $defaults['autovacuum_analyze_scale_factor']]),
			'type' => 'verbatim'
		],
		'autovacuum_vacuum_cost_delay' => [
			'title' => $lang['strvacuumcostdelay'],
			'field' => callback($enlight, ['autovacuum_vacuum_cost_delay', $defaults['autovacuum_vacuum_cost_delay'], 'append' => 'ms']),
			'type' => 'verbatim'
		],
		'autovacuum_vacuum_cost_limit' => [
			'title' => $lang['strvacuumcostlimit'],
			'field' => callback($enlight, ['autovacuum_vacuum_cost_limit', $defaults['autovacuum_vacuum_cost_limit']]),
			'type' => 'verbatim'
		],
	];

	// Maybe we need to check permissions here?
	$columns['actions'] = ['title' => $lang['stractions']];

	$actions = [
		'edit' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit'],
			'attr' => [
				'href' => [
					'url' => $script,
					'urlvars' => [
						'subject' => $type,
						'action' => 'confeditautovac',
						'schema' => field('nspname'),
						'table' => field('relname')
					]
				]
			]
		],
		'delete' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdelete'],
			'attr' => [
				'href' => [
					'url' => $script,
					'urlvars' => [
						'subject' => $type,
						'action' => 'confdelautovac',
						'schema' => field('nspname'),
						'table' => field('relname')
					]
				]
			]
		]
	];

	if ($type == 'table') {
		unset(
			$actions['edit']['vars']['schema'],
			$actions['delete']['vars']['schema'],
			$columns['namespace'],
			$columns['relname']
		);
	}

	$misc->printTable($autovac, $columns, $actions, 'admin-admin', $lang['strnovacuumconf']);

	if (($type == 'table') and ($autovac->recordCount() == 0)): ?>
		<br />
		<a
			href="tables.php?action=confeditautovac&amp;<?= $misc->href ?>&amp;table=<?= html_esc($_REQUEST['table']) ?>"><?= $lang['straddvacuumtable'] ?></a>
	<?php endif;
}

function adminActions($action, $type)
{
	global $script;

	if ($type == 'database') {
		$_REQUEST['object'] = $_REQUEST['database'];
		$script = 'database.php';
	} else {
		// $_REQUEST['table'] is no set if we are in the schema page
		$_REQUEST['object'] = ($_REQUEST['table'] ?? '');
		$script = 'tables.php';
	}

	switch ($action) {
		case 'confirm_cluster':
			doCluster($type, true);
			break;
		case 'confirm_reindex':
			doReindex($type, true);
			break;
		case 'confirm_analyze':
			doAnalyze($type, true);
			break;
		case 'confirm_vacuum':
			doVacuum($type, true);
			break;
		case 'cluster':
			if (isset($_POST['cluster']))
				doCluster($type);
			// if multi-action from table canceled: back to the schema default page
			else if (($type == 'table') && is_array($_REQUEST['object']))
				doDefault();
			else
				doAdmin($type);
			break;
		case 'reindex':
			if (isset($_POST['reindex']))
				doReindex($type);
			// if multi-action from table canceled: back to the schema default page
			else if (($type == 'table') && is_array($_REQUEST['object']))
				doDefault();
			else
				doAdmin($type);
			break;
		case 'analyze':
			if (isset($_POST['analyze']))
				doAnalyze($type);
			// if multi-action from table canceled: back to the schema default page
			else if (($type == 'table') && is_array($_REQUEST['object']))
				doDefault();
			else
				doAdmin($type);
			break;
		case 'vacuum':
			if (isset($_POST['vacuum']))
				doVacuum($type);
			// if multi-action from table canceled: back to the schema default page
			else if (($type == 'table') && is_array($_REQUEST['object']))
				doDefault();
			else
				doAdmin($type);
			break;
		case 'admin':
			doAdmin($type);
			break;
		case 'confeditautovac':
			doEditAutovacuum($type, true);
			break;
		case 'confdelautovac':
			doDropAutovacuum($type, true);
			break;
		//case 'confaddautovac':
		//	doAddAutovacuum(true);
		//	break;
		case 'editautovac':
			if (isset($_POST['save']))
				doEditAutovacuum($type, false);
			else
				doAdmin($type);
			break;
		case 'delautovac':
			doDropAutovacuum($type, false);
			break;
		default:
			return false;
	}
	return true;
}
