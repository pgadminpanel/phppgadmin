<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\FunctionActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\TypeActions;

/**
 * Manage types in a database
 *
 * $Id: types.php,v 1.42 2007/11/30 15:25:23 soranzo Exp $
 */

// Include application functions
require_once __DIR__ . '/libraries/bootstrap.php';

/**
 * Show read only properties for a type
 */
function doProperties($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);
	$tableActions = new TableActions($pg);

	// Get type (using base name)
	$typedata = $typeActions->getType($_REQUEST['type']);

	$misc->printTrail('type');
	$misc->printTitle($lang['strproperties'], 'pg.type');
	$misc->printMsg($msg);

	function attPre($rowdata)
	{
		$pg = AppContainer::getPostgres();
		$rowdata->fields['+type'] = $pg->formatType($rowdata->fields['type'], $rowdata->fields['atttypmod']);
	}

	if ($typedata->recordCount() > 0) {
		$vals = false;
		switch ($typedata->fields['typtype']) {
			case 'c':
				$attrs = $tableActions->getTableAttributes($_REQUEST['type']);

				$columns = [
					'field' => [
						'title' => $lang['strfield'],
						'field' => field('attname'),
					],
					'type' => [
						'title' => $lang['strtype'],
						'field' => field('+type'),
					],
					'comment' => [
						'title' => $lang['strcomment'],
						'field' => field('comment'),
					]
				];

				$actions = [];

				$misc->printTable($attrs, $columns, $actions, 'types-properties', null, 'attPre');

				break;
			case 'e':
				$vals = $typeActions->getEnumValues($typedata->fields['typname']);
			default:
				$byval = $pg->phpBool($typedata->fields['typbyval']);
				echo "<table>\n";
				echo "<tr><th class=\"data left\">{$lang['strname']}</th>\n";
				echo "<td class=\"data1\">", $misc->formatVal($typedata->fields['typname']), "</td></tr>\n";
				echo "<tr><th class=\"data left\">{$lang['strinputfn']}</th>\n";
				echo "<td class=\"data1\">", $misc->formatVal($typedata->fields['typin']), "</td></tr>\n";
				echo "<tr><th class=\"data left\">{$lang['stroutputfn']}</th>\n";
				echo "<td class=\"data1\">", $misc->formatVal($typedata->fields['typout']), "</td></tr>\n";
				echo "<tr><th class=\"data left\">{$lang['strlength']}</th>\n";
				echo "<td class=\"data1\">", $misc->formatVal($typedata->fields['typlen']), "</td></tr>\n";
				echo "<tr><th class=\"data left\">{$lang['strpassbyval']}</th>\n";
				echo "<td class=\"data1\">", ($byval) ? $lang['stryes'] : $lang['strno'], "</td></tr>\n";
				echo "<tr><th class=\"data left\">{$lang['stralignment']}</th>\n";
				echo "<td class=\"data1\">", $misc->formatVal($typedata->fields['typalign']), "</td></tr>\n";
				if ($typeActions->hasEnumTypes() && $vals) {
					$vals = $vals->getArray();
					$nbVals = count($vals);
					echo "<tr>\n\t<th class=\"data left\" rowspan=\"$nbVals\">{$lang['strenumvalues']}</th>\n";
					echo "<td class=\"data2\">{$vals[0]['enumval']}</td></tr>\n";
					for ($i = 1; $i < $nbVals; $i++)
						echo "<td class=\"data", 2 - ($i % 2), "\">{$vals[$i]['enumval']}</td></tr>\n";
				}
				echo "</table>\n";
		}

		$navlinks = [
			'showall' => [
				'attr' => [
					'href' => [
						'url' => 'types.php',
						'urlvars' => [
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
						]
					]
				],
				'icon' => $misc->icon('Return'),
				'content' => $lang['strshowalltypes']
			],
			'alter' => [
				'attr' => [
					'href' => [
						'url' => 'types.php',
						'urlvars' => [
							'action' => 'alter',
							'server' => $_REQUEST['server'],
							'database' => $_REQUEST['database'],
							'schema' => $_REQUEST['schema'],
							'type' => $_REQUEST['type'],
						]
					]
				],
				'icon' => $misc->icon('Edit'),
				'content' => $lang['stredit']
			],
		];

		$misc->printNavLinks($navlinks, 'types-properties', get_defined_vars());
	} else
		doDefault($lang['strinvalidparam']);
}

/**
 * Alters a type (rename, owner change, enum values)
 */
