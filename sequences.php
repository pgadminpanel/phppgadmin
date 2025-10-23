<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\Actions\SequenceActions;

/**
 * Manage sequences in a database
 *
 * $Id: sequences.php,v 1.49 2007/12/15 22:21:54 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

function ownedSequenceNames($sequenceActions, $sequences)
{
	$owned = [];
	foreach ($sequences as $sequenceName) {
		$sequence = $sequenceActions->getSequence($sequenceName);
		if (
			is_object($sequence)
			&& $sequence->recordCount() > 0
			&& !empty($sequence->fields['owned_table'])
			&& !empty($sequence->fields['owned_column'])
		) {
			$owned[] = $sequenceName;
		}
	}

	return $owned;
}

/**
 * Display list of all sequences in the database/schema
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	$conf = AppContainer::getConf();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'sequences');
	$misc->printMsg($msg);

	// Get all sequences
	$sequences = $sequenceActions->getSequences();

	$columns = [
		'sequence' => [
			'title' => $lang['strsequence'],
			'field' => field('seqname'),
			'url' => "sequences.php?action=properties&amp;{$misc->href}&amp;",
			'vars' => ['sequence' => 'seqname'],
			'icon' => $misc->icon('Sequence'),
			'class' => 'nowrap',
		],
		'table' => [
			'icon' => $misc->icon('Table'),
			'title' => $lang['strtable'],
			'field' => field('tablename'),
			'url' => "tblproperties.php?{$misc->href}&amp;subject=table&amp;",
			'vars' => ['schema' => 'owned_table_schema', 'table' => 'tablename'],
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('seqowner'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('seqcomment'),
		],
	];

	$footer = [
		'sequence' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strsequences']}",
			'colspan' => 2,
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 3,
		],
	];

	$actions = [
		'multiactions' => [
			'keycols' => ['sequence' => 'seqname'],
			'url' => 'sequences.php',
		],
		'alter' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stralter'],
			'attr' => [
				'href' => [
					'url' => 'sequences.php',
					'urlvars' => [
						'action' => 'confirm_alter',
						'subject' => 'sequence',
						'sequence' => field('seqname')
					]
				]
			]
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'disable' => callback(function ($fields) {
				return !empty($fields['tablename']);
			}),
			'attr' => [
				'href' => [
					'url' => 'sequences.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'sequence' => field('seqname')
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
						'subject' => 'sequence',
						'sequence' => field('seqname')
					]
				]
			]
		],
	];

	$misc->printTable(
		$sequences,
		$columns,
		$actions,
		'sequences-sequences',
		$lang['strnosequences'],
		null,
		$footer
	);

	$misc->printNavLinks([
		'create' => [
			'attr' => [
				'href' => [
					'url' => 'sequences.php',
					'urlvars' => [
						'action' => 'create',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateSequence'),
			'content' => $lang['strcreatesequence']
		]
	], 'sequences-sequences', get_defined_vars());
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$sequenceActions = new SequenceActions($pg);

	$sequences = $sequenceActions->getSequences();

	$reqvars = $misc->getRequestVars('sequence');

	$attrs = [
		'text' => field('seqname'),
		'icon' => 'Sequence',
		'toolTip' => field('seqcomment'),
		'action' => url(
			'sequences.php',
			$reqvars,
			[
				'action' => 'properties',
				'sequence' => field('seqname')
			]
		)
	];

	$misc->printTree($sequences, $attrs, 'sequences');
	exit;
}

/**
 * Display the properties of a sequence
 */
