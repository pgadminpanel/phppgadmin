<?php

use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Html\XHtmlButton;
use PhpPgAdmin\Html\XHtmlOption;
use PhpPgAdmin\Html\XHtmlSelect;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\TablespaceActions;

/**
 * List indexes on a table
 *
 * $Id: indexes.php,v 1.46 2008/01/08 22:50:29 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Show confirmation of cluster index and perform actual cluster
 */
function doClusterIndex($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$indexActions = new IndexActions($pg);
	$adminActions = new AdminActions($pg);

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	if ($confirm) {
		// Default analyze to on
		$_REQUEST['analyze'] = true;

		$misc->printTrail('index');
		$misc->printTitle($lang['strclusterindex'], 'pg.index.cluster');
		?>
		<p><?= sprintf($lang['strconfcluster'], $misc->formatVal($_REQUEST['index'])) ?></p>

		<form action="indexes.php" method="post">
			<p>
				<input type="checkbox" id="analyze" name="analyze" <?= isset($_REQUEST['analyze']) ? 'checked="checked"' : '' ?> />
				<label for="analyze"><?= $lang['stranalyze'] ?></label>
			</p>
			<input type="hidden" name="action" value="cluster_index" />
			<input type="hidden" name="subject" value="<?= htmlspecialchars($subject) ?>" />
			<input type="hidden" name="<?= htmlspecialchars($subject) ?>" value="<?= htmlspecialchars($table) ?>" />
			<input type="hidden" name="index" value="<?= html_esc($_REQUEST['index']) ?>" />
			<?= $misc->form ?>
			<input type="submit" name="cluster" value="<?= $lang['strclusterindex'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</form>
		<?php
	} else {
		$status = $indexActions->clusterIndex($table, $_POST['index']);
		if ($status == 0)
			if (isset($_POST['analyze'])) {
				$status = $adminActions->analyzeDB($table);
				if ($status == 0)
					doDefault($lang['strclusteredgood'] . ' ' . $lang['stranalyzegood']);
				else
					doDefault($lang['stranalyzebad']);
			} else
				doDefault($lang['strclusteredgood']);
		else
			doDefault($lang['strclusteredbad']);
	}

}

function doReindex()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$indexActions = new IndexActions($pg);

	$status = $indexActions->reindex('INDEX', $_REQUEST['index']);
	if ($status == 0)
		doDefault($lang['strreindexgood']);
	else
		doDefault($lang['strreindexbad']);
}

/**
 * Displays a screen where they can enter a new index
 */
