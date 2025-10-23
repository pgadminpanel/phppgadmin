<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Database\Actions\TablespaceActions;
use PhpPgAdmin\Gui\ExportFormRenderer;
use PhpPgAdmin\Gui\ImportFormRenderer;

/**
 * Manage databases within a server
 *
 * $Id: all_db.php,v 1.59 2007/10/17 21:40:19 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Display a form for alter and perform actual alter
 */
function doAlter($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);
	$databaseActions = new DatabaseActions($pg);

	if ($confirm) {
		$dbName = $_REQUEST['alterdatabase'] ?? '';
		$misc->printTrail('database');
		$misc->printTitle("{$lang['stralterdatabase']}: $dbName", 'pg.database.alter');

		?>
		<form action="all_db.php" method="post">
			<table>
				<tr>
					<th class="data left required"><?= $lang['strname']; ?></th>
					<td class="data1">
						<input name="newname" size="32" maxlength="<?= $pg->_maxNameLen; ?>"
							value="<?= html_esc($dbName); ?>" />
					</td>
				</tr>

				<?php if ($roleActions->isSuperUser()): ?>
					<?php
					// Fetch all users
					$owner = $databaseActions->getDatabaseOwner($dbName);
					$users = $roleActions->getUsers();
					?>
					<tr>
						<th class="data left required"><?= $lang['strowner']; ?></th>
						<td class="data1">
							<select name="owner">
								<?php
								while (!$users->EOF) {
									$uname = $users->fields['usename'];
									?>
									<option value="<?= html_esc($uname); ?>" <?php if ($uname == $owner)
										  echo ' selected="selected"'; ?>>
										<?= html_esc($uname); ?>
									</option>
									<?php
									$users->moveNext();
								}
								?>
							</select>
						</td>
					</tr>
				<?php endif; ?>

				<?php if ($pg->hasSharedComments()): ?>
					<?php
					$rs = $databaseActions->getDatabaseComment($dbName);
					$comment = $rs->fields['description'] ?? '';
					?>
					<tr>
						<th class="data left"><?= $lang['strcomment']; ?></th>
						<td class="data1">
							<textarea rows="3" cols="32" name="dbcomment"><?= html_esc($comment); ?></textarea>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<input type="hidden" name="action" value="alter" />
			<?= $misc->form; ?>
			<input type="hidden" name="oldname" value="<?= html_esc($dbName); ?>" />
			<p>
				<input type="submit" name="alter" value="<?= $lang['stralter']; ?>" />
				<input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
			</p>
		</form>
		<?php
	} else {
		if (!isset($_POST['owner']))
			$_POST['owner'] = '';
		if (!isset($_POST['dbcomment']))
			$_POST['dbcomment'] = '';
		$status = $databaseActions->alterDatabase(
			$_POST['oldname'],
			$_POST['newname'],
			$_POST['owner'],
			$_POST['dbcomment']
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strdatabasealtered']);
		} else
			doDefault($lang['strdatabasealteredbad']);
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
	$databaseActions = new DatabaseActions($pg);

	if (empty($_REQUEST['dropdatabase']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifydatabasetodrop']);
		exit();
	}

	if ($confirm) {

		$misc->printTrail('database');
		$misc->printTitle($lang['strdrop'], 'pg.database.drop');

		?>
		<form action="all_db.php" method="post">
			<?php
			// If multi drop
			if (isset($_REQUEST['ma'])) {
				foreach ($_REQUEST['ma'] as $v) {
					$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
					?>
					<p><?= sprintf($lang['strconfdropdatabase'], $misc->formatVal($a['database'])); ?></p>
					<input type="hidden" name="dropdatabase[]" value="<?= html_esc($a['database']); ?>" />
					<?php
				}
			} else {
				?>
				<p><?= sprintf($lang['strconfdropdatabase'], $misc->formatVal($_REQUEST['dropdatabase'])); ?></p>
				<input type="hidden" name="dropdatabase" value="<?= html_esc($_REQUEST['dropdatabase']); ?>" />
				<?php
			}
			?>
			<input type="hidden" name="action" value="drop" />
			<?= $misc->form; ?>
			<input type="submit" name="drop" value="<?= $lang['strdrop']; ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
		</form>
		<?php
	} // END confirm
	else {
		//If multi drop
		if (is_array($_REQUEST['dropdatabase'])) {
			$msg = '';
			foreach ($_REQUEST['dropdatabase'] as $d) {
				$status = $databaseActions->dropDatabase($d);
				if ($status == 0)
					$msg .= sprintf('%s: %s<br />', htmlentities($d, ENT_QUOTES, 'UTF-8'), $lang['strdatabasedropped']);
				else {
					doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($d, ENT_QUOTES, 'UTF-8'), $lang['strdatabasedroppedbad']));
					return;
				}
			} // Everything went fine, back to Default page...
			AppContainer::setShouldReloadTree(true);
			doDefault($msg);
		} else {
			$status = $databaseActions->dropDatabase($_POST['dropdatabase']);
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strdatabasedropped']);
			} else
				doDefault($lang['strdatabasedroppedbad']);
		}
	} //END DROP
}// END FUNCTION


