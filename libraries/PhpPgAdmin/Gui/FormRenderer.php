<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;

class FormRenderer extends AppContext
{

	/**
	 * Prints a combox box
	 * @param array $arrOptions associative array storing options and values of combo should be Option => Value
	 * @param string $szName string to specify the name of the form element
	 * @param bool (optional) $bBlankEntry bool to specify whether or not we want a blank selection
	 * @param string (optional) $szDefault string to specify the default VALUE selected
	 * @param bool (optional) $bMultiple bool to specify whether or not we want a multi select combo box
	 * @param int (optional) $iSize int to specify the size IF a multi select combo
	 * @return string with the generated HTML select box
	 */
	function printCombo(&$arrOptions, $szName, $bBlankEntry = true, $szDefault = '', $bMultiple = false, $iSize = 10)
	{
		$htmlOut = '';
		if ($bMultiple) // If multiple select combo
			$htmlOut .= "<select name=\"$szName\" id=\"$szName\" multiple=\"multiple\" size=\"$iSize\">\n";
		else
			$htmlOut .= "<select name=\"$szName\" id=\"$szName\">\n";
		if ($bBlankEntry)
			$htmlOut .= "<option value=\"\"></option>\n";

		foreach ($arrOptions as $curKey => $curVal) {
			$curVal = htmlspecialchars($curVal);
			$curKey = htmlspecialchars($curKey);
			if ($curVal == $szDefault) {
				$htmlOut .= "<option value=\"$curVal\" selected=\"selected\">$curKey</option>\n";
			} else {
				$htmlOut .= "<option value=\"$curVal\">$curKey</option>\n";
			}
		}
		$htmlOut .= "</select>\n";

		return $htmlOut;
	}

	/**
	 * Outputs the HTML code for a particular field
	 * @param string $name The name to give the field
	 * @param string $value The value of the field.  Note this could be 'numeric(7,2)' sort of thing...
	 * @param string $type The database type of the field
	 * @param array $extras An array of attributes name as key and attributes' values as value
	 * @param array $options An array of options for special types (like bytea)
	 */
	function printField($name, $value, $type, $extras = [], $options = [])
	{
		$lang = $this->lang();

		if (!isset($value)) {
			$value = '';
		}

		$base_type = strstr($type, ' ', true) ?: substr($type, 0, 9);

		// Determine actions string
		$extras['class'] = empty($extras['class']) ? '' : $extras['class'] . ' ';
		$extras['class'] .= htmlspecialchars($base_type);

		if (
			isset([
				'json' => true,
				'jsonb' => true,
				'xml' => true,
			][$base_type])
		) {
			$extras['class'] .= ' sql-editor frame resizable';
			$extras['data-mode'] = str_replace('jsonb', 'json', $base_type);
		}

		$extra_str = '';
		foreach ($extras as $k => $v) {
			$extra_str .= " {$k}=\"" . htmlspecialchars($v ?? '') . "\"";
		}
		$extra_str .= " data-type=\"" . htmlspecialchars($type) . "\"";

		//var_dump($type);

		switch ($base_type) {
			case 'bool':
			case 'boolean':
				if ($value == '')
					$value = null;
				elseif ($value == 'true')
					$value = 't';
				elseif ($value == 'false')
					$value = 'f';

				// If value is null, 't' or 'f'...
				if ($value === null || $value == 't' || $value == 'f') {
					/*
					echo "<select name=\"", htmlspecialchars($name), "\"{$extra_str}>\n";
					echo "<option value=\"\"", ($value === null) ? ' selected="selected"' : '', "></option>\n";
					echo "<option value=\"t\"", ($value == 't') ? ' selected="selected"' : '', ">{$lang['strtrue']}</option>\n";
					echo "<option value=\"f\"", ($value == 'f') ? ' selected="selected"' : '', ">{$lang['strfalse']}</option>\n";
					echo "</select>\n";
					*/
					$input_name = htmlspecialchars($name);
					echo "<label><input type=\"radio\" name=\"$input_name\" value=\"\"", ($value == null) ? " checked" : "", "$extra_str> {$lang['strnull']}</label>&nbsp;&nbsp;&nbsp;";
					echo "<label><input type=\"radio\" name=\"$input_name\" value=\"t\"", ($value == 't') ? " checked" : "", "$extra_str> {$lang['strtrue']}</label>&nbsp;&nbsp;&nbsp;";
					echo "<label><input type=\"radio\" name=\"$input_name\" value=\"f\"", ($value == 'f') ? " checked" : "", "$extra_str> {$lang['strfalse']}</label>";
				} else {
					echo "<input name=\"", htmlspecialchars($name), "\" value=\"", htmlspecialchars($value ?? ''), "\" size=\"35\"{$extra_str} />\n";
				}
				break;
			case 'bytea':
				$byteaLimit = $options['limit'] ?? 0;
				$byteaSize = $options['size'] ?? null;
				$isInsert = !empty($options['is_insert']);
				$downloadUrl = $options['download_url'] ?? null;
				$maxUploadSize = $options['max_upload_size'] ?? 0;
				$showTextarea = !($byteaLimit > 0 && $byteaSize > $byteaLimit);

				if ($showTextarea) {
					// Todo: move bin2hex conversion to form actions
					/*
					if (!empty($value) && !str_starts_with($value, '\\x')) {
						$value = '\\x' . strtoupper(bin2hex($value));
					}
					*/
					echo "<textarea name=\"", htmlspecialchars($name), "\" rows=\"5\" cols=\"75\"{$extra_str}>\n";
					echo htmlspecialchars($value ?? '');
					echo "</textarea>\n";
				} else {
					echo "<input type=\"hidden\" name=\"", htmlspecialchars($name), "\" value=\"\" />\n";
				}

				$fieldKey = $name;
				if (preg_match('/^values\[(.+)\]$/', $name, $matches)) {
					$fieldKey = $matches[1];
				}
				$fileInputName = 'bytea_upload[' . $fieldKey . ']';
				$fileExtra = '';
				if ($maxUploadSize > 0) {
					$fileExtra .= ' data-max-size="' . htmlspecialchars((string) $maxUploadSize) . '"';
				}
				echo "<div class=\"bytea-upload\">";
				echo "<input type=\"file\" name=\"", htmlspecialchars($fileInputName), "\"{$fileExtra} />";
				if (!$isInsert && $downloadUrl && $byteaSize > 0) {
					echo "<div class=\"flex-row\">";
					echo "<div class=\"ml-auto\"></div>";
					echo "<div class=\"me-1\"><a href=\"$downloadUrl\">", htmlspecialchars($lang['strdownload']), "</a></div>";
					echo $this->misc()->formatVal($byteaSize, 'prettysize');
					echo "<input type=\"hidden\" name=\"bytea_keep[", htmlspecialchars($fieldKey), "]\" value=\"1\" />";
					echo "</div>";
				}
				echo "</div>\n";
				break;
			case 'text':
			case 'json':
			case 'jsonb':
			case 'xml':
			case 'character':
			case 'character varying':
				echo "<textarea name=\"", htmlspecialchars($name), "\" rows=\"5\" cols=\"75\"{$extra_str}>\n";
				echo htmlspecialchars($value ?? '');
				echo "</textarea>\n";
				break;
			default:
				if (str_ends_with($base_type, '[]') || !empty($options['is_large_type'])) {
					echo "<textarea name=\"", htmlspecialchars($name), "\" rows=\"5\" cols=\"75\"{$extra_str}>\n";
					echo htmlspecialchars($value ?? '');
					echo "</textarea>\n";
				} else {
					echo "<input name=\"", htmlspecialchars($name), "\" value=\"", htmlspecialchars($value ?? ''), "\" size=\"35\"{$extra_str} />\n";
				}
				break;
		}
	}