function doAlter($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);
	$roleActions = new RoleActions($pg);

	// Get type (using base name)
	$typedata = $typeActions->getType($_REQUEST['type']);

	if ($typedata->recordCount() === 0) {
		doDefault($lang['strinvalidparam']);
		return;
	}

	$typname = $typedata->fields['typname'];
	$typtype = $typedata->fields['typtype'];
	$typowner = $typedata->fields['typowner'];
	$typcomment = $typedata->fields['typcomment'] ?? '';
	//var_dump($typedata->fields);

	// Initialize POST variables if not set
	if (!isset($_POST['name']))
		$_POST['name'] = $typname;
	if (!isset($_POST['owner']))
		$_POST['owner'] = $typowner;
	if (!isset($_POST['typcomment']))
		$_POST['typcomment'] = $typcomment;
	if (!isset($_POST['newEnumValues']))
		$_POST['newEnumValues'] = [];
	if (!isset($_POST['renameEnumOld']))
		$_POST['renameEnumOld'] = [];
	if (!isset($_POST['renameEnumNew']))
		$_POST['renameEnumNew'] = [];

	$misc->printTrail('type');
	$misc->printTitle($lang['stredit'] . ' - ' . $misc->formatVal($typname), 'pg.type.alter');
	$misc->printMsg($msg);

	echo "<form action=\"types.php\" method=\"post\">\n";
	echo "<table>\n";
	echo "<tr><th class=\"data left required\">{$lang['strname']}</th>\n";
	echo "<td class=\"data1\"><input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
		html_esc($_POST['name']), "\" /></td></tr>\n";

	// Owner change (superusers only)
	if ($roleActions->isSuperUser()) {
		$users = $roleActions->getUsers();
		echo "<tr><th class=\"data left required\">{$lang['strowner']}</th>\n";
		echo "<td class=\"data1\"><select name=\"owner\">\n";
		foreach ($users as $user) {
			$uname = $user['usename'];
			echo "<option value=\"", htmlspecialchars($uname), "\"", ($uname == $_POST['owner']) ? ' selected="selected"' : '', ">",
				htmlspecialchars($uname), "</option>\n";
		}
		echo "</select></td></tr>\n";
	}

	// Comment
	echo "<tr><th class=\"data left\">{$lang['strcomment']}</th>\n";
	echo "<td class=\"data1\"><input name=\"typcomment\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
		html_esc($_POST['typcomment']), "\" /></td></tr>\n";

	// Enum value management
	if ($typtype === 'e' && $typeActions->hasEnumTypes()) {
		$enumValues = $typeActions->getEnumValues($typname);

		// Display existing enum values
		if ($enumValues->recordCount() > 0) {
			$enumValues = $enumValues->getArray();
			$nbVals = count($enumValues);

			echo "<tr><th class=\"data left\">{$lang['strenumvalues']}</th>\n";
			echo "<td class=\"data1\">\n";
			echo "<table style=\"border: 1px solid #999; width: 100%;\">\n";
			echo "<tr><th class=\"data left\" style=\"width: 50%;\">{$lang['strvalue']}</th>";
			if ($typeActions->hasTypeRenameValue()) {
				echo "<th class=\"data left\" style=\"width: 50%;\">{$lang['strrenamevalue']}</th>";
			}
			echo "</tr>\n";

			for ($i = 0; $i < $nbVals; $i++) {
				echo "<tr><td class=\"data\">" . htmlspecialchars($enumValues[$i]['enumval']) . "</td>";
				if ($typeActions->hasTypeRenameValue()) {
					$newValue = htmlspecialchars($_POST['renameEnumNew'][$i] ?? '');
					echo "<td class=\"data\">\n";
					echo "<input type=\"hidden\" name=\"renameEnumOld[$i]\" value=\"", htmlspecialchars($enumValues[$i]['enumval']), "\" />\n";
					echo "<input name=\"renameEnumNew[$i]\" size=\"24\" value=\"$newValue\" placeholder=\"{$lang['strnewname']}\" />\n";
					echo "</td>";
				}

				echo "</tr>\n";
			}

			echo "</table>\n";
		}

		// Add new enum values
		if ($typeActions->hasTypeAddValue()) {
			echo "<tr><th class=\"data left\">{$lang['straddenumvalue']}</th>\n";
			echo "<td class=\"data1\">\n";
			for ($i = 0; $i < 5; $i++) {
				$newValue = htmlspecialchars($_POST['newEnumValues'][$i] ?? '');
				echo "<input name=\"newEnumValues[$i]\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" placeholder=\"{$lang['strvalue']}\" value=\"$newValue\" /><br />\n";
			}
		}
	}

	echo "</table>\n";
	echo "<p><input type=\"hidden\" name=\"action\" value=\"save_alter\" />\n";
	echo "<input type=\"hidden\" name=\"type\" value=\"", html_esc($_REQUEST['type']), "\" />\n";
	echo $misc->form;
	echo "<input type=\"submit\" value=\"{$lang['strsave']}\" />\n";
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
	echo "</form>\n";
}