function doProperties($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	$misc->printTrail('sequence');
	$misc->printTitle($lang['strproperties'], 'pg.sequence');
	$misc->printMsg($msg);

	// Fetch the sequence information
	$sequence = $sequenceActions->getSequence($_REQUEST['sequence']);

	if (is_object($sequence) && $sequence->recordCount() > 0) {
		$sequence->fields['is_cycled'] = $pg->phpBool($sequence->fields['is_cycled']);
		$sequence->fields['is_called'] = $pg->phpBool($sequence->fields['is_called']);
		// Show comment if any
		if ($sequence->fields['seqcomment'] !== null)
			echo "<p class=\"comment\">", $misc->formatVal($sequence->fields['seqcomment']), "</p>\n";

		if (!empty($sequence->fields['owned_table']) && !empty($sequence->fields['owned_column'])) {
			$ownedTableSchema = $sequence->fields['owned_table_schema'] ?: $_REQUEST['schema'];
			echo "<table border=\"0\">";
			echo "<tr><th class=\"data\">{$lang['strtable']}</th><th class=\"data\">{$lang['strcolumn']}</th></tr>";
			echo "<tr>";
			echo "<td class=\"data1 no-wrap\"><a href=\"tblproperties.php?{$misc->href}&amp;subject=table&amp;schema=", urlencode($ownedTableSchema), "&amp;table=", urlencode($sequence->fields['owned_table']), "\">";
			echo "<img src=\"", $misc->icon('Table'), "\" alt=\"Table\" class=\"icon\" />", $misc->formatVal("{$ownedTableSchema}.{$sequence->fields['owned_table']}"), "</a></td>";
			echo "<td class=\"data1 no-wrap\"><a href=\"colproperties.php?{$misc->href}&amp;subject=column&amp;schema=", urlencode($ownedTableSchema), "&amp;table=", urlencode($sequence->fields['owned_table']), "&amp;column=", urlencode($sequence->fields['owned_column']), "&amp;action=properties\">";
			echo "<img src=\"", $misc->icon('Column'), "\" alt=\"Column\" class=\"icon\" />", $misc->formatVal($sequence->fields['owned_column']), "</a></td>";
			echo "</tr>";
			echo "</table>";
		}

		echo "<table border=\"0\">";
		echo "<tr><th class=\"data\">{$lang['strname']}</th>";
		if ($pg->hasAlterSequenceStart()) {
			echo "<th class=\"data\">{$lang['strstartvalue']}</th>";
		}
		echo "<th class=\"data\">{$lang['strlastvalue']}</th>";
		echo "<th class=\"data\">{$lang['strincrementby']}</th>";
		echo "<th class=\"data\">{$lang['strmaxvalue']}</th>";
		echo "<th class=\"data\">{$lang['strminvalue']}</th>";
		echo "<th class=\"data\">{$lang['strcachevalue']}</th>";
		echo "<th class=\"data\">{$lang['strlogcount']}</th>";
		echo "<th class=\"data\">{$lang['strcancycle']}</th>";
		echo "<th class=\"data\">{$lang['striscalled']}</th></tr>";
		echo "<tr>";
		echo "<td class=\"data1 no-wrap\"><img src=\"", $misc->icon('Sequence'), "\" alt=\"Sequence\" class=\"icon\" />", $misc->formatVal($sequence->fields['seqname']), "</td>";
		if ($pg->hasAlterSequenceStart()) {
			echo "<td class=\"data1\">", $misc->formatVal($sequence->fields['start_value']), "</td>";
		}
		echo "<td class=\"data1\">", $misc->formatVal($sequence->fields['last_value']), "</td>";
		echo "<td class=\"data1\">", $misc->formatVal($sequence->fields['increment_by']), "</td>";
		echo "<td class=\"data1\">", $misc->formatVal($sequence->fields['max_value']), "</td>";
		echo "<td class=\"data1\">", $misc->formatVal($sequence->fields['min_value']), "</td>";
		echo "<td class=\"data1\">", $misc->formatVal($sequence->fields['cache_value']), "</td>";
		echo "<td class=\"data1\">", $misc->formatVal($sequence->fields['log_cnt']), "</td>";
		echo "<td class=\"data1\">", ($sequence->fields['is_cycled'] ? $lang['stryes'] : $lang['strno']), "</td>";
		echo "<td class=\"data1\">", ($sequence->fields['is_called'] ? $lang['stryes'] : $lang['strno']), "</td>";
		echo "</tr>";
		echo "</table>";

		$navlinks = [
			'alter' => [
				'attr' => [
					'href' => [
						'url' => 'sequences.php',
						'urlvars' => [
							'action' => 'confirm_alter',
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
							'sequence' => $sequence->fields['seqname']
						]
					]
				],
				'icon' => $misc->icon('Edit'),
				'content' => $lang['stralter']
			],
			'setval' => [
				'attr' => [
					'href' => [
						'url' => 'sequences.php',
						'urlvars' => [
							'action' => 'confirm_setval',
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
							'sequence' => $sequence->fields['seqname']
						]
					]
				],
				'icon' => $misc->icon('UniqueConstraint'),
				'content' => $lang['strsetval']
			],
			'nextval' => [
				'attr' => [
					'href' => [
						'url' => 'sequences.php',
						'urlvars' => [
							'action' => 'nextval',
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
							'sequence' => $sequence->fields['seqname']
						]
					]
				],
				'icon' => $misc->icon('Operator'),
				'content' => $lang['strnextval']
			],
			'restart' => [
				'attr' => [
					'href' => [
						'url' => 'sequences.php',
						'urlvars' => [
							'action' => 'restart',
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
							'sequence' => $sequence->fields['seqname']
						]
					]
				],
				'icon' => $misc->icon('Undo'),
				'content' => $lang['strrestart']
			],
			'reset' => [
				'attr' => [
					'href' => [
						'url' => 'sequences.php',
						'urlvars' => [
							'action' => 'reset',
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
							'sequence' => $sequence->fields['seqname']
						]
					]
				],
				'icon' => $misc->icon('Refresh'),
				'content' => $lang['strreset']
			],
			'showall' => [
				'attr' => [
					'href' => [
						'url' => 'sequences.php',
						'urlvars' => [
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema']
						]
					]
				],
				'icon' => $misc->icon('Sequences'),
				'content' => $lang['strshowallsequences']
			]
		];

		if (!$pg->hasAlterSequenceStart())
			unset($navlinks['restart']);

		$misc->printNavLinks($navlinks, 'sequences-properties', get_defined_vars());
	} else
		echo "<p>{$lang['strnodata']}</p>\n";
}