	public function prepareFieldFunctions()
	{
		static $function_def = <<<EOT
Date/Time
CURRENT_DATE, CURRENT_TIME, CURRENT_TIMESTAMP, DATE_TRUNC (value), AGE (value), TO_CHAR (value), TO_DATE (value), INTERVAL
Strings/Text
LENGTH (value), CHAR_LENGTH (value), LOWER (value), UPPER (value), TRIM (value), LTRIM (value), RTRIM (value), MD5 (value), ENCODE (value,'base64'), ENCODE (value,'escape'), ENCODE (value,'hex'), DECODE (value,'base64'), DECODE (value,'escape'), DECODE (value,'hex')
Math
ABS (value), CEIL (value), FLOOR (value), ROUND (value), EXP (value), LOG (value), LOG10 (value), POWER (value), SQRT (value), PI (value), SIN (value), COS (value), TAN (value)
UUID
gen_random_uuid (), uuid_generate_v4 ()
Network
inet, cidr, host (value), hostmask (value), network (value), masklen (value)
System/Info
current_user, session_user, version (), database ()
EOT;
		static $functions_by_category = null;
		static $all_functions = null;
		if (!isset($functions_by_category)) {
			$functions_by_category = [];
			$all_functions = [];
			$category = null;
			foreach (explode("\n", $function_def) as $line) {
				if (!isset($category)) {
					$category = $line;
					continue;
				}
				$functions_subset = explode(', ', $line);
				$functions_by_category[$category] = $functions_subset;
				$all_functions = array_merge_recursive($all_functions, $functions_subset);
				$category = null;
			}
			// make function searchable by key
			$all_functions = array_combine($all_functions, $all_functions);
		}
		return [$functions_by_category, $all_functions];
	}

	/**
	 * @param string $name The name to give the function select field
	 * @param string $value The value of the function field.
	 */
	function printFieldFunctions($name, $value, $extras = [])
	{
		$lang = $this->lang();

		[$functions_by_category] = $this->prepareFieldFunctions();
		$extra_str = '';
		foreach ($extras as $k => $v) {
			$extra_str .= " {$k}=\"" . htmlspecialchars($v ?? '') . "\"";
		}
		if ($value === 'CURRENT_TIMESTAMP') {
			$value2 = 'NOW ()';
		} elseif ($value === 'NOW ()') {
			$value2 = 'CURRENT_TIMESTAMP';
		} else {
			$value2 = '';
		}

		echo "<select $extra_str name=\"", htmlspecialchars($name), "\">\n";
		echo "<option value=\"\" class=\"placeholder\">", htmlspecialchars($lang['strchoosefunction']), "</option>\n";
		foreach ($functions_by_category as $category => $functions) {
			echo "<optgroup label=\"", htmlspecialchars($category), "\">\n";
			foreach ($functions as $function) {
				$selected = $value == $function || $value2 == $function ? " selected" : "";
				$function_html = htmlspecialchars($function);
				echo "<option value=\"$function_html\"{$selected}>$function_html</option>\n";
			}
			echo "</optgroup>\n";
		}
		echo "</select>\n";

	}

}
