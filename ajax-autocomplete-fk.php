<?php

use PhpPgAdmin\Core\AppContainer;

include_once('./libraries/bootstrap.php');

$pg = AppContainer::getPostgres();
$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

if (isset($_POST['offset']))
	$offset = " OFFSET {$_POST['offset']}";
else {
	$_POST['offset'] = 0;
	$offset = " OFFSET 0";
}

$keynames = [];
foreach ($_POST['keynames'] as $k => $v) {
	$keynames[$k] = html_entity_decode($v, ENT_QUOTES);
}
$f_keynames = [];
foreach ($_POST['f_keynames'] as $k => $v) {
	$f_keynames[$k] = html_entity_decode($v, ENT_QUOTES);
}

$keyspos = array_combine($f_keynames, $keynames);

$f_schema = html_entity_decode($_POST['f_schema'], ENT_QUOTES);
$pg->fieldClean($f_schema);
$f_table = html_entity_decode($_POST['f_table'], ENT_QUOTES);
$pg->fieldClean($f_table);
$f_attname = $f_keynames[$_POST['fattpos'][0]];
$pg->fieldClean($f_attname);

$q = "SELECT *
		FROM \"{$f_schema}\".\"{$f_table}\"
		WHERE \"{$f_attname}\"::text LIKE '{$_POST['fvalue']}%'
		ORDER BY \"{$f_attname}\" LIMIT 13 {$offset};";

$res = $pg->selectSet($q);

$context = $_POST['context'];
if (!$res->EOF) {
	echo "<table class=\"ac_values\">";
	echo '<tr class="ac_header">';
	foreach (array_keys($res->fields) as $h) {
		echo '<th>';

		if (isset($keyspos[$h]))
			echo '<img src="' . $misc->icon('ForeignKey') . '" alt="[referenced key]">';

		echo htmlentities($h, ENT_QUOTES, 'UTF-8'), '</th>';

	}
	echo "</tr>\n";
	$i = 0;
	while ((!$res->EOF) && ($i < 12)) {
		$j = 0;
		echo "<tr class=\"ac_line\">";
		foreach ($res->fields as $n => $v) {
			$finfo = $res->fetchField($j++);
			if (isset($keyspos[$n])) {
				$field_name = htmlspecialchars($keyspos[$n]);
				echo "<td><a href=\"javascript:void(0)\" class=\"fkval\" name=\"{$field_name}\">",
					$misc->formatVal($v, $finfo->type, ['clip' => 'collapsed']),
					"</a></td>";
			} else {
				echo "<td><a href=\"javascript:void(0)\">",
					$misc->formatVal($v, $finfo->type, ['clip' => 'collapsed']),
					"</a></td>";
			}
		}
		echo "</tr>\n";
		$i++;
		$res->moveNext();
	}
	echo "</table>\n";


} else {
	printf("<p class=\"empty\">{$lang['strnofkref']}</p>", "\"{$_POST['f_schema']}\".\"{$_POST['f_table']}\".\"{$f_keynames[$_POST['fattpos']]}\"");
}

$hasPrev = !empty($_POST['offset']);
$hasNext = $res->recordCount() == 13;

echo "<div class=\"ac-page-nav\">\n";
$class = "fkprev " . ($hasPrev ? "" : " disabled");
echo "<a href=\"#\" class=\"$class\" id=\"fkprev-$context\"><span class=\"psm\">⮜</span> Prev. Page</a>";
$class = "fknext " . ($hasNext ? "" : " disabled");
echo "<a href=\"#\" class=\"$class\" id=\"fknext-$context\">Next Page <span class=\"psm\">⮞</span></a>";