function doCreateIndex($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$tableActions = new TableActions($pg);
	$tableSpaceActions = new TablespaceActions($pg);

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	if (!isset($_POST['formIndexName']))
		$_POST['formIndexName'] = '';
	if (!isset($_POST['formIndexType']))
		$_POST['formIndexType'] = null;
	if (!isset($_POST['formCols']))
		$_POST['formCols'] = '';
	if (!isset($_POST['formWhere']))
		$_POST['formWhere'] = '';
	if (!isset($_POST['formSpc']))
		$_POST['formSpc'] = '';

	$attrs = $tableActions->getTableAttributes($table);
	// Fetch all tablespaces from the database
	if ($pg->hasTablespaces())
		$tablespaces = $tableSpaceActions->getTablespaces();

	$misc->printTrail($subject);
	$misc->printTitle($lang['strcreateindex'], 'pg.index.create');
	$misc->printMsg($msg);

	$selColumns = new XHtmlSelect("TableColumnList", true, 10);
	$selColumns->set_style("width: 10em;");

	if ($attrs->recordCount() > 0) {
		while (!$attrs->EOF) {
			$XHTML_Option = new XHtmlOption($attrs->fields['attname']);
			$selColumns->add($XHTML_Option);
			$attrs->moveNext();
		}
	}

	$selIndex = new XHtmlSelect("IndexColumnList[]", true, 10);
	$selIndex->set_style("width: 10em;");
	$selIndex->set_attribute("id", "IndexColumnList");
	$buttonAdd = new XHtmlButton("add", ">>");
	$buttonAdd->set_attribute("onclick", "buttonPressed(this);");
	$buttonAdd->set_attribute("type", "button");
	$buttonRemove = new XHtmlButton("remove", "<<");
	$buttonRemove->set_attribute("onclick", "buttonPressed(this);");
	$buttonRemove->set_attribute("type", "button");

	?>
	<form onsubmit="doSelectAll();" name="formIndex" action="indexes.php" method="post">
		<table>
			<tr>
				<th class="data required" colspan="3"><?= $lang['strindexname'] ?></th>
			</tr>
			<tr>
				<td class="data1" colspan="3">
					<input type="text" name="formIndexName" id="formIndexName" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						data-suffix="idx" value="<?= html_esc($_POST['formIndexName']) ?>" />
				</td>
			</tr>
			<tr>
				<th class="data"><?= $lang['strtablecolumnlist'] ?></th>
				<th class="data">&nbsp;</th>
				<th class="data required"><?= $lang['strindexcolumnlist'] ?></th>
			</tr>
			<tr>
				<td class="data1"><?= $selColumns->fetch() ?></td>
				<td class="data1"><?= $buttonRemove->fetch() . $buttonAdd->fetch() ?></td>
				<td class="data1"><?= $selIndex->fetch() ?></td>
			</tr>
		</table>

		<table>
			<tr>
				<th class="data left required" scope="row"><?= $lang['strindextype'] ?></th>
				<td class="data1">
					<select name="formIndexType">
						<?php foreach (IndexActions::INDEX_TYPES as $v): ?>
							<option value="<?= html_esc($v) ?>" <?= ($v == $_POST['formIndexType']) ? 'selected="selected"' : '' ?>><?= html_esc($v) ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th class="data left" scope="row"><label for="formUnique"><?= $lang['strunique'] ?></label></th>
				<td class="data1"><input type="checkbox" id="formUnique" name="formUnique" <?= isset($_POST['formUnique']) ? 'checked="checked"' : '' ?> /></td>
			</tr>
			<tr>
				<th class="data left" scope="row"><?= $lang['strwhere'] ?></th>
				<td class="data1">(<input name="formWhere" size="32" maxlength="<?= $pg->_maxNameLen ?>"
						value="<?= html_esc($_POST['formWhere']) ?>" />)</td>
			</tr>

			<?php if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0): ?>
				<tr>
					<th class="data left"><?= $lang['strtablespace'] ?></th>
					<td class="data1">
						<select name="formSpc">
							<option value="" <?= ($_POST['formSpc'] == '') ? 'selected="selected"' : '' ?>></option>
							<?php while (!$tablespaces->EOF):
								$spcname = html_esc($tablespaces->fields['spcname']);
								?>
								<option value="<?= $spcname ?>" <?= ($spcname == $_POST['formSpc']) ? 'selected="selected"' : '' ?>>
									<?= $spcname ?>
								</option>
								<?php $tablespaces->moveNext(); endwhile; ?>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ($pg->hasConcurrentIndexBuild()): ?>
				<tr>
					<th class="data left" scope="row"><label for="formConcur"><?= $lang['strconcurrently'] ?></label></th>
					<td class="data1"><input type="checkbox" id="formConcur" name="formConcur" <?= isset($_POST['formConcur']) ? 'checked="checked"' : '' ?> /></td>
				</tr>
			<?php endif; ?>
		</table>

		<p>
			<input type="hidden" name="action" value="save_create_index" />
			<?= $misc->form ?>
			<input type="hidden" name="subject" value="<?= htmlspecialchars($subject) ?>" />
			<input type="hidden" name="<?= htmlspecialchars($subject) ?>" value="<?= htmlspecialchars($table) ?>" />
			<input type="submit" value="<?= $lang['strcreate'] ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
		</p>
	</form>
	<?php
}

/**
 * Actually creates the new index in the database
 * @@ Note: this function can't handle columns with commas in them
 */
function doSaveCreateIndex()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$indexActions = new IndexActions($pg);

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	// Handle databases that don't have partial indexes
	if (!isset($_POST['formWhere']))
		$_POST['formWhere'] = '';
	// Default tablespace to null if it isn't set
	if (!isset($_POST['formSpc']))
		$_POST['formSpc'] = null;

	// Check that they've given a name and at least one column
	if ($_POST['formIndexName'] == '')
		doCreateIndex($lang['strindexneedsname']);
	elseif (!isset($_POST['IndexColumnList']) || $_POST['IndexColumnList'] == '')
		doCreateIndex($lang['strindexneedscols']);
	else {
		$status = $indexActions->createIndex(
			$_POST['formIndexName'],
			$table,
			$_POST['IndexColumnList'],
			$_POST['formIndexType'],
			isset($_POST['formUnique']),
			$_POST['formWhere'],
			$_POST['formSpc'],
			isset($_POST['formConcur'])
		);
		if ($status == 0)
			doDefault($lang['strindexcreated']);
		else
			doCreateIndex($lang['strindexcreatedbad']);
	}
}

/**
 * Perform actual drop of index
 */
function doExecDropIndex()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$indexActions = new IndexActions($pg);

	$status = $indexActions->dropIndex($_POST['index'], isset($_POST['cascade']));
	if ($status == 0)
		doDefault($lang['strindexdropped']);
	else
		doDefault($lang['strindexdroppedbad']);
	return;
}

/**
 * Show confirmation of drop index
 */
function doConfirmDropIndex()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	$misc->printTrail('index');
	$misc->printTitle($lang['strdrop'], 'pg.index.drop');
	?>
	<p><?= sprintf($lang['strconfdropindex'], $misc->formatVal($_REQUEST['index'])) ?></p>

	<form action="indexes.php" method="post">
		<input type="hidden" name="action" value="drop_index" />
		<input type="hidden" name="subject" value="<?= htmlspecialchars($subject) ?>" />
		<input type="hidden" name="<?= htmlspecialchars($subject) ?>" value="<?= htmlspecialchars($table) ?>" />
		<input type="hidden" name="index" value="<?= html_esc($_REQUEST['index']) ?>" />
		<?= $misc->form ?>
		<p><input type="checkbox" id="cascade" name="cascade" /> <label for="cascade"><?= $lang['strcascade'] ?></label></p>
		<input type="submit" name="drop" value="<?= $lang['strdrop'] ?>" />
		<input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
	</form>
	<?php
}

