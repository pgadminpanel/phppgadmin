<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Actions\TableActions;

/**
 * List extra information on a table
 *
 * $Id: info.php,v 1.14 2007/05/28 17:30:32 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * List all the information on the table
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$constraintActions = new ConstraintActions($pg);
	$tableActions = new TableActions($pg);

	$misc->printTrail('table');
	$misc->printTabs('table', 'info');
	$misc->printMsg($msg);

	// common params for printVal
	$shownull = ['null' => true];

	// Fetch info
	$referrers = $constraintActions->getReferrers($_REQUEST['table']);
	$parents = $tableActions->getTableParents($_REQUEST['table']);
	$children = $tableActions->getTableChildren($_REQUEST['table']);
	$tablestatstups = $tableActions->getStatsTableTuples($_REQUEST['table']);
	$tablestatsio = $tableActions->getStatsTableIO($_REQUEST['table']);
	$indexstatstups = $tableActions->getStatsIndexTuples($_REQUEST['table']);
	$indexstatsio = $tableActions->getStatsIndexIO($_REQUEST['table']);

	$found = false;

	// Referring foreign tables
	if ($referrers !== -99 && $referrers->recordCount() > 0) {
		$found = true;
		echo "<fieldset>\n";
		?>
		<legend><?= $lang['strreferringtables'] ?></legend>
		<?php

		$columns = [
			'schema' => [
				'title' => $lang['strschema'],
				'field' => field('nspname')
			],
			'table' => [
				'title' => $lang['strtable'],
				'field' => field('relname'),
			],
			'name' => [
				'title' => $lang['strname'],
				'field' => field('conname'),
			],
			'definition' => [
				'title' => $lang['strdefinition'],
				'field' => field('consrc'),
			],
			'actions' => [
				'title' => $lang['stractions'],
			]
		];

		$actions = [
			'properties' => [
				'icon' => $misc->icon('Constrains'),
				'content' => $lang['strproperties'],
				'attr' => [
					'href' => [
						'url' => 'constraints.php',
						'urlvars' => [
							'schema' => field('nspname'),
							'table' => field('relname')
						]
					]
				]
			]
		];

		$misc->printTable($referrers, $columns, $actions, 'info-referrers', $lang['strnodata']);
		echo "</fieldset>\n";
	}

	// Parent tables
	if ($parents->recordCount() > 0) {
		$found = true;
		echo "<fieldset>\n";
		?>
		<legend><?= $lang['strparenttables'] ?></legend>
		<?php

		$columns = [
			'schema' => [
				'title' => $lang['strschema'],
				'field' => field('nspname')
			],
			'table' => [
				'title' => $lang['strtable'],
				'field' => field('relname'),
			],
			'actions' => [
				'title' => $lang['stractions'],
			]
		];

		$actions = [
			'properties' => [
				'icon' => $misc->icon('Constrains'),
				'content' => $lang['strproperties'],
				'attr' => [
					'href' => [
						'url' => 'tblproperties.php',
						'urlvars' => [
							'schema' => field('nspname'),
							'table' => field('relname')
						]
					]
				]
			]
		];

		$misc->printTable($parents, $columns, $actions, 'info-parents', $lang['strnodata']);
		echo "</fieldset>\n";
	}

	// Child tables
	if ($children->recordCount() > 0) {
		$found = true;
		echo "<fieldset>\n";
		?>
		<legend><?= $lang['strchildtables'] ?></legend>
		<?php

		$columns = [
			'schema' => [
				'title' => $lang['strschema'],
				'field' => field('nspname')
			],
			'table' => [
				'title' => $lang['strtable'],
				'field' => field('relname'),
			],
			'actions' => [
				'title' => $lang['stractions'],
			]
		];

		$actions = [
			'properties' => [
				'icon' => $misc->icon('Constrains'),
				'content' => $lang['strproperties'],
				'attr' => [
					'href' => [
						'url' => 'tblproperties.php',
						'urlvars' => [
							'schema' => field('nspname'),
							'table' => field('relname')
						]
					]
				]
			]
		];

		$misc->printTable($children, $columns, $actions, 'info-children', $lang['strnodata']);

		echo "</fieldset>\n";
	}

	// Row performance
	if ($tablestatstups->recordCount() > 0) {
		$found = true;
		echo "<fieldset>\n";
		?>
		<legend><?= $lang['strrowperf'] ?></legend>
		<table>
			<tr>
				<th class="data" colspan="2"><?= $lang['strsequential'] ?></th>
				<th class="data" colspan="2"><?= $lang['strindex'] ?></th>
				<th class="data" colspan="3"><?= $lang['strrows2'] ?></th>
			</tr>
			<tr>
				<th class="data"><?= $lang['strscan'] ?></th>
				<th class="data"><?= $lang['strread'] ?></th>
				<th class="data"><?= $lang['strscan'] ?></th>
				<th class="data"><?= $lang['strfetch'] ?></th>
				<th class="data"><?= $lang['strinsert'] ?></th>
				<th class="data"><?= $lang['strupdate'] ?></th>
				<th class="data"><?= $lang['strdelete'] ?></th>
			</tr>
			<?php
			$i = 0;
			while (!$tablestatstups->EOF) {
				$id = (($i % 2) == 0 ? '1' : '2');
				?>
				<tr class="data<?= $id ?>">
					<td><?= $misc->formatVal($tablestatstups->fields['seq_scan'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($tablestatstups->fields['seq_tup_read'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($tablestatstups->fields['idx_scan'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($tablestatstups->fields['idx_tup_fetch'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($tablestatstups->fields['n_tup_ins'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($tablestatstups->fields['n_tup_upd'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($tablestatstups->fields['n_tup_del'], 'int4', $shownull) ?></td>
				</tr>
				<?php
				$tablestatstups->movenext();
				$i++;
			}
			?>
		</table>
		<?php
		echo "</fieldset>\n";
	}

	// I/O performance
	if ($tablestatsio->recordCount() > 0) {
		$found = true;
		echo "<fieldset>\n";
		?>
		<legend><?= $lang['strioperf'] ?></legend>
		<table>
			<tr>
				<th class="data" colspan="3"><?= $lang['strheap'] ?></th>
				<th class="data" colspan="3"><?= $lang['strindex'] ?></th>
				<th class="data" colspan="3"><?= $lang['strtoast'] ?></th>
				<th class="data" colspan="3"><?= $lang['strtoastindex'] ?></th>
			</tr>
			<tr>
				<th class="data"><?= $lang['strdisk'] ?></th>
				<th class="data"><?= $lang['strcache'] ?></th>
				<th class="data"><?= $lang['strpercent'] ?></th>
				<th class="data"><?= $lang['strdisk'] ?></th>
				<th class="data"><?= $lang['strcache'] ?></th>
				<th class="data"><?= $lang['strpercent'] ?></th>
				<th class="data"><?= $lang['strdisk'] ?></th>
				<th class="data"><?= $lang['strcache'] ?></th>
				<th class="data"><?= $lang['strpercent'] ?></th>
				<th class="data"><?= $lang['strdisk'] ?></th>
				<th class="data"><?= $lang['strcache'] ?></th>
				<th class="data"><?= $lang['strpercent'] ?></th>
			</tr>
			<?php
			$i = 0;
			while (!$tablestatsio->EOF) {
				$id = (($i % 2) == 0 ? '1' : '2');

				$total = $tablestatsio->fields['heap_blks_hit'] + $tablestatsio->fields['heap_blks_read'];
				if ($total > 0)
					$percentage = round(($tablestatsio->fields['heap_blks_hit'] / $total) * 100);
				else
					$percentage = 0;
				$heap_read = $tablestatsio->fields['heap_blks_read'];
				$heap_hit = $tablestatsio->fields['heap_blks_hit'];

				$total = $tablestatsio->fields['idx_blks_hit'] + $tablestatsio->fields['idx_blks_read'];
				if ($total > 0)
					$percentage_idx = round(($tablestatsio->fields['idx_blks_hit'] / $total) * 100);
				else
					$percentage_idx = 0;
				$idx_read = $tablestatsio->fields['idx_blks_read'];
				$idx_hit = $tablestatsio->fields['idx_blks_hit'];

				$total = $tablestatsio->fields['toast_blks_hit'] + $tablestatsio->fields['toast_blks_read'];
				if ($total > 0)
					$percentage_toast = round(($tablestatsio->fields['toast_blks_hit'] / $total) * 100);
				else
					$percentage_toast = 0;
				$toast_read = $tablestatsio->fields['toast_blks_read'];
				$toast_hit = $tablestatsio->fields['toast_blks_hit'];

				$total = $tablestatsio->fields['tidx_blks_hit'] + $tablestatsio->fields['tidx_blks_read'];
				if ($total > 0)
					$percentage_tidx = round(($tablestatsio->fields['tidx_blks_hit'] / $total) * 100);
				else
					$percentage_tidx = 0;
				$tidx_read = $tablestatsio->fields['tidx_blks_read'];
				$tidx_hit = $tablestatsio->fields['tidx_blks_hit'];
				?>
				<tr class="data<?= $id ?>">
					<td><?= $misc->formatVal($heap_read, 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($heap_hit, 'int4', $shownull) ?></td>
					<td>(<?= $percentage ?><?= $lang['strpercent'] ?>)</td>

					<td><?= $misc->formatVal($idx_read, 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($idx_hit, 'int4', $shownull) ?></td>
					<td>(<?= $percentage_idx ?><?= $lang['strpercent'] ?>)</td>

					<td><?= $misc->formatVal($toast_read, 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($toast_hit, 'int4', $shownull) ?></td>
					<td>(<?= $percentage_toast ?><?= $lang['strpercent'] ?>)</td>

					<td><?= $misc->formatVal($tidx_read, 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($tidx_hit, 'int4', $shownull) ?></td>
					<td>(<?= $percentage_tidx ?><?= $lang['strpercent'] ?>)</td>
				</tr>
				<?php
				$tablestatsio->movenext();
				$i++;
			}
			?>
		</table>
		<?php
		echo "</fieldset>\n";
	}

	// Index row performance
	if ($indexstatstups->recordCount() > 0) {
		$found = true;
		echo "<fieldset>\n";
		?>
		<legend><?= $lang['stridxrowperf'] ?></legend>
		<table>
			<tr>
				<th class="data"><?= $lang['strindex'] ?></th>
				<th class="data"><?= $lang['strscan'] ?></th>
				<th class="data"><?= $lang['strread'] ?></th>
				<th class="data"><?= $lang['strfetch'] ?></th>
			</tr>
			<?php
			$i = 0;
			while (!$indexstatstups->EOF) {
				$id = (($i % 2) == 0 ? '1' : '2');
				?>
				<tr class="data<?= $id ?>">
					<td><?= $misc->formatVal($indexstatstups->fields['indexrelname']) ?></td>
					<td><?= $misc->formatVal($indexstatstups->fields['idx_scan'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($indexstatstups->fields['idx_tup_read'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($indexstatstups->fields['idx_tup_fetch'], 'int4', $shownull) ?></td>
				</tr>
				<?php
				$indexstatstups->movenext();
				$i++;
			}
			?>
		</table>
		<?php
		echo "</fieldset>\n";
	}

	// Index I/0 performance
	if ($indexstatsio->recordCount() > 0) {
		$found = true;
		echo "<fieldset>\n";
		?>
		<legend><?= $lang['stridxioperf'] ?></legend>
		<table>
			<tr>
				<th class="data"><?= $lang['strindex'] ?></th>
				<th class="data"><?= $lang['strdisk'] ?></th>
				<th class="data"><?= $lang['strcache'] ?></th>
				<th class="data"><?= $lang['strpercent'] ?></th>
			</tr>
			<?php
			$i = 0;
			while (!$indexstatsio->EOF) {
				$id = (($i % 2) == 0 ? '1' : '2');

				$total = $indexstatsio->fields['idx_blks_hit'] + $indexstatsio->fields['idx_blks_read'];
				if ($total > 0)
					$percentage = round(($indexstatsio->fields['idx_blks_hit'] / $total) * 100);
				else
					$percentage = 0;
				?>
				<tr class="data<?= $id ?>">
					<td><?= $misc->formatVal($indexstatsio->fields['indexrelname']) ?></td>
					<td><?= $misc->formatVal($indexstatsio->fields['idx_blks_read'], 'int4', $shownull) ?></td>
					<td><?= $misc->formatVal($indexstatsio->fields['idx_blks_hit'], 'int4', $shownull) ?></td>
					<td>(<?= $percentage ?><?= $lang['strpercent'] ?>)</td>
				</tr>
				<?php
				$indexstatsio->movenext();
				$i++;
			}
			?>
		</table>
		<?php
		echo "</fieldset>\n";
	}

	if (!$found) {
		// No information found
		$misc->printMsg($lang['strnoinfo']);
	}

}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

$misc->printHeader($lang['strtables'] . ' - ' . $_REQUEST['table'] . ' - ' . $lang['strinfo']);
$misc->printBody();

switch ($action) {
	default:
		doDefault();
		break;
}

$misc->printFooter();