/**
 * Drop a sequence
 */
function doDrop($confirm, $msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	if (empty($_REQUEST['sequence']) && empty($_REQUEST['ma'])) {
		doDefault($lang['strspecifysequencetodrop']);
		exit();
	}

	$requestedSequences = [];
	if (isset($_REQUEST['ma'])) {
		foreach ($_REQUEST['ma'] as $v) {
			$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
			if (!empty($a['sequence'])) {
				$requestedSequences[] = $a['sequence'];
			}
		}
	} elseif (isset($_REQUEST['sequence'])) {
		if (is_array($_REQUEST['sequence'])) {
			$requestedSequences = $_REQUEST['sequence'];
		} else {
			$requestedSequences = [$_REQUEST['sequence']];
		}
	}

	$ownedSequences = ownedSequenceNames($sequenceActions, $requestedSequences);
	if (!empty($ownedSequences)) {
		doDefault($lang['strsystemobjectdenied']);
		return;
	}

	if ($confirm) {
		$misc->printTrail('sequence');
		$misc->printTitle($lang['strdrop'], 'pg.sequence.drop');
		$misc->printMsg($msg);

		echo "<form action=\"sequences.php\" method=\"post\">\n";

		//If multi drop
		if (isset($_REQUEST['ma'])) {
			foreach ($_REQUEST['ma'] as $v) {
				$a = unserialize(htmlspecialchars_decode($v, ENT_QUOTES));
				echo "<p>", sprintf($lang['strconfdropsequence'], $misc->formatVal($a['sequence'])), "</p>\n";
				printf('<input type="hidden" name="sequence[]" value="%s" />', html_esc($a['sequence']));
			}
		} else {
			echo "<p>", sprintf($lang['strconfdropsequence'], $misc->formatVal($_REQUEST['sequence'])), "</p>\n";
			echo "<input type=\"hidden\" name=\"sequence\" value=\"", html_esc($_REQUEST['sequence']), "\" />\n";
		}

		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		if (is_array($_POST['sequence'])) {
			$msg = '';
			$status = $pg->beginTransaction();
			if ($status == 0) {
				foreach ($_POST['sequence'] as $s) {
					$status = $sequenceActions->dropSequence($s, isset($_POST['cascade']));
					if ($status == 0)
						$msg .= sprintf('%s: %s<br />', htmlentities($s, ENT_QUOTES, 'UTF-8'), $lang['strsequencedropped']);
					else {
						$pg->endTransaction();
						doDefault(sprintf('%s%s: %s<br />', $msg, htmlentities($s, ENT_QUOTES, 'UTF-8'), $lang['strsequencedroppedbad']));
						return;
					}
				}
			}
			if ($pg->endTransaction() == 0) {
				// Everything went fine, back to the Default page....
				AppContainer::setShouldReloadTree(true);
				doDefault($msg);
			} else
				doDefault($lang['strsequencedroppedbad']);
		} else {
			$status = $sequenceActions->dropSequence($_POST['sequence'], isset($_POST['cascade']));
			if ($status == 0) {
				AppContainer::setShouldReloadTree(true);
				doDefault($lang['strsequencedropped']);
			} else
				doDrop(true, $lang['strsequencedroppedbad']);
		}
	}
}