/**
 * Save altered type
 */
function doSaveAlter()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$roleActions = new RoleActions($pg);
	$typeActions = new TypeActions($pg);

	if (isset($_POST['cancel']) || !isset($_POST['type'])) {
		doDefault();
		return;
	}

	$originalType = $_POST['type'];
	$newName = trim($_POST['name'] ?? '');
	$newOwner = trim($_POST['owner'] ?? '');

	// Validate name
	if ($newName === '') {
		doAlter($lang['strtypeneedsname']);
		return;
	}

	$pg->beginTransaction();

	// Rename type if name changed
	if ($newName !== $originalType) {
		$status = $typeActions->renameType($originalType, $newName);
		if ($status != 0) {
			$pg->rollbackTransaction();
			doAlter($lang['strtyperenamedbad']);
			return;
		}
	}

	// Change owner if superuser selected one
	if ($roleActions->isSuperUser() && $newOwner) {
		$status = $typeActions->changeTypeOwner($newName, $newOwner);
		if ($status != 0) {
			$pg->rollbackTransaction();
			doAlter($lang['strtypeownerbad']);
			return;
		}
	}

	// Handle enum value changes
	$typetype = null;
	$typedata = $typeActions->getType($newName);
	if ($typedata->recordCount() > 0) {
		$typetype = $typedata->fields['typtype'];
	}

	if ($typetype === 'e' && $typeActions->hasEnumTypes()) {
		// Handle enum value renames
		if ($typeActions->hasTypeRenameValue() && isset($_POST['renameEnumOld'])) {
			foreach ($_POST['renameEnumOld'] as $i => $oldValue) {
				$oldValue = trim($oldValue);
				$newValue = trim($_POST['renameEnumNew'][$i] ?? '');

				if ($newValue && $newValue !== $oldValue) {
					$status = $typeActions->renameEnumTypeValue($newName, $oldValue, $newValue);
					if ($status != 0) {
						$pg->rollbackTransaction();
						$msg = format_string($lang['strenumvaluerenamedbad'], [
							'old' => $oldValue,
							'new' => $newValue
						]);
						doAlter($msg);
						return;
					}
				}
			}
		}

		// Handle adding new enum values
		if ($typeActions->hasTypeAddValue() && isset($_POST['newEnumValues'])) {
			foreach ($_POST['newEnumValues'] as $newValue) {
				$newValue = trim($newValue);
				if ($newValue) {
					$status = $typeActions->addEnumTypeValue($newName, $newValue);
					if ($status != 0) {
						$pg->rollbackTransaction();
						$msg = format_string($lang['strenumvalueaddedbad'], [
							'new' => $newValue
						]);
						doAlter($msg);
						return;
					}
				}
			}
		}
	}

	// Update comment
	$newComment = trim($_POST['typcomment'] ?? '');
	$status = $pg->setComment('TYPE', $newName, '', $newComment, true);
	if ($status != 0) {
		$pg->rollbackTransaction();
		doAlter($lang['strtypecreatedbad']);
		return;
	}

	$pg->endTransaction();
	AppContainer::setShouldReloadTree(true);
	doDefault($lang['strtypealtered']);
}

/**
 * Show confirmation of drop and perform actual drop
 */
function doDrop($confirm)
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);

	if ($confirm) {
		$misc->printTrail('type');
		$misc->printTitle($lang['strdrop'], 'pg.type.drop');

		echo "<p>", sprintf($lang['strconfdroptype'], $misc->formatVal($_REQUEST['type'])), "</p>\n";

		echo "<form action=\"types.php\" method=\"post\">\n";
		echo "<p><input type=\"checkbox\" id=\"cascade\" name=\"cascade\" /> <label for=\"cascade\">{$lang['strcascade']}</label></p>\n";
		echo "<p><input type=\"hidden\" name=\"action\" value=\"drop\" />\n";
		echo "<input type=\"hidden\" name=\"type\" value=\"", html_esc($_REQUEST['type']), "\" />\n";
		echo $misc->form;
		echo "<input type=\"submit\" name=\"drop\" value=\"{$lang['strdrop']}\" />\n";
		echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
		echo "</form>\n";
	} else {
		$status = $typeActions->dropType($_POST['type'], isset($_POST['cascade']));
		if ($status == 0)
			doDefault($lang['strtypedropped']);
		else
			doDefault($lang['strtypedroppedbad']);
	}
}

/**
 * Displays a screen where they can enter a new composite type
 */