/**
 * Displays a screen where they can enter a new database
 */
function doCreate($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$databaseActions = new DatabaseActions($pg);
	$tablespaceActions = new TablespaceActions($pg);

	$misc->printTrail('server');
	$misc->printTitle($lang['strcreatedatabase'], 'pg.database.create');
	$misc->printMsg($msg);

	if (!isset($_POST['formName']))
		$_POST['formName'] = '';
	// Default encoding is that in language file
	if (!isset($_POST['formEncoding'])) {
		$_POST['formEncoding'] = '';
	}
	if (!isset($_POST['formTemplate']))
		$_POST['formTemplate'] = 'template1';
	if (!isset($_POST['formSpc']))
		$_POST['formSpc'] = '';
	if (!isset($_POST['formComment']))
		$_POST['formComment'] = '';

	// Fetch a list of databases in the cluster
	$templatedbs = $databaseActions->getDatabases(false);

	// Fetch all tablespaces from the database
	if ($pg->hasTablespaces())
		$tablespaces = $tablespaceActions->getTablespaces();
	?>
	<form action="all_db.php" method="post">
		<table>
			<tr>
				<th class="data left required"><?= $lang['strname']; ?></th>
				<td class="data1">
					<input name="formName" size="32" maxlength="<?= $pg->_maxNameLen; ?>"
						value="<?= html_esc($_POST['formName']); ?>" />
				</td>
			</tr>

			<tr>
				<th class="data left required"><?= $lang['strtemplatedb']; ?></th>
				<td class="data1">
					<select name="formTemplate">
						<!-- Always offer template0 and template1 -->
						<option value="template0" <?php if ($_POST['formTemplate'] == 'template0')
							echo ' selected="selected"'; ?>>template0</option>
						<option value="template1" <?php if ($_POST['formTemplate'] == 'template1')
							echo ' selected="selected"'; ?>>template1</option>
						<?php
						while (!$templatedbs->EOF) {
							$dbname = html_esc($templatedbs->fields['datname']);
							if ($dbname != 'template1') {
								// filter out for $conf[show_system] users so we don't get duplicates
								?>
								<option value="<?= $dbname; ?>" <?php if ($dbname == $_POST['formTemplate'])
									  echo ' selected="selected"'; ?>><?= $dbname; ?></option>
								<?php
							}
							$templatedbs->moveNext();
						}
						?>
					</select>
				</td>
			</tr>

			<!-- ENCODING -->
			<tr>
				<th class="data left required"><?= $lang['strencoding']; ?></th>
				<td class="data1">
					<select name="formEncoding">
						<option value=""></option>
						<?php
						foreach ($pg->codemap as $key) {
							?>
							<option value="<?= html_esc($key); ?>" <?php if ($key == $_POST['formEncoding'])
								  echo ' selected="selected"'; ?>>
								<?= $misc->formatVal($key); ?>
							</option>
							<?php
						}
						?>
					</select>
				</td>
			</tr>

			<?php if ($pg->hasDatabaseCollation()): ?>
				<?php
				if (!isset($_POST['formCollate']))
					$_POST['formCollate'] = '';
				if (!isset($_POST['formCType']))
					$_POST['formCType'] = '';
				?>
				<!-- LC_COLLATE -->
				<tr>
					<th class="data left"><?= $lang['strcollation']; ?></th>
					<td class="data1">
						<input name="formCollate" value="<?= html_esc($_POST['formCollate']); ?>" />
					</td>
				</tr>

				<!-- LC_CTYPE -->
				<tr>
					<th class="data left"><?= $lang['strctype']; ?></th>
					<td class="data1">
						<input name="formCType" value="<?= html_esc($_POST['formCType']); ?>" />
					</td>
				</tr>
			<?php endif; ?>

			<!-- Tablespace (if there are any) -->
			<?php if ($pg->hasTablespaces() && $tablespaces->recordCount() > 0): ?>
				<tr>
					<th class="data left"><?= $lang['strtablespace']; ?></th>
					<td class="data1">
						<select name="formSpc">
							<!-- Always offer the default (empty) option -->
							<option value="" <?php if ($_POST['formSpc'] == '')
								echo ' selected="selected"'; ?>></option>
							<?php
							// Display all other tablespaces
							while (!$tablespaces->EOF) {
								$spcname = html_esc($tablespaces->fields['spcname']);
								?>
								<option value="<?= $spcname; ?>" <?php if ($spcname == $_POST['formSpc'])
									  echo ' selected="selected"'; ?>>
									<?= $spcname; ?>
								</option>
								<?php
								$tablespaces->moveNext();
							}
							?>
						</select>
					</td>
				</tr>
			<?php endif; ?>

			<!-- Comments (if available) -->
			<?php if ($pg->hasSharedComments()): ?>
				<tr>
					<th class="data left"><?= $lang['strcomment']; ?></th>
					<td>
						<textarea name="formComment" rows="3" cols="32"><?= html_esc($_POST['formComment']); ?></textarea>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<p>
			<input type="hidden" name="action" value="save_create" />
			<?= $misc->form; ?>
			<input type="submit" value="<?= $lang['strcreate']; ?>" />
			<input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
		</p>
	</form>
	<?php
}