/**
 * Displays a screen where they can enter a new sequence
 */
function doCreateSequence($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();

	if (!isset($_POST['formSequenceName']))
		$_POST['formSequenceName'] = '';
	if (!isset($_POST['formIncrement']))
		$_POST['formIncrement'] = '';
	if (!isset($_POST['formMinValue']))
		$_POST['formMinValue'] = '';
	if (!isset($_POST['formMaxValue']))
		$_POST['formMaxValue'] = '';
	if (!isset($_POST['formStartValue']))
		$_POST['formStartValue'] = '';
	if (!isset($_POST['formCacheValue']))
		$_POST['formCacheValue'] = '';

	$misc->printTrail('schema');
	$misc->printTitle($lang['strcreatesequence'], 'pg.sequence.create');
	$misc->printMsg($msg);

	echo "<form action=\"sequences.php\" method=\"post\">\n";
	echo "<table>\n";

	echo "<tr><th class=\"data left required\">{$lang['strname']}</th>\n";
	echo "<td class=\"data1\"><input name=\"formSequenceName\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
		html_esc($_POST['formSequenceName']), "\" /></td></tr>\n";

	echo "<tr><th class=\"data left\">{$lang['strincrementby']}</th>\n";
	echo "<td class=\"data1\"><input name=\"formIncrement\" size=\"5\" value=\"",
		html_esc($_POST['formIncrement']), "\" /> </td></tr>\n";

	echo "<tr><th class=\"data left\">{$lang['strminvalue']}</th>\n";
	echo "<td class=\"data1\"><input name=\"formMinValue\" size=\"5\" value=\"",
		html_esc($_POST['formMinValue']), "\" /></td></tr>\n";

	echo "<tr><th class=\"data left\">{$lang['strmaxvalue']}</th>\n";
	echo "<td class=\"data1\"><input name=\"formMaxValue\" size=\"5\" value=\"",
		html_esc($_POST['formMaxValue']), "\" /></td></tr>\n";

	echo "<tr><th class=\"data left\">{$lang['strstartvalue']}</th>\n";
	echo "<td class=\"data1\"><input name=\"formStartValue\" size=\"5\" value=\"",
		html_esc($_POST['formStartValue']), "\" /></td></tr>\n";

	echo "<tr><th class=\"data left\">{$lang['strcachevalue']}</th>\n";
	echo "<td class=\"data1\"><input name=\"formCacheValue\" size=\"5\" value=\"",
		html_esc($_POST['formCacheValue']), "\" /></td></tr>\n";

	echo "<tr><th class=\"data left\"><label for=\"formCycledValue\">{$lang['strcancycle']}</label></th>\n";
	echo "<td class=\"data1\"><input type=\"checkbox\" id=\"formCycledValue\" name=\"formCycledValue\" ", (isset($_POST['formCycledValue']) ? ' checked="checked"' : ''), " /></td></tr>\n";

	echo "</table>\n";
	echo "<p><input type=\"hidden\" name=\"action\" value=\"save_create_sequence\" />\n";
	echo $misc->form;
	echo "<input type=\"submit\" name=\"create\" value=\"{$lang['strcreate']}\" />\n";
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
	echo "</form>\n";
}

