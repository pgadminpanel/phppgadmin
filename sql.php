<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Import\SqlParser;
use PhpPgAdmin\Database\QueryResult;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\ScriptActions;

/**
 * Process an arbitrary SQL script of statements!  
 *
 * $Id: sql.php,v 1.43 2008/01/10 20:19:27 xzilla Exp $
 */

// Prevent timeouts on large scripts (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Render query results to HTML
 * @param QueryResult $result The wrapped query result
 * @param string $query The SQL query that was executed
 * @param string|null $lineInfo Optional line info for error reporting
 */
function renderQueryResult($result, $query, $lineInfo = null)
{
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$pg = AppContainer::getPostgres();

	/*
	if (!$result->isSuccess) {
		echo nl2br(html_esc($result->errorMsg)), "<br/>\n";
		return;
	}
	*/

	// Get ADODB-compatible adapter (handles both ADODB and pg_* results)
	$rs = $result->getAdapterForResults();

	echo "<div class=\"query-box mb-2\">\n";
	echo "<pre class=\"sql-viewer\">" . htmlspecialchars($query) . "</pre>\n";
	if (!$result->isSuccess) {
		echo "<div class=\"error\">", nl2br(html_esc($result->errorMsg)), "</div>\n";
	}
	echo "<div class=\"query-stats\">\n";
	if ($lineInfo !== null) {
		echo "<span class=\"mr-1\">", html_esc($lineInfo), ",</span>";
	}
	if ($result->affectedRows() > 0) {
		echo $result->affectedRows(), " {$lang['strrowsaff']}\n";
	} else {
		echo $result->recordCount(), " {$lang['strrows']}\n";
	}
	echo "</div>\n";
	echo "</div>\n";

	if (!$result->isSuccess || $rs === null || $result->recordCount() <= 0) {
		return;
	}

	$pg->conn->SetFetchMode(ADODB_FETCH_ASSOC);

	$typeNames = [];
	for ($i = 0; $i < $rs->fieldCount(); $i++) {
		$finfo = $rs->fetchField($i);
		$typeNames[] = $finfo->type;
	}
	$typeActions = new TypeActions($pg);
	$typesMeta = $typeActions->getTypeMetasByNames($typeNames);

	echo "<table class=\"data query-result mb-1\">\n";
	echo "<colgroup>\n";
	foreach ($rs->fields as $k => $v) {
		$finfo = $rs->fetchField($k);
		echo "<col class=\"{$finfo->type}\" />\n";
	}
	echo "</colgroup>\n";
	echo "<thead class=\"sticky-thead\">\n";
	echo "<tr>\n";
	foreach ($rs->fields as $k => $v) {
		$finfo = $rs->fetchField($k);
		$typeMeta = $typesMeta[$finfo->type] ?? null;
		$isLargeType = $typeMeta !== null && $typeActions->isLargeTypeMeta($typeMeta);
		$class = 'data';
		if ($isLargeType) {
			$class .= ' large_type';
		}
		echo "<th class=\"{$class}\">", $misc->formatVal($finfo->name), "</th>\n";
	}
	echo "</tr>\n";
	echo "</thead>\n";
	echo "<tbody>\n";
	$i = 0;
	while (!$rs->EOF) {
		$id = (($i & 1) == 0 ? '1' : '2');
		echo "<tr class=\"data{$id} data-row\">\n";
		foreach ($rs->fields as $k => $v) {
			$finfo = $rs->fetchField($k);
			$isArray = substr_compare($finfo->type, '_', 0, 1) === 0;
			$class = $isArray ? "array" : "no-array";
			$hasLineBreak = isset($v) && str_contains($v, "\n");
			$class .= $hasLineBreak ? " line-break" : " no-line-break";
			if (!$hasLineBreak && is_string($v)) {
				if (strlen($v) > 2100) {
					$class .= " full-width";
				} elseif (strlen($v) > 1050) {
					$class .= " large-width";
				} elseif (strlen($v) > 500) {
					$class .= " medium-width";
				} elseif (strlen($v) > 100) {
					$class .= " small-width";
				}
			}
			echo "<td class=\"auto-wrap field $finfo->type $class\">\n";
			echo "<div class=\"wrapper\">\n";
			echo $misc->formatVal($v, $finfo->type, array('null' => true));
			echo "</div>\n";
			echo "</td>\n";
		}
		echo "</tr>\n";
		$rs->moveNext();
		$i++;
	}
	echo "</tbody>\n";
	echo "</table>\n";
	//echo "<p>", $i, " {$lang['strrows']}</p>\n";
}

/**
 * This is a callback function to display the result of each separate query
 * @param string $query The SQL query that was executed
 * @param QueryResult $result The wrapped query result from script executor
 * @param int $lineno The line number in the script file
 */
$sqlCallback = function ($query, $result, $lineno) {
	$lineInfo = $_FILES['script']['name'] . ':' . $lineno;
	renderQueryResult($result, $query, $lineInfo);
};

$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();
$pg = AppContainer::getPostgres();
$schemaActions = new SchemaActions($pg);

$subject = $_REQUEST['subject'] ?? '';

// We need to store the query in a session for editing purposes
// We avoid GPC vars to avoid truncating long queries
if ($subject == 'history') {
	// Or maybe we came from the history popup
	$_SESSION['sqlquery'] = $_SESSION['history'][$_REQUEST['server']][$_REQUEST['database']][$_GET['queryid']]['query'];
} elseif (isset($_REQUEST['query'])) {
	// Or maybe we came from an sql form
	$_SESSION['sqlquery'] = $_REQUEST['query'];
} else {
	echo "could not find the query!!";
	exit;
}

