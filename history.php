<?php

use PhpPgAdmin\Database\ArrayRecordSet;
use PhpPgAdmin\Core\AppContainer;

/**
 * Alternative SQL editing window
 *
 * $Id: history.php,v 1.3 2008/01/10 19:37:07 xzilla Exp $
 */

// Include application functions

include_once('./libraries/bootstrap.php');


function doDefault()
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$onchange = "onchange=\"location.href='history.php?server=' + encodeURI(server.options[server.selectedIndex].value) + '&amp;database=' + encodeURI(database.options[database.selectedIndex].value) + '&amp;'\"";

	$misc->printHeader($lang['strhistory']);
	echo "<body id=\"content\" class=\"popup pt-2\" onload=\"window.focus();\">\n";
	?>

	<form action="history.php" method="post">
		<?php $misc->printConnection($onchange); ?>
	</form><br />
	<?php

	if (!isset($_REQUEST['database'])) {
		?>
		<p><?= $lang['strnodatabaseselected'] ?></p>
		<?php
		return;
	}

	$data = $_SESSION['history'][$_REQUEST['server']][$_REQUEST['database']] ?? null;
	if (!empty($data)) {

		$history = new ArrayRecordSet(array_reverse($data));

		$columns = [
			'query' => [
				'title' => $lang['strsqlhistory'],
				'field' => field('query'),
				'type' => 'sql',
			],
			'paginate' => [
				'title' => $lang['strpaginate'],
				'field' => field('paginate'),
				'type' => 'yesno',
			],
			'actions' => [
				'title' => $lang['stractions'],
			],
		];

		$actions = [
			'run' => [
				'icon' => $misc->icon('Execute'),
				'content' => $lang['strexecute'],
				'attr' => [
					'href' => [
						'url' => 'sql.php',
						'urlvars' => [
							'subject' => 'history',
							'nohistory' => 't',
							'queryid' => field('queryid'),
							'paginate' => field('paginate')
						]
					],
					'target' => 'detail'
				]
			],
			'remove' => [
				'icon' => $misc->icon('Delete'),
				'content' => $lang['strdelete'],
				'attr' => [
					'href' => [
						'url' => 'history.php',
						'urlvars' => [
							'action' => 'confdelhistory',
							'queryid' => field('queryid'),
						]
					]
				]
			]
		];

		$misc->printTable($history, $columns, $actions, 'history-history', $lang['strnohistory']);
	} else {
		?>
		<p class="empty"><?= $lang['strnohistory'] ?></p>
		<?php
	}

	$navlinks = [
		'refresh' => [
			'attr' => [
				'href' => [
					'url' => 'history.php',
					'urlvars' => [
						'action' => 'history',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
					]
				]
			],
			'icon' => $misc->icon('Refresh'),
			'content' => $lang['strrefresh']
		]
	];

	if (!empty($data)) {
		$navlinks['download'] = [
			'attr' => [
				'href' => [
					'url' => 'history.php',
					'urlvars' => [
						'action' => 'download',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database']
					]
				]
			],
			'icon' => $misc->icon('Download'),
			'content' => $lang['strdownload']
		];
		$navlinks['clear'] = [
			'attr' => [
				'href' => [
					'url' => 'history.php',
					'urlvars' => [
						'action' => 'confclearhistory',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database']
					]
				]
			],
			'icon' => $misc->icon('Trashcan'),
			'content' => $lang['strclearhistory']
		];
	}

	$misc->printNavLinks($navlinks, 'history-history', get_defined_vars());
}

function doDelHistory($qid, $confirm)
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	if ($confirm) {
		$misc->printHeader($lang['strhistory']);
		?>

		<body onload="window.focus();">
			<h3><?= $lang['strdelhistory'] ?></h3>
			<p><?= $lang['strconfdelhistory'] ?></p>
			<pre><?= htmlentities($_SESSION['history'][$_REQUEST['server']][$_REQUEST['database']][$qid]['query'], ENT_QUOTES, 'UTF-8') ?></pre>
			<form action="history.php" method="post">
				<input type="hidden" name="action" value="delhistory" />
				<input type="hidden" name="queryid" value="<?= html_esc($qid) ?>" />
				<?= $misc->form ?>
				<input type="submit" name="yes" value="<?= $lang['stryes'] ?>" />
				<input type="submit" name="no" value="<?= $lang['strno'] ?>" />
			</form>
			<?php
	} else
		unset($_SESSION['history'][$_REQUEST['server']][$_REQUEST['database']][$qid]);
}

function doClearHistory($confirm)
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	if ($confirm) {
		$misc->printHeader($lang['strhistory']);
		?>

			<body onload="window.focus();">
				<h3><?= $lang['strclearhistory'] ?></h3>
				<p><?= $lang['strconfclearhistory'] ?></p>
				<form action="history.php" method="post">
					<input type="hidden" name="action" value="clearhistory" />
					<?= $misc->form ?>
					<input type="submit" name="yes" value="<?= $lang['stryes'] ?>" />
					<input type="submit" name="no" value="<?= $lang['strno'] ?>" />
				</form>
				<?php
	} else
		unset($_SESSION['history'][$_REQUEST['server']][$_REQUEST['database']]);
}

function doDownloadHistory()
{
	header('Content-Type: application/download');
	$datetime = date('Ymd_His');
	header("Content-Disposition: attachment; filename=history_{$datetime}.sql");

	foreach ($_SESSION['history'][$_REQUEST['server']][$_REQUEST['database']] as $queries) {
		$query = rtrim($queries['query']);
		echo $query;
		if (!str_ends_with($query, ';'))
			echo ';';
		echo "\n";
	}

	exit;
}

// Main program

$misc = AppContainer::getMisc();

$action = $_REQUEST['action'] ?? '';

switch ($action) {
	case 'confdelhistory':
		doDelHistory($_REQUEST['queryid'], true);
		break;
	case 'delhistory':
		if (isset($_POST['yes']))
			doDelHistory($_REQUEST['queryid'], false);
		doDefault();
		break;
	case 'confclearhistory':
		doClearHistory(true);
		break;
	case 'clearhistory':
		if (isset($_POST['yes']))
			doClearHistory(false);
		doDefault();
		break;
	case 'download':
		doDownloadHistory();
		break;
	default:
		doDefault();
}

// Set the name of the window
$misc->setWindowName('history');
$misc->printFooter();
