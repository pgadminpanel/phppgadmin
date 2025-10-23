<?php

use PHPSQLParser\PHPSQLParser;
use PhpPgAdmin\Gui\FormRenderer;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Gui\RowBrowserRenderer;
use PhpPgAdmin\Database\Actions\RowActions;
use PhpPgAdmin\Database\ByteaQueryModifier;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\SchemaActions;
use PhpPgAdmin\Database\QueryResultMetadataProbe;
use PhpPgAdmin\Database\Actions\ConstraintActions;

/**
 * Common relation browsing function that can be used for views,
 * tables, reports, arbitrary queries, etc. to avoid code duplication.
 * @param string $query The SQL SELECT string to execute
 * @param string $count The same SQL query, but only retrieves the count of the rows (AS total)
 * @param mixed $return The return section
 * @param int $page The current page
 *
 * $Id: display.php,v 1.68 2008/04/14 12:44:27 ioguix Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

// Prevent timeouts on large exports (non-safe mode only)
if (!ini_get('safe_mode'))
	set_time_limit(0);


/**
 * Show confirmation of edit or insert and perform insert or update
 */
function doEditRow($confirm, $msg = '')
{

	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$conf = AppContainer::getConf();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);
	$tableActions = new TableActions($pg);
	$schemaActions = new SchemaActions($pg);

	$schema = $_REQUEST['schema'] ?? $pg->_schema;
	if (!empty($schema)) {
		$schemaActions->setSchema($schema);
	}

	$insert = !isset($_REQUEST['key']);
	if (!$insert) {
		if (is_array($_REQUEST['key']))
			$keyFields = $_REQUEST['key'];
		else
			$keyFields = unserialize(urldecode($_REQUEST['key']));
	} else {
		$keyFields = [];
	}

	$attrs = $tableActions->getTableAttributes($_REQUEST['table']);
	$byteaColumns = [];
	if ($attrs && $attrs->recordCount() > 0) {
		while (!$attrs->EOF) {
			$type = $attrs->fields['type'] ?? '';
			if (strpos($type, 'bytea') === 0) {
				$byteaColumns[] = $attrs->fields['attname'];
			}
			$attrs->moveNext();
		}
		$attrs->moveFirst();
	}

	$byteaSizes = [];
	if (!$insert && !empty($byteaColumns)) {
		$whereParts = [];
		foreach ($keyFields as $field => $value) {
			if ($value === null || (is_string($value) && strcasecmp($value, 'NULL') === 0)) {
				$whereParts[] = $pg->quoteIdentifier($field) . ' IS NULL';
			} else {
				$whereParts[] = $pg->quoteIdentifier($field) . ' = ' . $pg->escapeLiteral($value);
			}
		}
		$whereClause = implode(' AND ', $whereParts);

		$sizeSelect = [];
		foreach ($byteaColumns as $col) {
			$escapedCol = $pg->quoteIdentifier($col);
			$sizeSelect[] = 'octet_length(' . $escapedCol . ') AS ' . $escapedCol;
		}

		$sizeSql = 'SELECT ' . implode(', ', $sizeSelect) .
			' FROM ' . $pg->quoteIdentifier($schema) . '.' . $pg->quoteIdentifier($_REQUEST['table']) .
			' WHERE ' . $whereClause .
			' LIMIT 1';

		$sizeResult = $pg->selectSet($sizeSql);
		if ($sizeResult && $sizeResult->recordCount() === 1) {
			foreach ($byteaColumns as $col) {
				$byteaSizes[$col] = $sizeResult->fields[$col];
			}
		}
	}

	$rs = null;
	if (!$insert) {
		$byteaInlineLimit = isset($conf['bytea_inline_limit']) ? (int) $conf['bytea_inline_limit'] : 1024 * 1024;
		if ($byteaInlineLimit < 0) {
			$byteaInlineLimit = 0;
		}

		$selectParts = [];
		if (is_object($attrs) && $attrs->recordCount() > 0) {
			while (!$attrs->EOF) {
				$col = $attrs->fields['attname'];
				$type = $attrs->fields['type'] ?? '';
				if (strpos($type, 'bytea') === 0) {
					$size = $byteaSizes[$col] ?? null;
					if ($size !== null && $byteaInlineLimit > 0 && $size > $byteaInlineLimit) {
						$selectParts[] = 'NULL AS ' . $pg->quoteIdentifier($col);
					} else {
						$selectParts[] = $pg->quoteIdentifier($col);
					}
				} else {
					$selectParts[] = $pg->quoteIdentifier($col);
				}
				$attrs->moveNext();
			}
			$attrs->moveFirst();
		}

		if (!empty($selectParts)) {
			$whereParts = [];
			foreach ($keyFields as $field => $value) {
				if ($value === null || (is_string($value) && strcasecmp($value, 'NULL') === 0)) {
					$whereParts[] = $pg->quoteIdentifier($field) . ' IS NULL';
				} else {
					$whereParts[] = $pg->quoteIdentifier($field) . ' = ' . $pg->escapeLiteral($value);
				}
			}
			$whereClause = implode(' AND ', $whereParts);
			$rowSql = 'SELECT ' . implode(', ', $selectParts) .
				' FROM ' . $pg->quoteIdentifier($schema) . '.' . $pg->quoteIdentifier($_REQUEST['table']) .
				' WHERE ' . $whereClause .
				' LIMIT 1';
			$rs = $pg->selectSet($rowSql);
		}

		if (!$rs) {
			$rs = $rowActions->browseRow($_REQUEST['table'], $keyFields);
		}
	}

	if (!$confirm) {
		// Insert or update the row
		if (!isset($_POST['values']))
			$_POST['values'] = [];
		if (!isset($_POST['nulls']))
			$_POST['nulls'] = [];
		if (!isset($_POST['expr']))
			$_POST['expr'] = [];

		$fields = [];
		$types = [];
		$generatedColumns = [];
		while (!$attrs->EOF) {
			// Skip generated columns - they cannot be manually set
			$isGenerated = isset($attrs->fields['attgenerated']) && $attrs->fields['attgenerated'] === 's';
			if ($isGenerated) {
				$generatedColumns[] = $attrs->fields['attname'];
				$attrs->moveNext();
				continue;
			}

			$fields[$attrs->fields['attnum']] = $attrs->fields['attname'];
			$types[$attrs->fields['attname']] = $attrs->fields['type'];
			$attrs->moveNext();
		}

		// Remove any values submitted for generated columns
		if (!empty($generatedColumns)) {
			foreach ($generatedColumns as $genCol) {
				unset($_POST['values'][$genCol]);
				unset($_POST['nulls'][$genCol]);
				unset($_POST['expr'][$genCol]);
			}
		}

		$byteaColumns = [];
		foreach ($types as $field => $type) {
			if (strpos($type, 'bytea') === 0) {
				$byteaColumns[] = $field;
			}
		}

		$byteaUploads = $_FILES['bytea_upload'] ?? [];
		if (!is_array($byteaUploads)) {
			$byteaUploads = [];
		}
		if (!empty($byteaColumns) && !empty($byteaUploads)) {
			foreach ($byteaColumns as $field) {
				$uploadError = $byteaUploads['error'][$field] ?? UPLOAD_ERR_NO_FILE;
				if ($uploadError === UPLOAD_ERR_OK) {
					$tmpName = $byteaUploads['tmp_name'][$field] ?? '';
					if ($tmpName && is_uploaded_file($tmpName)) {
						$data = file_get_contents($tmpName);
						if ($data !== false) {
							$hex = bin2hex($data);
							$_POST['values'][$field] = "decode('{$hex}','hex')";
							$_POST['expr'][$field] = 1;
							unset($_POST['nulls'][$field]);
						}
					}
				}
			}
		}

		if (!$insert && !empty($_POST['bytea_keep']) && is_array($_POST['bytea_keep'])) {
			foreach ($_POST['bytea_keep'] as $field => $keep) {
				if (!in_array($field, $byteaColumns, true)) {
					continue;
				}
				if (isset($_POST['nulls'][$field])) {
					continue;
				}
				$uploadError = $byteaUploads['error'][$field] ?? UPLOAD_ERR_NO_FILE;
				$hasUpload = ($uploadError === UPLOAD_ERR_OK);
				$hasValue = isset($_POST['values'][$field]) && $_POST['values'][$field] !== '';
				if (!$hasUpload && !$hasValue) {
					$_POST['expr'][$field] = 1;
					$_POST['values'][$field] = $pg->escapeIdentifier($field);
					unset($_POST['nulls'][$field]);
				}
			}
		}

		if ($insert) {
			// Insert new row
			if ($_SESSION['counter']++ == $_POST['protection_counter']) {
				$status = $rowActions->insertRow(
					$_POST['table'],
					$fields,
					$_POST['values'],
					$_POST['nulls'],
					$_POST['format'],
					$_POST['expr'],
					$types
				);
				if ($status == 0) {
					if (isset($_POST['insert_and_repeat'])) {
						$_POST = [];
						unset($_REQUEST['values']);
						unset($_REQUEST['expr']);
						unset($_REQUEST['nulls']);
						unset($_REQUEST['format']);
						doEditRow(true, $lang['strrowinserted']);
					} else
						doBrowse($lang['strrowinserted']);
				} else
					doEditRow(true, $lang['strrowinsertedbad']);
			} else
				doEditRow(true, $lang['strrowduplicate']);
		} else {
			// Update existing row
			$status = $rowActions->editRow(
				$_POST['table'],
				$_POST['values'],
				$_POST['nulls'],
				$_POST['format'],
				$_POST['expr'],
				$types,
				$keyFields
			);
			if ($status == 0)
				doBrowse($lang['strrowupdated']);
			elseif ($status == -2)
				doEditRow(true, $lang['strrownotunique']);
			else
				doEditRow(true, $lang['strrowupdatedbad']);
		}

		return;
	}

	$formRenderer = new FormRenderer();

	//var_dump($keyFields);
	$initial = empty($_POST);

	$misc->printTrail($_REQUEST['subject']);
	$misc->printTitle($insert ? $lang['strinsertrow'] : $lang['streditrow']);
	$misc->printMsg($msg);

	if (($conf['autocomplete'] != 'disable')) {
		$fksprops = $misc->getAutocompleteFKProperties($_REQUEST['table'], 'insert');
		if ($fksprops !== false)
			echo $fksprops['code'];
	} else
		$fksprops = false;

	[$functions_by_category, $all_functions] = $formRenderer->prepareFieldFunctions();
	$byteaInlineLimit = isset($conf['bytea_inline_limit']) ? (int) $conf['bytea_inline_limit'] : 1024 * 1024;
	if ($byteaInlineLimit < 0) {
		$byteaInlineLimit = 0;
	}

	$parseSize = function ($value) {
		$unit = strtoupper(substr(trim($value), -1));
		$number = (int) $value;
		switch ($unit) {
			case 'G':
				return $number * 1024 * 1024 * 1024;
			case 'M':
				return $number * 1024 * 1024;
			case 'K':
				return $number * 1024;
			default:
				return (int) $value;
		}
	};

	$byteaMaxUploadSize = isset($conf['bytea_max_upload_size']) ? (int) $conf['bytea_max_upload_size'] : 0;
	if ($byteaMaxUploadSize <= 0) {
		$uploadMax = $parseSize(ini_get('upload_max_filesize'));
		$postMax = $parseSize(ini_get('post_max_size'));
		$byteaMaxUploadSize = min($uploadMax, $postMax);
	}

	if ($rs && $rs->recordCount() > 1) {
		$misc->printMsg($lang['strrownotunique']);
		return;
	}

	$isValid = $attrs && $attrs->recordCount() > 0 &&
		($insert || ($rs && $rs->recordCount() == 1));

	if (!$isValid) {
		$misc->printMsg($lang['strinvalidparam']);
		return;
	}

	$typeNames = [];
	if ($attrs && $attrs->recordCount() > 0) {
		while (!$attrs->EOF) {
			$typeNames[] = $attrs->fields['type'] ?? '';
			$attrs->moveNext();
		}
		$attrs->moveFirst();
	}
	$typeActions = new TypeActions($pg);
	$typeMetas = $typeActions->getTypeMetasByNames($typeNames);
	//var_dump($typeNames);

	echo "<form action=\"display.php\" method=\"post\" id=\"ac_form\" enctype=\"multipart/form-data\">\n";

	echo "<table class=\"data\">\n";

	// Output table header
	echo "<thead class=\"sticky-thead\">\n";
	echo "<tr>\n";
	//echo "<th class=\"data\"></th>\n";
	echo "<th class=\"data\">{$lang['strcolumn']}</th>\n";
	echo "<th class=\"data\">{$lang['strtype']}</th>";
	echo "<th class=\"data\">{$lang['strfunction']}</th>\n";
	echo "<th class=\"data\">{$lang['strnull']}</th>\n";
	echo "<th class=\"data\">{$lang['strvalue']}</th>\n";
	echo "<th class=\"data\">{$lang['strexpr']}</th>\n";
	echo "</tr>";
	echo "</thead>\n";
	echo "<tbody>\n";

	$i = 0;
	while (!$attrs->EOF) {

		$attrs->fields['attnotnull'] = $pg->phpBool($attrs->fields['attnotnull']);

		// Skip generated columns - they are computed automatically
		$isGenerated = ($attrs->fields['attgenerated'] ?? '') === 's';
		if ($isGenerated) {
			$attrs->moveNext();
			continue;
		}

		$id = (($i & 1) == 0 ? '1' : '2');

		// Initialise variables
		//if (!isset($_REQUEST['format'][$attrs->fields['attname']]))
		//	$_REQUEST['format'][$attrs->fields['attname']] = 'VALUE';

		if ($initial) {
			if ($insert) {
				$value = $attrs->fields['adsrc'];
				if (!empty($value)) {
					$search = str_replace("()", " ()", strtoupper($value));
					if ($search === 'NOW ()') {
						$search2 = 'CURRENT_TIMESTAMP';
					} elseif ($search === 'CURRENT_TIMESTAMP') {
						$search2 = 'NOW ()';
					} else {
						$search2 = '';
					}
					$function = $all_functions[$search] ?? $all_functions[$search2] ?? null;
					if (!empty($function)) {
						// use function
						$_REQUEST['format'][$attrs->fields['attname']] = $function;
						$value = '';
					} else {
						// use expression
						$_REQUEST['expr'][$attrs->fields['attname']] = 1;
					}
					//$_REQUEST['expr'][$attrs->fields['attname']] = 1;
				}
			} else {
				$value = $rs->fields[$attrs->fields['attname']];
			}
		} else {
			$value = $_REQUEST["values"][$attrs->fields['attname']];
		}

		echo "<tr class=\"data{$id}\">\n";
		//echo "<td class=\"info\">#", $i+1, "</td>";
		echo "<th>", htmlspecialchars($attrs->fields['attname']), "</th>";
		echo "<td>\n";
		echo htmlspecialchars($attrs->fields['type']);
		//echo "<input type=\"hidden\" name=\"types[", htmlspecialchars($attrs->fields['attname']), "]\" value=\"", htmlspecialchars($attrs->fields['type']), "\" /></td>";
		echo "<td>\n";
		$formRenderer->printFieldFunctions(
			"format[{$attrs->fields['attname']}]",
			$_REQUEST['format'][$attrs->fields['attname']] ?? '',
			[
				'id' => "sel_fnc_" . htmlspecialchars($attrs->fields['attname'])
			],
		);
		echo "</td>\n";
		echo "<td class=\"text-center\">";
		// Output null box if the column allows nulls (doesn't look at CHECKs or ASSERTIONS)
		if (!$attrs->fields['attnotnull']) {
			// Set initial null values
			if ($initial && ($insert || $rs->fields[$attrs->fields['attname']] === null)) {
				$_REQUEST['nulls'][$attrs->fields['attname']] = 'on';
			}
			$null_cb_id = "cb_null_" . htmlspecialchars($attrs->fields['attname']);
			echo "<label><span><input type=\"checkbox\" name=\"nulls[{$attrs->fields['attname']}]\" id=\"$null_cb_id\"",
				isset($_REQUEST['nulls'][$attrs->fields['attname']]) ? ' checked="checked"' : '', " /></span></label>\n";
		} else {
			echo "&nbsp;";
			$null_cb_id = "";
		}
		echo "</td>\n";

		echo "<td id=\"row_att_{$attrs->fields['attnum']}\">";

		$extras = [
			'data-field' => $attrs->fields['attname'],
		];

		//$extras['onChange'] = 'document.getElementById("' . $sel_fnc_id . '").value = "";';

		// If the column allows nulls, then we put a JavaScript action on
		// the data field to unset the NULL checkbox as soon as anything
		// is entered in the field.
		if (!$attrs->fields['attnotnull']) {
			$extras['onChange'] = 'document.getElementById("' . $null_cb_id . '").checked = false;';
		}

		if (($fksprops !== false) && isset($fksprops['byfield'][$attrs->fields['attnum']])) {
			$extras['id'] = "attr_{$attrs->fields['attnum']}";
			$extras['autocomplete'] = 'off';
			$extras['data-fk-context'] = 'insert';
			$extras['data-attnum'] = $attrs->fields['attnum'];
		}

		$type = $attrs->fields['type'] ?? '';
		$options = [
			'is_large_type' => $typeActions->isLargeTypeMeta($typeMetas[$type]),
		];
		if (strpos($type, 'bytea') === 0) {
			$downloadUrl = null;
			if (!$insert && !empty($keyFields)) {
				$params = [
					'action' => 'downloadbytea',
					'server' => $_REQUEST['server'],
					'database' => $_REQUEST['database'],
					'schema' => $schema,
					'table' => $_REQUEST['table'],
					'column' => $attrs->fields['attname'],
					'key' => $keyFields,
					'output' => 'download', // for frameset.js to detect
				];
				$downloadUrl = 'display.php?' . http_build_query($params);
			}
			$options += [
				'is_insert' => $insert,
				'size' => $byteaSizes[$attrs->fields['attname']] ?? null,
				'limit' => $byteaInlineLimit,
				'download_url' => $downloadUrl,
				'max_upload_size' => $byteaMaxUploadSize,
			];
			if ($initial && !empty($value) && !str_starts_with($value, '\\x')) {
				$value = '\\x' . bin2hex($value);
			}
		}

		$formRenderer->printField(
			"values[{$attrs->fields['attname']}]",
			$value,
			$attrs->fields['type'],
			$extras,
			$options
		);

		echo "</td>";
		echo "<td class=\"text-center\">\n";
		$expr_cb_id = "cb_expr_" . htmlspecialchars($attrs->fields['attname']);
		echo "<label><span><input type=\"checkbox\" id=\"$expr_cb_id\" name=\"expr[{$attrs->fields['attname']}]\"",
			!empty($_REQUEST['expr'][$attrs->fields['attname']]) ? ' checked="checked"' : '', " /></span></label>\n";
		echo "</td>";
		echo "</tr>\n";
		$i++;
		$attrs->moveNext();
	}
	echo "</tbody>\n";
	echo "</table>\n";

	echo "<input type=\"hidden\" name=\"action\" value=\"editrow\" />\n";
	echo $misc->form;
	if ($insert) {
		if (!isset($_SESSION['counter']))
			$_SESSION['counter'] = 0;
		echo "<input type=\"hidden\" name=\"protection_counter\" value=\"" . $_SESSION['counter'] . "\" />\n";
	} else {
		foreach ($keyFields as $field => $val) {
			echo "<input type=\"hidden\" name=\"key[", htmlspecialchars($field), "]\" value=\"", htmlspecialchars($val), "\" />\n";
		}
		//echo "<input type=\"hidden\" name=\"key\" value=\"", html_esc(urlencode(serialize($keyFields))), "\" />\n";
	}
	if (isset($_REQUEST['table']))
		echo "<input type=\"hidden\" name=\"table\" value=\"", htmlspecialchars($_REQUEST['table']), "\" />\n";
	if (isset($_REQUEST['subject']))
		echo "<input type=\"hidden\" name=\"subject\" value=\"", htmlspecialchars($_REQUEST['subject']), "\" />\n";
	if (isset($_REQUEST['query']))
		echo "<input type=\"hidden\" name=\"query\" value=\"", htmlspecialchars($_REQUEST['query']), "\" />\n";
	if (isset($_REQUEST['count']))
		echo "<input type=\"hidden\" name=\"count\" value=\"", htmlspecialchars($_REQUEST['count']), "\" />\n";
	if (isset($_REQUEST['return']))
		echo "<input type=\"hidden\" name=\"return\" value=\"", htmlspecialchars($_REQUEST['return']), "\" />\n";
	if (isset($_REQUEST['page']))
		echo "<input type=\"hidden\" name=\"page\" value=\"", htmlspecialchars($_REQUEST['page']), "\" />\n";
	if (isset($_REQUEST['orderby'])) {
		foreach ($_REQUEST['orderby'] as $field => $val) {
			echo "<input type=\"hidden\" name=\"orderby[", htmlspecialchars($field), "]\" value=\"", htmlspecialchars($val), "\" />\n";
		}
	}
	if (isset($_REQUEST['strings']))
		echo "<input type=\"hidden\" name=\"strings\" value=\"", htmlspecialchars($_REQUEST['strings']), "\" />\n";

	echo "<p>";
	if ($insert) {
		echo "<input type=\"submit\" name=\"insert\" value=\"{$lang['strinsert']}\" />\n";
		echo "<input type=\"submit\" name=\"insert_and_repeat\" accesskey=\"r\" value=\"{$lang['strinsertandrepeat']}\" />\n";
	} else {
		echo "<input type=\"submit\" name=\"save\" accesskey=\"r\" value=\"{$lang['strsave']}\" />\n";
	}
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";

	if ($fksprops !== false) {
		echo "&nbsp;&nbsp;&nbsp;";
		if ($conf['autocomplete'] != 'default off')
			echo "<input type=\"checkbox\" id=\"no_ac\" value=\"1\" checked=\"checked\" /> <label for=\"no_ac\"> {$lang['strac']}</label>\n";
		else
			echo "<input type=\"checkbox\" id=\"no_ac\" value=\"0\" /> <label for=\"no_ac\"> {$lang['strac']}</label>\n";
	}

	echo "</p>\n";
	echo "</form>\n";
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDelRow($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);

	if ($confirm) {
		$misc->printTrail($_REQUEST['subject']);
		$misc->printTitle($lang['strdeleterow']);

		$pg->conn->SetFetchMode(ADODB_FETCH_NUM);
		$rs = $rowActions->browseRow($_REQUEST['table'], $_REQUEST['key']);
		$pg->conn->SetFetchMode(ADODB_FETCH_ASSOC);

		echo "<form action=\"display.php\" method=\"post\">\n";
		echo $misc->form;

		if ($rs->recordCount() == 1) {
			echo "<p>{$lang['strconfdeleterow']}</p>\n";

			$rowBrowser = new RowBrowserRenderer();
			$fkinfo = [];
			echo "<table><tr>";
			$rowBrowser->printTableHeaderCells($rs, false, true);
			echo "</tr>";
			echo "<tr class=\"data1\">\n";
			$rowBrowser->printTableRowCells($rs, $fkinfo, true);
			echo "</tr>\n";
			echo "</table>\n";
			echo "<br />\n";

			echo "<input type=\"hidden\" name=\"action\" value=\"delrow\" />\n";
			echo "<input type=\"submit\" name=\"yes\" value=\"{$lang['stryes']}\" />\n";
			echo "<input type=\"submit\" name=\"no\" value=\"{$lang['strno']}\" />\n";
		} elseif ($rs->recordCount() != 1) {
			echo "<p>{$lang['strrownotunique']}</p>\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		} else {
			echo "<p>{$lang['strinvalidparam']}</p>\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" />\n";
		}
		if (isset($_REQUEST['table']))
			echo "<input type=\"hidden\" name=\"table\" value=\"", html_esc($_REQUEST['table']), "\" />\n";
		if (isset($_REQUEST['subject']))
			echo "<input type=\"hidden\" name=\"subject\" value=\"", html_esc($_REQUEST['subject']), "\" />\n";
		if (isset($_REQUEST['query']))
			echo "<input type=\"hidden\" name=\"query\" value=\"", html_esc($_REQUEST['query']), "\" />\n";
		if (isset($_REQUEST['count']))
			echo "<input type=\"hidden\" name=\"count\" value=\"", html_esc($_REQUEST['count']), "\" />\n";
		if (isset($_REQUEST['return']))
			echo "<input type=\"hidden\" name=\"return\" value=\"", html_esc($_REQUEST['return']), "\" />\n";
		echo "<input type=\"hidden\" name=\"page\" value=\"", html_esc($_REQUEST['page']), "\" />\n";
		if (isset($_REQUEST['orderby'])) {
			foreach ($_REQUEST['orderby'] as $key => $val) {
				echo "<input type=\"hidden\" name=\"orderby[", htmlspecialchars($key), "]\" value=\"", htmlspecialchars($val), "\" />\n";
			}
		}
		echo "<input type=\"hidden\" name=\"strings\" value=\"", html_esc($_REQUEST['strings']), "\" />\n";
		echo "<input type=\"hidden\" name=\"key\" value=\"", html_esc(urlencode(serialize($_REQUEST['key']))), "\" />\n";
		echo "</form>\n";
	} else {
		$status = $rowActions->deleteRow($_POST['table'], unserialize(urldecode($_POST['key'])));
		if ($status == 0)
			doBrowse($lang['strrowdeleted']);
		elseif ($status == -2)
			doBrowse($lang['strrownotunique']);
		else
			doBrowse($lang['strrowdeletedbad']);
	}
}