/**
 * Actually creates the new sequence in the database
 */
function doSaveCreateSequence()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	// Check that they've given a name and at least one column
	if ($_POST['formSequenceName'] == '')
		doCreateSequence($lang['strsequenceneedsname']);
	else {
		$status = $sequenceActions->createSequence(
			$_POST['formSequenceName'],
			$_POST['formIncrement'],
			$_POST['formMinValue'],
			$_POST['formMaxValue'],
			$_POST['formStartValue'],
			$_POST['formCacheValue'],
			isset($_POST['formCycledValue'])
		);
		if ($status == 0) {
			doDefault($lang['strsequencecreated']);
		} else {
			doCreateSequence($lang['strsequencecreatedbad']);
		}
	}
}

/**
 * Restarts a sequence
 */
function doRestart()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	$status = $sequenceActions->restartSequence($_REQUEST['sequence']);
	if ($status == 0)
		doProperties($lang['strsequencerestart']);
	else
		doProperties($lang['strsequencerestartbad']);
}

/**
 * Resets a sequence
 */
function doReset()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	$status = $sequenceActions->resetSequence($_REQUEST['sequence']);
	if ($status == 0)
		doProperties($lang['strsequencereset']);
	else
		doProperties($lang['strsequenceresetbad']);
}

/**
 * Set Nextval of a sequence
 */
function doNextval()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	$status = $sequenceActions->nextvalSequence($_REQUEST['sequence']);
	if ($status == 0)
		doProperties($lang['strsequencenextval']);
	else
		doProperties($lang['strsequencenextvalbad']);
}

/**
 * Function to save after 'setval'ing a sequence
 */
function doSaveSetval()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	$status = $sequenceActions->setvalSequence($_POST['sequence'], $_POST['nextvalue']);
	if ($status == 0)
		doProperties($lang['strsequencesetval']);
	else
		doProperties($lang['strsequencesetvalbad']);
}

/**
 * Function to allow 'setval'ing of a sequence
 */