function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$indexActions = new IndexActions($pg);

	$indPre = function ($rowdata, $actions) use ($pg, $lang) {
		if ($pg->phpBool($rowdata->fields['indisprimary'])) {
			$rowdata->fields['+constraints'] = $lang['strprimarykey'];
			$actions['drop']['disable'] = true;
		} elseif ($pg->phpBool($rowdata->fields['indisunique'])) {
			$rowdata->fields['+constraints'] = $lang['struniquekey'];
			$actions['drop']['disable'] = true;
		} else
			$rowdata->fields['+constraints'] = '';

		return $actions;
	};

	$subject = $_REQUEST['subject'] ?? 'table';
	$table = $_REQUEST[$subject];

	$misc->printTrail($subject);
	$misc->printTabs($subject, 'indexes');
	$misc->printMsg($msg);

	$indexes = $indexActions->getIndexes($table);
	$columns = [
		'index' => [
			'title' => $lang['strname'],
			'field' => field('indname'),
			'class' => 'nowrap',
			'icon' => $misc->icon('Index'),
		],
		'definition' => [
			'title' => $lang['strdefinition'],
			'field' => field('inddef'),
			'type' => 'sql',
		],
		'constraints' => [
			'title' => $lang['strconstraints'],
			'field' => field('+constraints'),
			'type' => 'verbatim',
			//'params' => ['align' => 'center'],
		],
		'clustered' => [
			'title' => $lang['strclustered'],
			'field' => field('indisclustered'),
			'type' => 'yesno',
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('idxcomment'),
		],
	];

	$actions = [
		'cluster' => [
			'icon' => $misc->icon('Cluster'),
			'content' => $lang['strclusterindex'],
			'attr' => [
				'href' => [
					'url' => 'indexes.php',
					'urlvars' => [
						'action' => 'confirm_cluster_index',
						'subject' => $subject,
						$subject => $table,
						'index' => field('indname')
					]
				]
			]
		],
		'reindex' => [
			'icon' => $misc->icon('Refresh'),
			'content' => $lang['strreindex'],
			'attr' => [
				'href' => [
					'url' => 'indexes.php',
					'urlvars' => [
						'action' => 'reindex',
						'subject' => $subject,
						$subject => $table,
						'index' => field('indname')
					]
				]
			]
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'indexes.php',
					'urlvars' => [
						'action' => 'confirm_drop_index',
						'subject' => $subject,
						$subject => $table,
						'index' => field('indname')
					]
				]
			]
		]
	];

	$schemaActions = new SchemaActions($pg);
	$isSystemSchema = $schemaActions->isSystemSchema($_REQUEST['schema']);
	if ($isSystemSchema) {
		$actions = [];
		unset($columns['actions']);
	}

	$misc->printTable($indexes, $columns, $actions, 'indexes-indexes', $lang['strnoindexes'], $indPre);

	if (!$isSystemSchema) {
		$misc->printNavLinks([
			'create' => [
				'attr' => [
					'href' => [
						'url' => 'indexes.php',
						'urlvars' => [
							'action' => 'create_index',
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
							'subject' => $subject,
							$subject => $table,
						]
					]
				],
				'icon' => $misc->icon('CreateIndex'),
				'content' => $lang['strcreateindex']
			]
		], 'indexes-indexes', get_defined_vars());
	}
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$indexActions = new IndexActions($pg);

	$table = $_REQUEST['table'] ?? $_REQUEST['view'] ?? '';

	$indexes = $indexActions->getIndexes($table);

	//$reqvars = $misc->getRequestVars('table');

	$getIcon = function ($f) {
		if ($f['indisprimary'] == 't')
			return 'PrimaryKey';
		if ($f['indisunique'] == 't')
			return 'UniqueConstraint';
		return 'Index';
	};

	$attrs = [
		'text' => field('indname'),
		'icon' => callback($getIcon),
	];

	$misc->printTree($indexes, $attrs, 'indexes');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

$table = $_REQUEST['table'] ?? $_REQUEST['view'] ?? '';

if ($action == 'tree')
	doTree();

$scripts = "<script defer src=\"js/indexes.js\" type=\"text/javascript\"></script>";
$misc->printHeader($table . ' - ' . $lang['strindexes'], $scripts);

$misc->printBody();

switch ($action) {
	case 'cluster_index':
		if (isset($_POST['cluster']))
			doClusterIndex(false);
		else
			doDefault();
		break;
	case 'confirm_cluster_index':
		doClusterIndex(true);
		break;
	case 'reindex':
		doReindex();
		break;
	case 'save_create_index':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doSaveCreateIndex();
		break;
	case 'create_index':
		doCreateIndex();
		break;
	case 'drop_index':
		if (isset($_POST['drop']))
			doExecDropIndex();
		else
			doDefault();
		break;
	case 'confirm_drop_index':
		doConfirmDropIndex();
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