/**
 * Download bytea field data
 */
function doDownloadBytea()
{
	$pg = AppContainer::getPostgres();
	$conf = AppContainer::getConf();
	$tableActions = new TableActions($pg);
	$schemaActions = new SchemaActions($pg);

	// Validate required parameters
	if (empty($_REQUEST['table']) || empty($_REQUEST['column']) || empty($_REQUEST['schema'])) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Missing required parameters';
		exit;
	}

	if (empty($_REQUEST['key']) || !is_array($_REQUEST['key'])) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Missing key fields';
		exit;
	}

	$table = $_REQUEST['table'];
	$column = $_REQUEST['column'];
	$schema = $_REQUEST['schema'];
	$keyFields = $_REQUEST['key'];

	// Ensure schema context for attribute checks
	$schemaActions->setSchema($schema);

	// Verify column exists and is bytea type
	$attrs = $tableActions->getTableAttributes($table);
	$columnExists = false;
	$isBytea = false;

	if ($attrs && $attrs->recordCount() > 0) {
		while (!$attrs->EOF) {
			if ($attrs->fields['attname'] === $column) {
				$columnExists = true;
				$type = $attrs->fields['type'] ?? '';
				$isBytea = (strpos($type, 'bytea') === 0);
				break;
			}
			$attrs->moveNext();
		}
	}

	if (!$columnExists || !$isBytea) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Invalid column or not bytea type';
		exit;
	}

	// Build WHERE clause from key fields
	$whereParts = [];
	foreach ($keyFields as $field => $value) {
		if ($value === null || (is_string($value) && strcasecmp($value, 'NULL') === 0)) {
			$whereParts[] = $pg->escapeIdentifier($field) . ' IS NULL';
		} else {
			$whereParts[] = $pg->escapeIdentifier($field) . ' = ' . $pg->clean($value);
		}
	}
	$whereClause = implode(' AND ', $whereParts);

	$sanitizePart = function ($value) {
		return preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) $value);
	};
	$timestamp = date('Ymd_His');
	$keyParts = [];
	foreach ($keyFields as $field => $value) {
		$keyParts[] = $sanitizePart($field . '_' . ($value === null ? 'null' : $value));
	}
	$filenameParts = [
		$sanitizePart($table),
		$sanitizePart($column),
	];
	if (!empty($keyParts)) {
		$filenameParts[] = implode('-', $keyParts);
	}
	$filenameParts[] = $timestamp;
	$filename = implode('-', array_filter($filenameParts)) . '.dat';

	$sizeSql = 'SELECT octet_length(' . $pg->escapeIdentifier($column) . ') AS size' .
		' FROM ' . $pg->escapeIdentifier($schema) . '.' . $pg->escapeIdentifier($table) .
		' WHERE ' . $whereClause .
		' LIMIT 1';
	$sizeResult = $pg->selectSet($sizeSql);
	if (!$sizeResult || $sizeResult->recordCount() !== 1) {
		header('HTTP/1.0 404 Not Found');
		echo 'Data not found';
		exit;
	}
	$totalSize = $sizeResult->fields['size'];
	if ($totalSize === null) {
		header('HTTP/1.0 404 Not Found');
		echo 'Data is NULL';
		exit;
	}
	$totalSize = (int) $totalSize;

	$chunkSize = $conf['bytea_download_chunk_size'] ?? 5 * 1024 * 1024;

	$range = $_SERVER['HTTP_RANGE'] ?? '';
	$hasRange = false;
	$start = 0;
	$end = $totalSize - 1;

	if ($range && preg_match('/bytes=(\d+)-(\d*)/i', $range, $matches)) {
		$hasRange = true;
		$start = (int) $matches[1];
		$end = ($matches[2] !== '') ? (int) $matches[2] : ($totalSize - 1);
		if ($end >= $totalSize) {
			$end = $totalSize - 1;
		}
		if ($start >= $totalSize || $start < 0 || $end < $start) {
			header('HTTP/1.1 416 Range Not Satisfiable');
			header('Content-Range: bytes */' . $totalSize);
			exit;
		}
	}

	$length = $end - $start + 1;

	if ($hasRange) {
		header('HTTP/1.1 206 Partial Content');
		header('Content-Range: bytes ' . $start . '-' . $end . '/' . $totalSize);
	} else {
		header('HTTP/1.1 200 OK');
	}

	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	header('Accept-Ranges: bytes');
	header('Content-Length: ' . $length);
	header('Cache-Control: must-revalidate');
	header('Pragma: public');

	for ($offset = $start; $offset <= $end; $offset += $chunkSize) {
		$remaining = $end - $offset + 1;
		$readLen = ($remaining > $chunkSize) ? $chunkSize : $remaining;
		$sqlOffset = $offset + 1;
		$chunkSql = 'SELECT substring(' . $pg->escapeIdentifier($column) . ' FROM ' . $sqlOffset . ' FOR ' . $readLen . ') AS chunk' .
			' FROM ' . $pg->escapeIdentifier($schema) . '.' . $pg->escapeIdentifier($table) .
			' WHERE ' . $whereClause .
			' LIMIT 1';
		$chunkResult = $pg->selectSet($chunkSql);
		if (!$chunkResult || $chunkResult->recordCount() !== 1) {
			header('HTTP/1.0 404 Not Found');
			echo 'Data not found';
			exit;
		}
		$chunk = $chunkResult->fields['chunk'];
		if ($chunk === null) {
			header('HTTP/1.0 404 Not Found');
			echo 'Data is NULL';
			exit;
		}
		echo $chunk;
		flush();
	}

	exit;
}