$isUpload = isset($_FILES['script']) && $_FILES['script']['size'] > 0;
$canPaginate = !$isUpload;
$hasNonReadQueries = $isUpload;
if (!$isUpload) {
	$script = trim($_SESSION['sqlquery']);
	if (substr($script, -1) !== ';') {
		$script .= ';';
	}
	$result = SqlParser::parseFromString($script);
	$statements = array_column(
		array_filter(
			$result['items'],
			function ($item) {
				return $item['type'] === 'statement';
			}
		),
		'content'
	);
	$readQueryCount = 0;
	foreach ($statements as $stmt) {
		if (is_result_set_query($stmt)) {
			$readQueryCount++;
		} else {
			$hasNonReadQueries = true;
		}
	}
	$canPaginate = ($readQueryCount === 1);
}

// Pagination maybe set by a get link that has it as FALSE,
// if that's the case, unset the variable.

/*
if (isset($_REQUEST['paginate']) && $_REQUEST['paginate'] == 'f') {
	unset($_REQUEST['paginate']);
	unset($_POST['paginate']);
	unset($_GET['paginate']);
}
*/
$paginate = $_REQUEST['paginate'] ?? '';
if ($isUpload) {
	$paginate = 'f';
}
if (empty($paginate)) {
	$paginate = $canPaginate ? 't' : 'f';
}

// Check to see if pagination has been specified. In that case, send to display
// script for pagination
if ($paginate == 't') {
	require './display.php';
	exit;
}

$misc->printHeader($lang['strqueryresults']);
$misc->printBody();
$misc->printTrail('database');
$misc->printTitle($lang['strqueryresults']);

// Set the schema search path
if (isset($_REQUEST['search_path'])) {
	if ($schemaActions->setSearchPath(array_map('trim', explode(',', $_REQUEST['search_path']))) != 0) {
		$misc->printFooter();
		exit;
	}
}

// May as well try to time the query
$start_time = microtime(true);

// Execute the query.  If it's a script upload, special handling is necessary
if ($isUpload) {
	// Execute the script via our ScriptActions class
	$scriptActions = new ScriptActions($pg);
	$scriptActions->executeScript('script', $sqlCallback);
} else {
	// Execute each individual statement
	foreach ($statements as $stmt) {
		// Set fetch mode to NUM so that duplicate field names are properly returned
		$pg->conn->setFetchMode(ADODB_FETCH_NUM);
		$rs = $pg->conn->Execute($stmt);
		$errorMsg = $rs === false ? $pg->conn->ErrorMsg() : '';

		// Wrap result for consistent handling
		$result = QueryResult::fromADORecordSet($rs, $errorMsg);

		// Render the result
		renderQueryResult($result, $stmt);
	}

	// Request was run, saving it in history
	if (!isset($_REQUEST['nohistory'])) {
		$misc->saveSqlHistory($_SESSION['sqlquery'], false);
	}

}

// May as well try to time the query
$end_time = microtime(true);
$duration = number_format(($end_time - $start_time) * 1000, 3);

// Reload the tree as we may have made schema changes
if ($hasNonReadQueries) {
	AppContainer::setShouldReloadTree(true);
}

// Display duration
if ($duration !== null) {
	echo "<p>", sprintf($lang['strruntime'], $duration), "</p>\n";
}

echo "<p>{$lang['strsqlexecuted']}</p>\n";

$navlinks = array();
$fields = array(
	'server' => $_REQUEST['server'],
	'database' => $_REQUEST['database'],
);

if (isset($_REQUEST['schema']))
	$fields['schema'] = $_REQUEST['schema'];

// Return
if (isset($_REQUEST['return'])) {
	$urlvars = $misc->getSubjectParams($_REQUEST['return']);
	$navlinks['back'] = array(
		'attr' => array(
			'href' => array(
				'url' => $urlvars['url'],
				'urlvars' => $urlvars['params']
			)
		),
		'content' => $lang['strback']
	);
}

// Edit		
$navlinks['alter'] = array(
	'attr' => array(
		'href' => array(
			'url' => 'database.php',
			'urlvars' => array_merge($fields, array(
				'action' => 'sql',
				'paginate' => $paginate,
			))
		)
	),
	'icon' => $misc->icon('SqlEditor'),
	'content' => $lang['streditsql']
);

// Create view and download
if (isset($_SESSION['sqlquery']) && isset($rs) && is_object($rs) && $rs->recordCount() > 0) {
	// Report views don't set a schema, so we need to disable create view in that case
	if (isset($_REQUEST['schema'])) {
		$navlinks['createview'] = array(
			'attr' => array(
				'href' => array(
					'url' => 'views.php',
					'urlvars' => array_merge($fields, array(
						'action' => 'create'
					))
				)
			),
			'content' => $lang['strcreateview']
		);
	}

	if (isset($_REQUEST['search_path']))
		$fields['search_path'] = $_REQUEST['search_path'];

	$navlinks['download'] = array(
		'attr' => array(
			'href' => array(
				'url' => 'dataexport.php',
				'urlvars' => array_merge($fields, ['query' => $_SESSION['sqlquery']])
			)
		),
		'icon' => $misc->icon('Download'),
		'content' => $lang['strdownload']
	);
}

$misc->printNavLinks($navlinks, 'sql-form', get_defined_vars());

$misc->printFooter();