/**
 * Render import form for server scope
 */
function doImport($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printTrail('server');
	$misc->printTabs('server', 'import');
	$misc->printMsg($msg);

	// Check file uploads enabled
	if (!ini_get('file_uploads')) {
		echo "<p>{$lang['strnouploads']}</p>\n";
		return;
	}

	$import = new ImportFormRenderer();
	$import->renderImportForm('server', []);
}

/**
 * Actually creates the new view in the database
 */
function doSaveCreate()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$databaseActions = new DatabaseActions($pg);

	// Default tablespace to null if it isn't set
	if (!isset($_POST['formSpc']))
		$_POST['formSpc'] = null;

	// Default comment to blank if it isn't set
	if (!isset($_POST['formComment']))
		$_POST['formComment'] = null;

	// Default collate to blank if it isn't set
	if (!isset($_POST['formCollate']))
		$_POST['formCollate'] = null;

	// Default ctype to blank if it isn't set
	if (!isset($_POST['formCType']))
		$_POST['formCType'] = null;

	// Check that they've given a name and a definition
	if ($_POST['formName'] == '')
		doCreate($lang['strdatabaseneedsname']);
	else {
		$status = $databaseActions->createDatabase(
			$_POST['formName'],
			$_POST['formEncoding'],
			$_POST['formSpc'],
			$_POST['formComment'],
			$_POST['formTemplate'],
			$_POST['formCollate'],
			$_POST['formCType']
		);
		if ($status == 0) {
			AppContainer::setShouldReloadTree(true);
			doDefault($lang['strdatabasecreated']);
		} else
			doCreate($lang['strdatabasecreatedbad']);
	}
}

/**
 * Displays options for cluster download
 */
function doExport($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$pg = AppContainer::getPostgres();

	$misc->printTrail('server');
	$misc->printTabs('server', 'export');
	$misc->printMsg($msg);

	// Get list of databases for database selection fieldset
	$databaseNames = [];
	$databaseActions = new DatabaseActions($pg);
	$databases = $databaseActions->getDatabases(null, true);
	while (!$databases->EOF) {
		$databaseNames[] = $databases->fields['datname'];
		$databases->moveNext();
	}

	// Use the unified DumpRenderer for the export form
	$exportRenderer = new ExportFormRenderer();
	$exportRenderer->renderExportForm('server', [
		'name' => 'databases',
		//'icon' => 'Database',
		//'objects' => $databaseNames,
		'objects_by_type' => [
			'databases' => $databaseNames,
		],
	]);
}

