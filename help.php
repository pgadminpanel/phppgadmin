<?php

/**
 * Help page redirection/browsing.
 *
 * $Id: help.php,v 1.3 2006/12/31 16:21:26 soranzo Exp $
 */


// Include application functions
use PhpPgAdmin\Core\AppContainer;

include_once('./libraries/bootstrap.php');

/**
 * Fetch a URL (or array of URLs) for a given help page.
 */
function getHelp($help)
{
	$pg = AppContainer::getPostgres();
	$conf = AppContainer::getConf();

	$help_base = sprintf($conf['help_base'], (string) $pg->major_version);

	// Get help pages
	$pages = include __DIR__ . '/help-pages.inc.php';

	if (isset($pages[$help])) {
		if (is_array($pages[$help])) {
			$urls = [];
			foreach ($pages[$help] as $link) {
				$urls[] = $help_base . $link;
			}
			return $urls;
		} else
			return $help_base . $pages[$help];
	} else
		return null;
}

function doDefault()
{
	$lang = AppContainer::getLang();

	if (isset($_REQUEST['help'])) {
		$url = getHelp($_REQUEST['help']);

		if (is_array($url)) {
			doChoosePage($url);
			return;
		}

		if ($url) {
			header("Location: $url");
			exit;
		}
	}

	doBrowse($lang['strinvalidhelppage']);
}

function doBrowse($msg = '')
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printHeader($lang['strhelppagebrowser']);
	$misc->printBody();

	$misc->printTitle($lang['strselecthelppage']);

	echo $misc->printMsg($msg);

	echo "<dl>\n";

	$pages = include __DIR__ . '/help-pages.inc.php';
	foreach ($pages as $page => $dummy) {
		echo "<dt>{$page}</dt>\n";

		$urls = getHelp($page);
		if (!is_array($urls))
			$urls = [$urls];
		foreach ($urls as $url) {
			echo "<dd><a href=\"{$url}\">{$url}</a></dd>\n";
		}
	}

	echo "</dl>\n";

	$misc->printFooter();
}

function doChoosePage($urls)
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	$misc->printHeader($lang['strhelppagebrowser']);
	$misc->printBody();

	$misc->printTitle($lang['strselecthelppage']);

	echo "<ul>\n";
	foreach ($urls as $url) {
		echo "<li><a href=\"{$url}\">{$url}</a></li>\n";
	}
	echo "</ul>\n";

	$misc->printFooter();
}

// Main program

$action = $_REQUEST['action'] ?? '';

switch ($action) {
	case 'browse':
		doBrowse();
		break;
	default:
		doDefault();
		break;
}