function doCreateComposite($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);

	if (!isset($_REQUEST['stage']))
		$_REQUEST['stage'] = 1;
	if (!isset($_REQUEST['name']))
		$_REQUEST['name'] = '';
	if (!isset($_REQUEST['fields']))
		$_REQUEST['fields'] = '';
	if (!isset($_REQUEST['typcomment']))
		$_REQUEST['typcomment'] = '';

	switch ($_REQUEST['stage']) {
		case 1:
			$misc->printTrail('type');
			$misc->printTitle($lang['strcreatecomptype'], 'pg.type.create');
			$misc->printMsg($msg);

			echo "<form action=\"types.php\" method=\"post\">\n";
			echo "<table>\n";
			echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
			echo "\t\t<td class=\"data\"><input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
				html_esc($_REQUEST['name']), "\" /></td>\n\t</tr>\n";
			echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strnumfields']}</th>\n";
			echo "\t\t<td class=\"data\"><input name=\"fields\" size=\"5\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
				html_esc($_REQUEST['fields']), "\" /></td>\n\t</tr>\n";

			echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strcomment']}</th>\n";
			echo "\t\t<td><textarea name=\"typcomment\" rows=\"3\" cols=\"32\">",
				html_esc($_REQUEST['typcomment']), "</textarea></td>\n\t</tr>\n";

			echo "</table>\n";
			echo "<p><input type=\"hidden\" name=\"action\" value=\"create_comp\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"2\" />\n";
			echo $misc->form;
			echo "<input type=\"submit\" value=\"{$lang['strnext']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
			break;
		case 2:
			// Check inputs
			$fields = trim($_REQUEST['fields']);
			if (trim($_REQUEST['name']) == '') {
				$_REQUEST['stage'] = 1;
				doCreateComposite($lang['strtypeneedsname']);
				return;
			} elseif ($fields == '' || !is_numeric($fields) || $fields != (int) $fields || $fields < 1) {
				$_REQUEST['stage'] = 1;
				doCreateComposite($lang['strtypeneedscols']);
				return;
			}

			$types = $typeActions->getTypes(true, false, true);

			$misc->printTrail('schema');
			$misc->printTitle($lang['strcreatecomptype'], 'pg.type.create');
			$misc->printMsg($msg);

			echo "<form action=\"types.php\" method=\"post\">\n";

			// Output table header
			echo "<table>\n";
			echo "\t<tr><th colspan=\"2\" class=\"data required\">{$lang['strfield']}</th><th colspan=\"2\" class=\"data required\">{$lang['strtype']}</th>";
			echo "<th class=\"data\">{$lang['strlength']}</th><th class=\"data\">{$lang['strcomment']}</th></tr>\n";

			for ($i = 0; $i < $_REQUEST['fields']; $i++) {
				if (!isset($_REQUEST['field'][$i]))
					$_REQUEST['field'][$i] = '';
				if (!isset($_REQUEST['length'][$i]))
					$_REQUEST['length'][$i] = '';
				if (!isset($_REQUEST['colcomment'][$i]))
					$_REQUEST['colcomment'][$i] = '';

				echo "\t<tr>\n\t\t<td>", $i + 1, ".&nbsp;</td>\n";
				echo "\t\t<td><input name=\"field[{$i}]\" size=\"16\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
					html_esc($_REQUEST['field'][$i]), "\" /></td>\n";
				echo "\t\t<td>\n\t\t\t<select name=\"type[{$i}]\">\n";
				$types->moveFirst();
				while (!$types->EOF) {
					$typname = $types->fields['typname'];
					echo "\t\t\t\t<option value=\"", html_esc($typname), "\"", (isset($_REQUEST['type'][$i]) && $typname == $_REQUEST['type'][$i]) ? ' selected="selected"' : '', ">",
						$misc->formatVal($typname), "</option>\n";
					$types->moveNext();
				}
				echo "\t\t\t</select>\n\t\t</td>\n";

				// Output array type selector
				echo "\t\t<td>\n\t\t\t<select name=\"array[{$i}]\">\n";
				echo "\t\t\t\t<option value=\"\"", (isset($_REQUEST['array'][$i]) && $_REQUEST['array'][$i] == '') ? ' selected="selected"' : '', "></option>\n";
				echo "\t\t\t\t<option value=\"[]\"", (isset($_REQUEST['array'][$i]) && $_REQUEST['array'][$i] == '[]') ? ' selected="selected"' : '', ">[ ]</option>\n";
				echo "\t\t\t</select>\n\t\t</td>\n";

				echo "\t\t<td><input name=\"length[{$i}]\" size=\"10\" value=\"",
					html_esc($_REQUEST['length'][$i]), "\" /></td>\n";
				echo "\t\t<td><input name=\"colcomment[{$i}]\" size=\"40\" value=\"",
					html_esc($_REQUEST['colcomment'][$i]), "\" /></td>\n\t</tr>\n";
			}
			echo "</table>\n";
			echo "<p><input type=\"hidden\" name=\"action\" value=\"create_comp\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"3\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"name\" value=\"", html_esc($_REQUEST['name']), "\" />\n";
			echo "<input type=\"hidden\" name=\"fields\" value=\"", html_esc($_REQUEST['fields']), "\" />\n";
			echo "<input type=\"hidden\" name=\"typcomment\" value=\"", html_esc($_REQUEST['typcomment']), "\" />\n";
			echo "<input type=\"submit\" value=\"{$lang['strcreate']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";

			break;
		case 3:
			// Check inputs
			$fields = trim($_REQUEST['fields']);
			if (trim($_REQUEST['name']) == '') {
				$_REQUEST['stage'] = 1;
				doCreateComposite($lang['strtypeneedsname']);
				return;
			} elseif ($fields == '' || !is_numeric($fields) || $fields != (int) $fields || $fields <= 0) {
				$_REQUEST['stage'] = 1;
				doCreateComposite($lang['strtypeneedscols']);
				return;
			}

			$status = $typeActions->createCompositeType(
				$_REQUEST['name'],
				$_REQUEST['fields'],
				$_REQUEST['field'],
				$_REQUEST['type'],
				$_REQUEST['array'],
				$_REQUEST['length'],
				$_REQUEST['colcomment'],
				$_REQUEST['typcomment']
			);

			if ($status == 0)
				doDefault($lang['strtypecreated']);
			elseif ($status == -1) {
				$_REQUEST['stage'] = 2;
				doCreateComposite($lang['strtypeneedsfield']);
				return;
			} else {
				$_REQUEST['stage'] = 2;
				doCreateComposite($lang['strtypecreatedbad']);
				return;
			}
			break;
		default:
			echo "<p>{$lang['strinvalidparam']}</p>\n";
	}
}