function doSetval($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);

	$misc->printTrail('sequence');
	$misc->printTitle($lang['strsetval'], 'pg.sequence');
	$misc->printMsg($msg);

	// Fetch the sequence information
	$sequence = $sequenceActions->getSequence($_REQUEST['sequence']);

	if (is_object($sequence) && $sequence->recordCount() > 0) {
		echo "<form action=\"sequences.php\" method=\"post\">\n";
		echo "<table border=\"0\">";
		echo "<tr><th class=\"data left required\">{$lang['strlastvalue']}</th>\n";
		echo "<td class=\"data1\">";
		echo "<input name=\"nextvalue\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"", $sequence->fields['last_value'], "\" /></td></tr>\n";
		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"setval\" />\n";
		echo "<input type=\"hidden\" name=\"sequence\" value=\"", html_esc($_REQUEST['sequence']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"setval\" value=\"{$lang['strsetval']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else
		echo "<p class=\"no-data\">{$lang['strnodata']}</p>\n";
}

/**
 * Function to save after altering a sequence
 */
function doSaveAlter()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$misc = AppContainer::getMisc();
	$sequenceActions = new SequenceActions($pg);


	if (!isset($_POST['owner']))
		$_POST['owner'] = null;
	if (!isset($_POST['newschema']))
		$_POST['newschema'] = null;
	if (!isset($_POST['formIncrement']))
		$_POST['formIncrement'] = null;
	if (!isset($_POST['formMinValue']))
		$_POST['formMinValue'] = null;
	if (!isset($_POST['formMaxValue']))
		$_POST['formMaxValue'] = null;
	if (!isset($_POST['formStartValue']))
		$_POST['formStartValue'] = null;
	if (!isset($_POST['formRestartValue']))
		$_POST['formRestartValue'] = null;
	if (!isset($_POST['formCacheValue']))
		$_POST['formCacheValue'] = null;
	if (!isset($_POST['formCycledValue']))
		$_POST['formCycledValue'] = null;

	$status = $sequenceActions->alterSequence(
		$_POST['sequence'],
		$_POST['name'],
		$_POST['comment'],
		$_POST['owner'],
		$_POST['newschema'],
		$_POST['formIncrement'],
		$_POST['formMinValue'],
		$_POST['formMaxValue'],
		$_POST['formRestartValue'],
		$_POST['formCacheValue'],
		isset($_POST['formCycledValue']),
		$_POST['formStartValue']
	);

	if ($status == 0) {
		if ($_POST['sequence'] != $_POST['name']) {
			// Jump them to the new view name
			$_REQUEST['sequence'] = $_POST['name'];
			// Reload the tree
			AppContainer::setShouldReloadTree(true);
		}
		if (!empty($_POST['newschema']) && ($_POST['newschema'] != $pg->_schema)) {
			// Jump them to the new sequence schema
			$misc->setCurrentSchema($_POST['newschema']);
			AppContainer::setShouldReloadTree(true);
		}
		doProperties($lang['strsequencealtered']);
	} else
		doProperties($lang['strsequencealteredbad']);
}

/**
 * Function to allow altering of a sequence
 */
function doAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$sequenceActions = new SequenceActions($pg);
	$roleActions = new RoleActions($pg);
	$schemaActions = new SchemaActions($pg);

	$misc->printTrail('sequence');
	$misc->printTitle($lang['stralter'], 'pg.sequence.alter');
	$misc->printMsg($msg);

	// Fetch the sequence information
	$sequence = $sequenceActions->getSequence($_REQUEST['sequence']);

	if (is_object($sequence) && $sequence->recordCount() > 0) {
		if (!isset($_POST['name']))
			$_POST['name'] = $_REQUEST['sequence'];
		if (!isset($_POST['comment']))
			$_POST['comment'] = $sequence->fields['seqcomment'];
		if (!isset($_POST['owner']))
			$_POST['owner'] = $sequence->fields['seqowner'];
		if (!isset($_POST['newschema']))
			$_POST['newschema'] = $sequence->fields['nspname'];

		// Handle Checkbox Value
		$sequence->fields['is_cycled'] = $pg->phpBool($sequence->fields['is_cycled']);
		if ($sequence->fields['is_cycled'])
			$_POST['formCycledValue'] = 'on';

		echo "<form action=\"sequences.php\" method=\"post\">\n";
		echo "<table>\n";

		echo "<tr><th class=\"data left required\">{$lang['strname']}</th>\n";
		echo "<td class=\"data1\">";
		echo "<input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
			html_esc($_POST['name']), "\" /></td></tr>\n";

		if ($roleActions->isSuperUser()) {
			// Fetch all users
			$users = $roleActions->getUsers();

			echo "<tr><th class=\"data left required\">{$lang['strowner']}</th>\n";
			echo "<td class=\"data1\"><select name=\"owner\">";
			while (!$users->EOF) {
				$uname = $users->fields['usename'];
				echo "<option value=\"", html_esc($uname), "\"", ($uname == $_POST['owner']) ? ' selected="selected"' : '', ">", html_esc($uname), "</option>\n";
				$users->moveNext();
			}
			echo "</select></td></tr>\n";
		}

		if ($pg->hasAlterSequenceSchema()) {
			$schemas = $schemaActions->getSchemas();
			echo "<tr><th class=\"data left required\">{$lang['strschema']}</th>\n";
			echo "<td class=\"data1\"><select name=\"newschema\">";
			while (!$schemas->EOF) {
				$schema = $schemas->fields['nspname'];
				echo "<option value=\"", html_esc($schema), "\"", ($schema == $_POST['newschema']) ? ' selected="selected"' : '', ">", html_esc($schema), "</option>\n";
				$schemas->moveNext();
			}
			echo "</select></td></tr>\n";
		}

		echo "<tr><th class=\"data left\">{$lang['strcomment']}</th>\n";
		echo "<td class=\"data1\">";
		echo "<textarea rows=\"3\" cols=\"32\" name=\"comment\">",
			html_esc($_POST['comment']), "</textarea></td></tr>\n";

		if ($pg->hasAlterSequenceStart()) {
			echo "<tr><th class=\"data left\">{$lang['strstartvalue']}</th>\n";
			echo "<td class=\"data1\"><input name=\"formStartValue\" size=\"5\" value=\"",
				html_esc($sequence->fields['start_value']), "\" /></td></tr>\n";
		}

		echo "<tr><th class=\"data left\">{$lang['strrestartvalue']}</th>\n";
		echo "<td class=\"data1\"><input name=\"formRestartValue\" size=\"5\" value=\"",
			html_esc($sequence->fields['last_value']), "\" /></td></tr>\n";

		echo "<tr><th class=\"data left\">{$lang['strincrementby']}</th>\n";
		echo "<td class=\"data1\"><input name=\"formIncrement\" size=\"5\" value=\"",
			html_esc($sequence->fields['increment_by']), "\" /> </td></tr>\n";

		echo "<tr><th class=\"data left\">{$lang['strmaxvalue']}</th>\n";
		echo "<td class=\"data1\"><input name=\"formMaxValue\" size=\"5\" value=\"",
			html_esc($sequence->fields['max_value']), "\" /></td></tr>\n";

		echo "<tr><th class=\"data left\">{$lang['strminvalue']}</th>\n";
		echo "<td class=\"data1\"><input name=\"formMinValue\" size=\"5\" value=\"",
			html_esc($sequence->fields['min_value']), "\" /></td></tr>\n";

		echo "<tr><th class=\"data left\">{$lang['strcachevalue']}</th>\n";
		echo "<td class=\"data1\"><input name=\"formCacheValue\" size=\"5\" value=\"",
			html_esc($sequence->fields['cache_value']), "\" /></td></tr>\n";

		echo "<tr><th class=\"data left\"><label for=\"formCycledValue\">{$lang['strcancycle']}</label></th>\n";
		echo "<td class=\"data1\"><input type=\"checkbox\" id=\"formCycledValue\" name=\"formCycledValue\" ", (isset($_POST['formCycledValue']) ? ' checked="checked"' : ''), " /></td></tr>\n";

		echo "</table>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"alter\" />\n";
		echo $misc->form;
		echo "<input type=\"hidden\" name=\"sequence\" value=\"", html_esc($_REQUEST['sequence']), "\" />\n";
		echo "<input type=\"submit\" name=\"alter\" value=\"{$lang['stralter']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else
		echo "<p>{$lang['strnodata']}</p>\n";
}

// Main program
$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';
if (!isset($msg))
	$msg = '';

if ($action == 'tree')
	doTree();

// Print header
$misc->printHeader($lang['strsequences']);
$misc->printBody();

switch ($action) {
	case 'create':
		doCreateSequence();
		break;
	case 'save_create_sequence':
		if (isset($_POST['create']))
			doSaveCreateSequence();
		else
			doDefault();
		break;
	case 'properties':
		doProperties();
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
	case 'restart':
		doRestart();
		break;
	case 'reset':
		doReset();
		break;
	case 'nextval':
		doNextval();
		break;
	case 'setval':
		if (isset($_POST['setval']))
			doSaveSetval();
		else
			doDefault();
		break;
	case 'confirm_setval':
		doSetval();
		break;
	case 'alter':
		if (isset($_POST['alter']))
			doSaveAlter();
		else
			doDefault();
		break;
	case 'confirm_alter':
		doAlter();
		break;
	default:
		doDefault();
		break;
}

// Print footer
$misc->printFooter();