/**
 * Show default list of databases in the server
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$databaseActions = new DatabaseActions($pg);

	$misc->printTrail('server');
	$misc->printTabs('server', 'databases');
	$misc->printMsg($msg);

	$databases = $databaseActions->getDatabases();

	$columns = [
		'database' => [
			'title' => $lang['strdatabase'],
			'field' => field('datname'),
			'url' => "redirect.php?subject=database&amp;{$misc->href}&amp;",
			'vars' => ['database' => 'datname'],
			'icon' => $misc->icon('Database'),
			'class' => 'no-wrap',
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('datowner'),
		],
		'encoding' => [
			'title' => $lang['strencoding'],
			'field' => field('datencoding'),
		],
		'lc_collate' => [
			'title' => $lang['strcollation'],
			'field' => field('datcollate'),
		],
		'lc_ctype' => [
			'title' => $lang['strctype'],
			'field' => field('datctype'),
		],
		'tablespace' => [
			'title' => $lang['strtablespace'],
			'field' => field('tablespace'),
		],
		'dbsize' => [
			'title' => $lang['strsize'],
			'field' => field('dbsize'),
			'type' => 'prettysize',
			'class' => 'text-end',
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('datcomment'),
		],
	];

	$footer = [
		'database' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strdatabases']}",
			'colspan' => 2,
		],
		'encoding' => [
			'text' => $lang['strtotal'],
			'colspan' => 4,
		],
		'dbsize' => [
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
			'keycols' => ['database' => 'datname'],
			'url' => 'all_db.php',
			'default' => null,
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'all_db.php',
					'urlvars' => [
						'subject' => 'database',
						'action' => 'confirm_drop',
						'dropdatabase' => field('datname')
					]
				]
			],
			'multiaction' => 'confirm_drop',
		],
		'privileges' => [
			'icon' => $misc->icon('Privileges'),
			'content' => $lang['strprivileges'],
			'attr' => [
				'href' => [
					'url' => 'privileges.php',
					'urlvars' => [
						'subject' => 'database',
						'database' => field('datname')
					]
				]
			]
		]
	];
	if ($pg->hasAlterDatabase()) {
		$actions['alter'] = [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'all_db.php',
					'urlvars' => [
						'subject' => 'database',
						'action' => 'confirm_alter',
						'alterdatabase' => field('datname')
					]
				]
			]
		];
	}

	if (!$pg->hasTablespaces())
		unset($columns['tablespace']);
	if (!$pg->hasServerAdminFuncs())
		unset($columns['dbsize']);
	if (!$pg->hasDatabaseCollation())
		unset($columns['lc_collate'], $columns['lc_ctype']);
	if (!isset($pg->privlist['database']))
		unset($actions['privileges']);

	$misc->printTable(
		$databases,
		$columns,
		$actions,
		'all_db-databases',
		$lang['strnodatabases'],
		null,
		$footer
	);

	$navlinks = [
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'all_db.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server']
					]
				]
			],
			'icon' => $misc->icon('CreateDatabase'),
			'content' => $lang['strcreatedatabase']
		]
	];
	$misc->printNavLinks($navlinks, 'all_db-databases', get_defined_vars());
}

function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$databaseActions = new DatabaseActions($pg);

	$databases = $databaseActions->getDatabases();

	$reqvars = $misc->getRequestVars('database');

	$attrs = [
		'text' => field('datname'),
		'icon' => 'Database',
		'toolTip' => field('datcomment'),
		'action' => url(
			'redirect.php',
			$reqvars,
			['database' => field('datname')]
		),
		'branch' => url(
			'database.php',
			$reqvars,
			[
				'action' => 'tree',
				'database' => field('datname')
			]
		),
	];

	$misc->printTree($databases, $attrs, 'databases');
	exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
	doTree();

$misc->printHeader($lang['strdatabases']);
$misc->printBody();

switch ($action) {
	case 'export':
		doExport();
		break;
	case 'import':
		doImport();
		break;
	case 'save_create':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doSaveCreate();
		break;
	case 'create':
		doCreate();
		break;
	case 'drop':
		if (isset($_REQUEST['drop']))
			doDrop(false);
		else
			doDefault();
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	case 'alter':
		if (isset($_POST['oldname']) && isset($_POST['newname']) && !isset($_POST['cancel']))
			doAlter(false);
		else
			doDefault();
		break;
	case 'confirm_alter':
		doAlter(true);
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