/**
 * Displays a screen where they can enter a new enum type
 */
function doCreateEnum($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);

	if (!isset($_REQUEST['stage']))
		$_REQUEST['stage'] = 1;
	if (!isset($_REQUEST['name']))
		$_REQUEST['name'] = '';
	if (!isset($_REQUEST['values']))
		$_REQUEST['values'] = '';
	if (!isset($_REQUEST['typcomment']))
		$_REQUEST['typcomment'] = '';

	switch ($_REQUEST['stage']) {
		case 1:
			$misc->printTrail('type');
			$misc->printTitle($lang['strcreateenumtype'], 'pg.type.create');
			$misc->printMsg($msg);

			echo "<form action=\"types.php\" method=\"post\">\n";
			echo "<table>\n";
			echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strname']}</th>\n";
			echo "\t\t<td class=\"data\"><input name=\"name\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
				html_esc($_REQUEST['name']), "\" /></td>\n\t</tr>\n";
			echo "\t<tr>\n\t\t<th class=\"data left required\">{$lang['strnumvalues']}</th>\n";
			echo "\t\t<td class=\"data\"><input name=\"values\" size=\"5\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
				html_esc($_REQUEST['values']), "\" /></td>\n\t</tr>\n";

			echo "\t<tr>\n\t\t<th class=\"data left\">{$lang['strcomment']}</th>\n";
			echo "\t\t<td><textarea name=\"typcomment\" rows=\"3\" cols=\"32\">",
				html_esc($_REQUEST['typcomment']), "</textarea></td>\n\t</tr>\n";

			echo "</table>\n";
			echo "<p><input type=\"hidden\" name=\"action\" value=\"create_enum\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"2\" />\n";
			echo $misc->form;
			echo "<input type=\"submit\" value=\"{$lang['strnext']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";
			break;
		case 2:
			// Check inputs
			$values = trim($_REQUEST['values']);
			if (trim($_REQUEST['name']) == '') {
				$_REQUEST['stage'] = 1;
				doCreateEnum($lang['strtypeneedsname']);
				return;
			} elseif ($values == '' || !is_numeric($values) || $values != (int) $values || $values < 1) {
				$_REQUEST['stage'] = 1;
				doCreateEnum($lang['strtypeneedsvals']);
				return;
			}

			$misc->printTrail('schema');
			$misc->printTitle($lang['strcreateenumtype'], 'pg.type.create');
			$misc->printMsg($msg);

			echo "<form action=\"types.php\" method=\"post\">\n";

			// Output table header
			echo "<table>\n";
			echo "\t<tr><th colspan=\"2\" class=\"data required\">{$lang['strvalue']}</th></tr>\n";

			for ($i = 0; $i < $_REQUEST['values']; $i++) {
				if (!isset($_REQUEST['value'][$i]))
					$_REQUEST['value'][$i] = '';

				echo "\t<tr>\n\t\t<td>", $i + 1, ".&nbsp;</td>\n";
				echo "\t\t<td><input name=\"value[{$i}]\" size=\"16\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
					html_esc($_REQUEST['value'][$i]), "\" /></td>\n\t</tr>\n";
			}
			echo "</table>\n";
			echo "<p><input type=\"hidden\" name=\"action\" value=\"create_enum\" />\n";
			echo "<input type=\"hidden\" name=\"stage\" value=\"3\" />\n";
			echo $misc->form;
			echo "<input type=\"hidden\" name=\"name\" value=\"", html_esc($_REQUEST['name']), "\" />\n";
			echo "<input type=\"hidden\" name=\"values\" value=\"", html_esc($_REQUEST['values']), "\" />\n";
			echo "<input type=\"hidden\" name=\"typcomment\" value=\"", html_esc($_REQUEST['typcomment']), "\" />\n";
			echo "<input type=\"submit\" value=\"{$lang['strcreate']}\" />\n";
			echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
			echo "</form>\n";

			break;
		case 3:
			// Check inputs
			$values = trim($_REQUEST['values']);
			if (trim($_REQUEST['name']) == '') {
				$_REQUEST['stage'] = 1;
				doCreateEnum($lang['strtypeneedsname']);
				return;
			} elseif ($values == '' || !is_numeric($values) || $values != (int) $values || $values <= 0) {
				$_REQUEST['stage'] = 1;
				doCreateEnum($lang['strtypeneedsvals']);
				return;
			}

			$status = $typeActions->createEnumType($_REQUEST['name'], $_REQUEST['value'], $_REQUEST['typcomment']);

			if ($status == 0)
				doDefault($lang['strtypecreated']);
			elseif ($status == -1) {
				$_REQUEST['stage'] = 2;
				doCreateEnum($lang['strtypeneedsvalue']);
				return;
			} else {
				$_REQUEST['stage'] = 2;
				doCreateEnum($lang['strtypecreatedbad']);
				return;
			}
			break;
		default:
			echo "<p>{$lang['strinvalidparam']}</p>\n";
	}
}