/* Print the FK row, used in ajax requests */
function doBrowseFK()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$rowActions = new RowActions($pg);
	$rowBrowser = new RowBrowserRenderer();

	$ops = [];
	foreach ($_REQUEST['fkey'] as $x => $y) {
		$ops[$x] = '=';
	}

	$query = $pg->getSelectSQL($_REQUEST['table'], [], $_REQUEST['fkey'], $ops);
	$_REQUEST['query'] = $query;

	$fkinfo = $rowBrowser->getFKInfo();

	$max_pages = 1;
	// Retrieve page from query.  $max_pages is returned by reference.
	$rs = $rowActions->browseQuery(
		'SELECT',
		$_REQUEST['table'],
		$_REQUEST['query'],
		null,
		1,
		1,
		$max_pages
	);

	echo "<a href=\"#\" style=\"display:table-cell;\" class=\"fk_close\"><img alt=\"[close]\" src=\"" . $misc->icon('Close') . "\" /></a>\n";
	echo "<div style=\"display:table-cell;\">";

	if (is_object($rs) && $rs->recordCount() > 0) {
		/* we are browsing a referenced table here
		 * we should show OID if show_oids is true
		 * so we give true to withOid in functions below
		 * as 3rd parameter */

		echo "<table><tr>";
		$rowBrowser->printTableHeaderCells($rs, false, true);
		echo "</tr>";
		echo "<tr class=\"data1\">\n";
		$rowBrowser->printTableRowCells($rs, $fkinfo, true);
		echo "</tr>\n";
		echo "</table>\n";
	} else
		echo $lang['strnodata'];

	echo "</div>";

	exit;
}