/**
 * Displays a screen where they can enter a new type
 */
function doCreateBase($msg = '')
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);
	$fncActions = new FunctionActions($pg);

	if (!isset($_POST['typname']))
		$_POST['typname'] = '';
	if (!isset($_POST['typin']))
		$_POST['typin'] = '';
	if (!isset($_POST['typout']))
		$_POST['typout'] = '';
	if (!isset($_POST['typlen']))
		$_POST['typlen'] = '';
	if (!isset($_POST['typdef']))
		$_POST['typdef'] = '';
	if (!isset($_POST['typelem']))
		$_POST['typelem'] = '';
	if (!isset($_POST['typdelim']))
		$_POST['typdelim'] = '';
	if (!isset($_POST['typalign']))
		$_POST['typalign'] = $pg->typAlignDef;
	if (!isset($_POST['typstorage']))
		$_POST['typstorage'] = $pg->typStorageDef;

	// Retrieve all functions and types in the database
	$funcs = $fncActions->getFunctions(true);
	$types = $typeActions->getTypes(true);

	$misc->printTrail('schema');
	$misc->printTitle($lang['strcreatetype'], 'pg.type.create');
	$misc->printMsg($msg);

	echo "<form action=\"types.php\" method=\"post\">\n";
	echo "<table>\n";
	echo "<tr><th class=\"data left required\">{$lang['strname']}</th>\n";
	echo "<td class=\"data1\"><input name=\"typname\" size=\"32\" maxlength=\"{$pg->_maxNameLen}\" value=\"",
		html_esc($_POST['typname']), "\" /></td></tr>\n";
	echo "<tr><th class=\"data left required\">{$lang['strinputfn']}</th>\n";
	echo "<td class=\"data1\"><select name=\"typin\">";
	while (!$funcs->EOF) {
		$proname = html_esc($funcs->fields['proname']);
		echo "<option value=\"{$proname}\"", ($proname == $_POST['typin']) ? ' selected="selected"' : '', ">{$proname}</option>\n";
		$funcs->moveNext();
	}
	echo "</select></td></tr>\n";
	echo "<tr><th class=\"data left required\">{$lang['stroutputfn']}</th>\n";
	echo "<td class=\"data1\"><select name=\"typout\">";
	$funcs->moveFirst();
	while (!$funcs->EOF) {
		$proname = html_esc($funcs->fields['proname']);
		echo "<option value=\"{$proname}\"", ($proname == $_POST['typout']) ? ' selected="selected"' : '', ">{$proname}</option>\n";
		$funcs->moveNext();
	}
	echo "</select></td></tr>\n";
	echo "<tr><th class=\"data left" . (version_compare($pg->major_version, '7.4', '<') ? ' required' : '') . "\">{$lang['strlength']}</th>\n";
	echo "<td class=\"data1\"><input name=\"typlen\" size=\"8\" value=\"",
		html_esc($_POST['typlen']), "\" /></td></tr>";
	echo "<tr><th class=\"data left\">{$lang['strdefault']}</th>\n";
	echo "<td class=\"data1\"><input name=\"typdef\" size=\"8\" value=\"",
		html_esc($_POST['typdef']), "\" /></td></tr>";
	echo "<tr><th class=\"data left\">{$lang['strelement']}</th>\n";
	echo "<td class=\"data1\"><select name=\"typelem\">";
	echo "<option value=\"\"></option>\n";
	while (!$types->EOF) {
		$currname = html_esc($types->fields['typname']);
		echo "<option value=\"{$currname}\"", ($currname == $_POST['typelem']) ? ' selected="selected"' : '', ">{$currname}</option>\n";
		$types->moveNext();
	}
	echo "</select></td></tr>\n";
	echo "<tr><th class=\"data left\">{$lang['strdelimiter']}</th>\n";
	echo "<td class=\"data1\"><input name=\"typdelim\" size=\"1\" maxlength=\"1\" value=\"",
		html_esc($_POST['typdelim']), "\" /></td></tr>";
	echo "<tr><th class=\"data left\"><label for=\"typbyval\">{$lang['strpassbyval']}</label></th>\n";
	echo "<td class=\"data1\"><input type=\"checkbox\" id=\"typbyval\" name=\"typbyval\"",
		isset($_POST['typbyval']) ? ' checked="checked"' : '', " /></td></tr>";
	echo "<tr><th class=\"data left\">{$lang['stralignment']}</th>\n";
	echo "<td class=\"data1\"><select name=\"typalign\">";
	foreach ($pg->typAligns as $v) {
		echo "<option value=\"{$v}\"", ($v == $_POST['typalign']) ? ' selected="selected"' : '', ">{$v}</option>\n";
	}
	echo "</select></td></tr>\n";
	echo "<tr><th class=\"data left\">{$lang['strstorage']}</th>\n";
	echo "<td class=\"data1\"><select name=\"typstorage\">";
	foreach ($pg->typStorages as $v) {
		echo "<option value=\"{$v}\"", ($v == $_POST['typstorage']) ? ' selected="selected"' : '', ">{$v}</option>\n";
	}
	echo "</select></td></tr>\n";
	echo "</table>\n";
	echo "<p><input type=\"hidden\" name=\"action\" value=\"save_create_base\" />\n";
	echo $misc->form;
	echo "<input type=\"submit\" value=\"{$lang['strcreate']}\" />\n";
	echo "<input type=\"submit\" name=\"cancel\" value=\"{$lang['strcancel']}\" /></p>\n";
	echo "</form>\n";
}