/**
 * Displays requested data
 */
function doBrowse($msg = '')
{
	(new RowBrowserRenderer())->doBrowse($msg);
}

function popupEdit()
{
	$pg = AppContainer::getPostgres();
	$tableActions = new TableActions($pg);
	$schemaActions = new SchemaActions($pg);
	$typeActions = new TypeActions($pg);
	$formRenderer = new FormRenderer();
	$lang = AppContainer::getLang();

	// Check required parameters
	if (empty($_REQUEST['field']) || empty($_REQUEST['schema']) || empty($_REQUEST['table']) || empty($_REQUEST['keys'])) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Missing required parameters';
		exit;
	}

	$field = $_REQUEST['field'];
	$schema = $_REQUEST['schema'];
	$table = $_REQUEST['table'];
	$keys = is_array($_REQUEST['keys']) ? $_REQUEST['keys'] : json_decode($_REQUEST['keys'], true);

	if (!is_array($keys) || empty($keys)) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Invalid keys parameter';
		exit;
	}

	// Set schema context
	$schemaActions->setSchema($schema);

	// Get field metadata
	$attrs = $tableActions->getTableAttributes($table);
	$fieldInfo = null;

	if ($attrs && $attrs->recordCount() > 0) {
		while (!$attrs->EOF) {
			if ($attrs->fields['attname'] === $field) {
				$fieldInfo = $attrs->fields;
				break;
			}
			$attrs->moveNext();
		}
	}

	if (!$fieldInfo) {
		header('HTTP/1.0 404 Not Found');
		echo 'Field not found';
		exit;
	}

	$type = $fieldInfo['type'] ?? 'text';

	// Check type blacklist (bytea and array types)
	if (strpos($type, 'bytea') === 0) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Field type not supported for inline editing';
		exit;
	}

	$isGenerated = ($fieldInfo['attgenerated'] ?? '') === 's';
	if ($isGenerated) {
		header('HTTP/1.0 400 Bad Request');
		echo 'Generated columns cannot be edited';
		exit;
	}

	$metas = $typeActions->getTypeMetasByNames([$type]);
	//var_dump($metas[$type]);
	$isLargeType = isset($metas[$type])
		? $typeActions->isLargeTypeMeta($metas[$type])
		: false;

	// Fetch actual field value from database using keys
	$whereParts = [];
	foreach ($keys as $keyField => $keyValue) {
		if ($keyValue === null) {
			// Does this actually make sense for key fields?
			$whereParts[] = $pg->quoteIdentifier($keyField) . ' IS NULL';
		} else {
			$whereParts[] = $pg->quoteIdentifier($keyField) . ' = ' . $pg->escapeLiteral($keyValue);
		}
	}

	if (empty($whereParts)) {
		header('HTTP/1.0 400 Bad Request');
		echo 'No valid key fields provided';
		exit;
	}

	$whereClause = implode(' AND ', $whereParts);
	$valueSql = 'SELECT ' . $pg->quoteIdentifier($field) . ' FROM ' .
		$pg->quoteIdentifier($schema) . '.' . $pg->quoteIdentifier($table) .
		' WHERE ' . $whereClause . ' LIMIT 1';

	$valueResult = $pg->selectSet($valueSql);
	if (!$valueResult || $valueResult->recordCount() !== 1) {
		header('HTTP/1.0 404 Not Found');
		echo 'Row not found';
		exit;
	}

	$value = $valueResult->fields[$field];

	$extras = [
		'data-field' => $field,
		'class' => 'popup-field-input',
		'autofocus' => 'autofocus',
	];

	echo '<div class="popup-field-editor p-2">';
	echo '<div class="popup-field-label mb-1">' . htmlspecialchars($field) . '</div>';
	$options = [
		'is_large_type' => $isLargeType,
	];
	$formRenderer->printField('value', $value, $type, $extras, $options);

	$nullChecked = isset($value) ? '' : ' checked';
	echo '<div class="popup-field-options">';
	$formRenderer->printFieldFunctions(
		"_function",
		'',
		[
			'id' => 'popup-function-sel',
			'class' => 'my-2'
		],
	);
	if (!$pg->phpBool($fieldInfo['attnotnull'])) {
		echo "<label class=\"mr-2\"><input type=\"checkbox\" name=\"_isnull\" id=\"popup-null-cb\" class=\"mr-1\"{$nullChecked}>" . htmlspecialchars($lang['strnull']) . "</label> ";
	}
	echo '<label><input type="checkbox" name="_isexpr" id="popup-expr-cb" class="mr-1"> ' . htmlspecialchars($lang['strexpression']) . '</label>';
	// Todo: add a maximize button/icon
	//echo '<button class="popup-maximize-btn" id="popup-maximize-btn">🗖</button>';
	echo '</div>';
	echo '</div>';

	exit;
}


// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();
$conf = AppContainer::getConf();

$action = $_REQUEST['action'] ?? '';

// Actions that don't require header and body
switch ($action) {
	case 'dobrowsefk':
		doBrowseFK();
		break;
	case 'downloadbytea':
		doDownloadBytea();
		break;
	case 'popupedit':
		popupEdit();
		break;
}

// Set the title based on the subject of the request
$subject_type = $_REQUEST['subject'] ?? '';
$subject_name = $_REQUEST[$subject_type] ?? '';
if (!empty($subject_name)) {
	switch ($subject_type) {
		case 'table':
			$title = $lang['strtables'] . ': ' . $subject_name;
			break;
		case 'view':
			$title = $lang['strviews'] . ': ' . $subject_name;
			break;
		case 'column':
			$title = $lang['strcolumn'] . ': ' . $subject_name;
			break;
	}
} else {
	$title = $lang['strqueryresults'];
}

$misc->printHeader($title ?? '');
$misc->printBody();

switch ($action) {
	case 'editrow':
	case 'insertrow':
		if (isset($_POST['cancel']))
			doBrowse();
		else
			doEditRow(false);
		break;
	case 'confeditrow':
	case 'confinsertrow':
		doEditRow(true);
		break;
	case 'delrow':
		if (isset($_POST['yes']))
			doDelRow(false);
		else
			doBrowse();
		break;
	case 'confdelrow':
		doDelRow(true);
		break;
	default:
		doBrowse();
		break;
}

$misc->printFooter();