/**
 * Actually creates the new base type in the database
 */
function doSaveCreateBase()
{
	$pg = AppContainer::getPostgres();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);

	// Check that they've given a name and a length.
	// Note: We're assuming they've given in and out functions here
	// which might be unwise...
	if ($_POST['typname'] == '')
		doCreateBase($lang['strtypeneedsname']);
	elseif ($_POST['typlen'] == '')
		doCreateBase($lang['strtypeneedslen']);
	else {
		$status = $typeActions->createBaseType(
			$_POST['typname'],
			$_POST['typin'],
			$_POST['typout'],
			$_POST['typlen'],
			$_POST['typdef'],
			$_POST['typelem'],
			$_POST['typdelim'],
			isset($_POST['typbyval']),
			$_POST['typalign'],
			$_POST['typstorage']
		);
		if ($status == 0)
			doDefault($lang['strtypecreated']);
		else
			doCreateBase($lang['strtypecreatedbad']);
	}
}

/**
 * Show default list of types in the database
 */
function doDefault($msg = '')
{
	$pg = AppContainer::getPostgres();
	//$conf = AppContainer::getConf();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$typeActions = new TypeActions($pg);
	$roleActions = new RoleActions($pg);

	$misc->printTrail('schema');
	$misc->printTabs('schema', 'types');
	$misc->printMsg($msg);

	$types = $typeActions->getTypes();

	$columns = [
		'type' => [
			'title' => $lang['strtype'],
			'field' => field('typname'),
			'url' => "types.php?action=properties&amp;{$misc->href}&amp;",
			'vars' => ['type' => 'basename'],
			'icon' => $misc->icon('Type'),
			'class' => 'nowrap'
		],
		'flavour' => [
			'title' => $lang['strflavor'],
			'field' => field('typtype'),
			'type' => 'verbatim',
			'class' => 'text-center',
			'params' => [
				'map' => [
					'b' => $lang['strbasetype'],
					'c' => $lang['strcompositetype'],
					'd' => $lang['strdomain'],
					'e' => $lang['strenum'],
					'p' => $lang['strpseudotype'],
					'r' => $lang['strrangetype'],
					'm' => $lang['strmultirangetype'],
				],
			],
		],
		'owner' => [
			'title' => $lang['strowner'],
			'field' => field('typowner'),
		],
		'actions' => [
			'title' => $lang['stractions'],
		],
		'comment' => [
			'title' => $lang['strcomment'],
			'field' => field('typcomment'),
		],
	];

	$footer = [
		'type' => [
			'agg' => 'count',
			'format' => fn($v) => "$v {$lang['strtypes']}",
			'colspan' => 2,
		],
		'owner' => [
			'text' => $lang['strtotal'],
			'colspan' => 3,
		],
	];

	$actions = [
		'edit' => [
			'icon' => $misc->icon('Edit'),
			'content' => $lang['stredit'],
			'attr' => [
				'href' => [
					'url' => 'types.php',
					'urlvars' => [
						'action' => 'alter',
						'type' => field('basename')
					]
				]
			]
		],
		'drop' => [
			'icon' => $misc->icon('Delete'),
			'content' => $lang['strdrop'],
			'attr' => [
				'href' => [
					'url' => 'types.php',
					'urlvars' => [
						'action' => 'confirm_drop',
						'type' => field('basename')
					]
				]
			]
		],
	];

	$isCatalogSchema = $misc->isCatalogSchema();
	if ($isCatalogSchema) {
		// Disable actions on system types
		unset($columns['actions']);
		$actions = [];
	}

	$misc->printTable(
		$types,
		$columns,
		$actions,
		'types-types',
		$lang['strnotypes'],
		null,
		$footer
	);

	$navlinks = [
		'create_enum' => [
			'attr' => [
				'href' => [
					'url' => 'types.php',
					'urlvars' => [
						'action' => 'create_enum',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateEnumType'),
			'content' => $lang['strcreateenumtype']
		],
		'create_comp' => [
			'attr' => [
				'href' => [
					'url' => 'types.php',
					'urlvars' => [
						'action' => 'create_comp',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateCompositeType'),
			'content' => $lang['strcreatecomptype']
		],
		'create_base' => [
			'attr' => [
				'href' => [
					'url' => 'types.php',
					'urlvars' => [
						'action' => 'create_base',
						'server' => $_REQUEST['server'],
						'database' => $_REQUEST['database'],
						'schema' => $_REQUEST['schema']
					]
				]
			],
			'icon' => $misc->icon('CreateBaseType'),
			'content' => $lang['strcreatebasetype']
		],
	];

	if (!$roleActions->isSuperUser()) {
		// To create a new base type, you must be a superuser.
		// (This restriction is made because an erroneous type definition
		// could confuse or even crash the server.)
		unset($navlinks['create']);
	}
	if (!$typeActions->hasEnumTypes()) {
		unset($navlinks['createenum']);
	}

	if (!$isCatalogSchema) {
		$misc->printNavLinks($navlinks, 'types-types', get_defined_vars());
	}

}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
	$misc = AppContainer::getMisc();
	$pg = AppContainer::getPostgres();
	$typeActions = new TypeActions($pg);

	$types = $typeActions->getTypes();

	$reqvars = $misc->getRequestVars('type');

	$attrs = [
		'text' => field('typname'),
		'icon' => 'Type',
		'toolTip' => field('typcomment'),
		'action' => url(
			'types.php',
			$reqvars,
			[
				'action' => 'properties',
				'type' => field('basename')
			]
		)
	];

	$misc->printTree($types, $attrs, 'types');
	exit;
}

$action = $_REQUEST['action'] ?? '';

//$pg = AppContainer::getPostgres();
//$conf = AppContainer::getConf();
$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();


if ($action == 'tree')
	doTree();

$misc->printHeader($lang['strtypes']);
$misc->printBody();

switch ($action) {
	case 'create_comp':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doCreateComposite();
		break;
	case 'create_enum':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doCreateEnum();
		break;
	case 'save_create_base':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doSaveCreateBase();
		break;
	case 'create_base':
		doCreateBase();
		break;
	case 'alter':
		doAlter();
		break;
	case 'save_alter':
		doSaveAlter();
		break;
	case 'drop':
		if (isset($_POST['cancel']))
			doDefault();
		else
			doDrop(false);
		break;
	case 'confirm_drop':
		doDrop(true);
		break;
	case 'properties':
		doProperties();
		break;
	default:
		doDefault();
		break;
}

$misc->printFooter();
