<?php

use PhpPgAdmin\Core\AppContainer;

/**
 * Transforms raw binary data into PostgreSQL COPY-compatible octal escapes.
 *
 * Example:
 *   "\xDE\xAD\xBE\xEF" → "\\336\\255\\276\\357"
 *
 * COPY expects exactly this format.
 */
function bytea_to_octal(string $data): string
{
	if ($data === '') {
		return '';
	}

	static $map = null;
	if ($map === null) {
		$map = [];
		for ($i = 0; $i < 256; $i++) {
			if ($i >= 32 && $i <= 126) {
				if ($i === 92) {
					// backslash
					$map["\\"] = '\\\\';
				} else {
					// printable except backslash
					$map[chr($i)] = chr($i);
				}
			} else {
				// non-printable
				$map[chr($i)] = sprintf("\\%03o", $i);
			}
		}
	}

	return strtr($data, $map);
}

/**
 * Transforms raw binary data into octal escaped string.
 *
 * Example:
 *   "\xDE\xAD\xBE\xEF" → "\\\\336\\\\255\\\\276\\\\357"
 *
 * COPY expects exactly this format.
 */
function bytea_to_octal_escaped(string $data): string
{
	if ($data === '') {
		return '';
	}

	static $map = null;
	if ($map === null) {
		$map = [];
		for ($i = 0; $i < 256; $i++) {
			$ch = chr($i);

			if ($i >= 32 && $i <= 126) {
				if ($i === 34 || $i === 39 || $i === 92) {
					// Always octal-escape problematic characters
					$map[$ch] = sprintf("\\\\%03o", $i);
				} else {
					// printable ASCII
					$map[$ch] = $ch;
				}
			} else {
				// non-printable → octal
				$map[$ch] = sprintf("\\\\%03o", $i);
			}
		}
	}

	return strtr($data, $map);
}


/**
 * Remove PostgreSQL identifier quoting
 * @param string $ident
 * @return string
 */
function pg_unquote_identifier(string $ident): string
{
	// remove surrounding quotes
	$len = strlen($ident);
	if ($len >= 2 && $ident[0] === '"' && $ident[$len - 1] === '"') {
		$ident = substr($ident, 1, $len - 2);
		// replace double quotes with single quotes
		$ident = str_replace('""', '"', $ident);
	}
	return $ident;
}

/**
 * Escape a string for use as a PostgreSQL identifier (e.g., table or column name)
 * @param string $id
 * @return string
 */
function pg_escape_id($id = ''): string
{
	$pg = AppContainer::getPostgres();
	return pg_escape_identifier($pg->conn->_connectionID, $id);
}

/**
 * HTML-escape a string, brings null check back to PHP 8.2+
 * @param string|null $string
 * @param int $flags
 * @param string $encoding
 * @param bool $double_encode
 * @return string
 */
function html_esc(
	$string,
	$flags = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
	$encoding = 'UTF-8',
	$double_encode = true
): string {
	if ($string === null) {
		return '';
	}
	return htmlspecialchars($string, $flags, $encoding, $double_encode);
}

/**
 * Format a string according to a template and values from an array or object
 * Field names in the template are enclosed in {}, i.e., {name} reads $data['name'] or $data->{'name'}
 * To the right of the field name, a sprintf-like format string can be defined, starting with :
 * Example: {amount:06.2f} formats $data['amount'] as a decimal number in the format 0000.00
 * ? can be used to specify an optional default value, which can also be empty if the field is not set
 * Example: Hello {person}, you have {currency?$} {amount:0.2f} credit
 * Format: '{' name [':' fmt] ['?' [default]] '}'
 * @param string $template
 * @param array|object $data
 * @return string
 */
function format_string($template, $data)
{
	$isObject = is_object($data);
	$pattern = '/(?<left>[^{]*)\{(?<name>\w+)(:(?<pad>\'.|0| )?(?<justify>-)?(?<minlen>\d+)?(\.(?<prec>\d+))?(?<type>[a-zA-Z]))?(?<optional>\?.*)?\}(?<right>.*)/';
	while (preg_match($pattern, $template, $match)) {
		$fieldName = $match['name'];
		$fieldExists = $isObject ? isset($data->{$fieldName}) : isset($data[$fieldName]);
		if (!$fieldExists) {
			if (isset($match['optional'])) {
				$template = $match['left'] . substr($match['optional'], 1) . $match['right'];
				continue;
			} else {
				$template = $match['left'] . '[?' . $match['name'] . ']' . $match['right'];
				continue;
			}
		} else {
			$param = $isObject ? $data->{$fieldName} : $data[$fieldName];
		}
		if (strlen($padding = $match['pad'])) {
			if ($padding[0] == '\'') {
				$padding = $padding[1];
			}
		} else {
			$padding = ' ';
		}
		$precision = $match['prec'] ? intval($match['prec']) : null;
		switch ($match['type']) {
			case 'b':
				$subst = base_convert($param, 10, 2);
				break;
			case 'c':
				$subst = chr($param);
				break;
			case 'd':
				$subst = (string) (int) $param;
				break;
			case 'f':
			case 'F':
				if ($precision !== null) {
					$subst = number_format((float) $param, $precision);
				} else {
					$subst = (string) (float) $param;
				}
				break;
			case 'o':
				$subst = base_convert($param, 10, 8);
				break;
			case 'p':
				$subst = (string) (round((float) $param, $precision) * 100);
				break;
			case 's':
			default:
				$subst = (string) $param;
				break;
			case 'u':
				$subst = (string) abs((int) $param);
				break;
			case 'x':
				$subst = strtolower(base_convert($param, 10, 16));
				break;
			case 'X':
				$subst = base_convert($param, 10, 16);
				break;
		}
		$minLength = (int) $match['minlen'];
		if ($match['justify'] != '-') {
			// justify right
			if (strlen($subst) < $minLength) {
				$subst = str_repeat($padding, $minLength - strlen($subst)) . $subst;
			}
		} else {
			// justify left
			if (strlen($subst) < $minLength) {
				$subst .= str_repeat($padding, $minLength - strlen($subst));
			}
		}
		$template = $match['left'] . $subst . $match['right'];
	}
	return $template;
}

/**
 * SQL query extractor with multibyte string support and dollar-quoted strings
 *
 * @param string $sql
 * @return string[]
 */
function extract_sql_queries(string $sql): array
{
	$queries = [];
	$len = mb_strlen($sql);
	$current = '';
	$i = 0;

	$inSingle = false;   // '
	$inDouble = false;   // "
	$inDollar = false;   // $tag$ ... $tag$
	$dollarTag = '';     // full tag like $tag$
	$inLineComment = false; // --
	$blockDepth = 0;        // nested /* ... */
	while ($i < $len) {
		$ch = mb_substr($sql, $i, 1);
		$next = ($i + 1 < $len) ? mb_substr($sql, $i + 1, 1) : null;

		// handle end of line comment
		if ($inLineComment) {
			$current .= $ch;
			if ($ch === "\n" || $ch === "\r") {
				$inLineComment = false;
			}
			$i++;
			continue;
		}

		// handle block comments nesting
		if ($blockDepth > 0) {
			// detect start of nested block
			if ($ch === '/' && $next === '*') {
				$blockDepth++;
				$current .= '/*';
				$i += 2;
				continue;
			}
			// detect end of block
			if ($ch === '*' && $next === '/') {
				$blockDepth--;
				$current .= '*/';
				$i += 2;
				continue;
			}
			// otherwise consume
			$current .= $ch;
			$i++;
			continue;
		}

		// if currently in dollar-quote
		if ($inDollar) {
			// try to match closing tag at current position
			$tagLen = mb_strlen($dollarTag);
			$substr = mb_substr($sql, $i, $tagLen);
			if ($substr === $dollarTag) {
				$current .= $dollarTag;
				$i += $tagLen;
				$inDollar = false;
				$dollarTag = '';
				continue;
			}
			// otherwise consume one char
			$current .= $ch;
			$i++;
			continue;
		}

		// if in single-quoted string
		if ($inSingle) {
			// handle escaped single quote '' -> consume both and stay in string
			if ($ch === "'" && $next === "'") {
				$current .= "''";
				$i += 2;
				continue;
			}
			// end of single-quoted string
			if ($ch === "'") {
				$inSingle = false;
				$current .= $ch;
				$i++;
				continue;
			}
			// otherwise consume
			$current .= $ch;
			$i++;
			continue;
		}

		// if in double-quoted identifier
		if ($inDouble) {
			// escaped double quote ""
			if ($ch === '"' && $next === '"') {
				$current .= '""';
				$i += 2;
				continue;
			}
			if ($ch === '"') {
				$inDouble = false;
				$current .= $ch;
				$i++;
				continue;
			}
			$current .= $ch;
			$i++;
			continue;
		}

		// Not inside any string/comment/dollar: detect starts

		// line comment --
		if ($ch === '-' && $next === '-') {
			$inLineComment = true;
			$current .= '--';
			$i += 2;
			continue;
		}

		// block comment start /*
		if ($ch === '/' && $next === '*') {
			$blockDepth = 1;
			$current .= '/*';
			$i += 2;
			continue;
		}

		// dollar-quote start: match $tag$
		if ($ch === '$') {
			// try to match $tag$ at this position
			$rest = mb_substr($sql, $i);
			if (preg_match('/^\$[A-Za-z0-9_]*\$/u', $rest, $m)) {
				$dollarTag = $m[0]; // e.g. $tag$
				$inDollar = true;
				$current .= $dollarTag;
				$i += mb_strlen($dollarTag);
				continue;
			}
			// if not a tag, treat as normal char
		}

		// single-quote start
		if ($ch === "'") {
			$inSingle = true;
			$current .= $ch;
			$i++;
			continue;
		}

		// double-quote start
		if ($ch === '"') {
			$inDouble = true;
			$current .= $ch;
			$i++;
			continue;
		}

		// semicolon ends statement (only when not in any string/comment/dollar)
		if ($ch === ';') {
			$trimmed = trim($current);
			if ($trimmed !== '') {
				$queries[] = $trimmed;
			}
			$current = '';
			$i++;
			continue;
		}

		// normal char
		$current .= $ch;
		$i++;
	}

	$trimmed = trim($current);
	if ($trimmed !== '') {
		$queries[] = $trimmed;
	}

	return $queries;
}

/**
 * Check if SQL query returns a result set
 * @param string $sql
 * @return bool
 */
function is_result_set_query(string $sql): bool
{
	$s = trim($sql);
	if ($s === '')
		return false;

	// remove leading single-line and block comments
	$s = preg_replace('/^\s*(--[^\n]*\n|\/\*.*?\*\/\s*)+/s', '', $s);
	if ($s === null)
		return false;
	$stmt = trim($s);

	if ($stmt === '')
		return false;

	// EXPLAIN always returns a resultset
	if (preg_match('/^\s*EXPLAIN\b/i', $stmt)) {
		return true;
	}

	// quick checks for always-resultset starters
	$always = ['SELECT', 'VALUES', 'TABLE', 'SHOW', 'FETCH', 'MOVE'];
	foreach ($always as $kw) {
		if (preg_match('/^\s*' . $kw . '\b/i', $stmt))
			return true;
	}

	// COPY ... TO  => resultset (stream)
	if (preg_match('/^\s*COPY\b.+\bTO\b/i', $stmt))
		return true;
	// COPY ... FROM => no resultset
	if (preg_match('/^\s*COPY\b.+\bFROM\b/i', $stmt))
		return false;

	// If statement contains RETURNING at top-level -> returns rows
	// This is a heuristic: matches RETURNING outside of quotes/dollar; good for most cases.
	if (preg_match('/\bRETURNING\b/i', $stmt))
		return true;

	// WITH ... need to find the main query token after CTE list
	if (preg_match('/^\s*WITH\b/i', $stmt)) {
		// parse CTE list to find position after last CTE closing parenthesis at depth 0
		$len = strlen($stmt);
		$pos = 0;
		// skip 'WITH'
		if (preg_match('/^\s*WITH\b/i', $stmt, $m, PREG_OFFSET_CAPTURE)) {
			$pos = $m[0][1] + strlen($m[0][0]);
		}
		$depth = 0;
		$inSingle = $inDouble = $inDollar = false;
		$dollarTag = '';
		$i = $pos;
		$lastClose = -1;
		for (; $i < $len; $i++) {
			$ch = $stmt[$i];
			// dollar quoting
			if (!$inSingle && !$inDouble && $ch === '$') {
				if (preg_match('/\G\$([A-Za-z0-9_]*)\$/A', $stmt, $m, 0, $i)) {
					$tag = $m[1];
					$tagFull = '$' . $tag . '$';
					if (!$inDollar) {
						$inDollar = true;
						$dollarTag = $tagFull;
						$i += strlen($tagFull) - 1;
						continue;
					} else {
						if ($tagFull === $dollarTag) {
							$inDollar = false;
							$dollarTag = '';
							$i += strlen($tagFull) - 1;
							continue;
						}
					}
				}
			}
			if ($inDollar)
				continue;

			if ($ch === "'" && !$inDouble) {
				$inSingle = !$inSingle;
				continue;
			}
			if ($ch === '"' && !$inSingle) {
				$inDouble = !$inDouble;
				continue;
			}
			if ($inSingle || $inDouble)
				continue;

			if ($ch === '(') {
				$depth++;
				continue;
			}
			if ($ch === ')') {
				if ($depth > 0)
					$depth--;
				$lastClose = $i;
				continue;
			}
			// if we hit a semicolon at depth 0, stop
			if ($ch === ';' && $depth === 0)
				break;
			// if we see a token after CTEs (depth 0) that is not comma, assume main query starts here
			if ($depth === 0 && $ch !== ' ' && $ch !== "\t" && $ch !== "\n" && $ch !== ',') {
				// check substring from here for a known main token
				$rest = substr($stmt, $i);
				if (preg_match('/^\s*(SELECT|VALUES|TABLE|INSERT|UPDATE|DELETE|MERGE|SHOW|EXPLAIN|COPY|FETCH|MOVE)\b/i', $rest, $mm)) {
					$mainToken = strtoupper($mm[1]);
					if (in_array($mainToken, ['SELECT', 'VALUES', 'TABLE', 'SHOW', 'FETCH', 'MOVE'], true))
						return true;
					if ($mainToken === 'COPY') {
						return (bool) preg_match('/^\s*COPY\b.+\bTO\b/i', $rest);
					}
					if ($mainToken === 'EXPLAIN') {
						// reuse EXPLAIN logic
						return true;
					}
					// INSERT/UPDATE/DELETE/MERGE -> only resultset if RETURNING present
					if (preg_match('/\bRETURNING\b/i', $rest))
						return true;
					return false;
				}
			}
		}

		// fallback: if we couldn't reliably find main token, be conservative and check for RETURNING or SELECT inside
		if (preg_match('/\bSELECT\b/i', $stmt))
			return true;
		if (preg_match('/\bRETURNING\b/i', $stmt))
			return true;
		return false;
	}

	// For top-level INSERT/UPDATE/DELETE/MERGE without RETURNING -> no resultset
	if (preg_match('/^\s*(INSERT|UPDATE|DELETE|MERGE)\b/i', $stmt)) {
		return (bool) preg_match('/\bRETURNING\b/i', $stmt);
	}

	// DDL, SET, RESET, VACUUM, ANALYZE, DO, CALL, LOCK etc. -> no resultset
	if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE|SET|RESET|VACUUM|ANALYZE|DO|CALL|LOCK|GRANT|REVOKE)\b/i', $stmt)) {
		return false;
	}

	// conservative fallback: if first token is an identifier-like token, check common resultset tokens
	if (preg_match('/^\s*([A-Z_]+)/i', $stmt, $m)) {
		$tok = strtoupper($m[1]);
		return in_array($tok, ['SELECT', 'VALUES', 'TABLE', 'SHOW', 'EXPLAIN', 'FETCH', 'MOVE'], true);
	}

	return false;
}


// ------------------------------------------------------------
// str_starts_with
// ------------------------------------------------------------
if (!function_exists('str_starts_with')) {
	function str_starts_with($haystack, $needle)
	{
		if ($needle === '') {
			return false;
		}
		return substr_compare($haystack, $needle, 0, strlen($needle)) === 0;
	}
}

// ------------------------------------------------------------
// str_ends_with
// ------------------------------------------------------------
if (!function_exists('str_ends_with')) {
	function str_ends_with($haystack, $needle)
	{
		if ($needle === '') {
			return false;
		}
		return substr_compare($haystack, $needle, -strlen($needle), strlen($needle)) === 0;
	}
}

// ------------------------------------------------------------
// str_contains
// ------------------------------------------------------------
if (!function_exists('str_contains')) {
	function str_contains($haystack, $needle)
	{
		if ($needle === '') {
			return false;
		}
		return strpos($haystack, $needle) !== false;
	}
}

// ------------------------------------------------------------
// fdiv — PHP 8 floating‑point division with INF/NaN behavior
// ------------------------------------------------------------
if (!function_exists('fdiv')) {
	function fdiv($dividend, $divisor)
	{
		// Match PHP 8 behavior exactly
		if ($divisor == 0) {
			if ($dividend == 0) {
				return NAN;
			}
			return ($dividend > 0 ? INF : -INF);
		}
		return $dividend / $divisor;
	}
}

// ------------------------------------------------------------
// get_debug_type — PHP 8 type inspection
// ------------------------------------------------------------
if (!function_exists('get_debug_type')) {
	function get_debug_type($value)
	{
		switch (true) {
			case is_null($value):
				return 'null';
			case is_bool($value):
				return 'bool';
			case is_int($value):
				return 'int';
			case is_float($value):
				return 'float';
			case is_string($value):
				return 'string';
			case is_array($value):
				return 'array';
			case is_object($value):
				return get_class($value);
			case is_resource($value):
				$type = get_resource_type($value);
				return $type === 'unknown type' ? 'resource' : "resource ($type)";
			default:
				return 'unknown';
		}
	}
}

if (!function_exists('printFatalError')) {
    /**
     * 输出完整的错误页面并终止脚本
     *
     * @param string $message 错误消息
     * @param string|null $title 错误标题（默认用 lang['strerror']）
     */
    function printFatalError(string $message, ?string $title = null)
    {
        $lang = AppContainer::getLang();

        $errortitle = htmlspecialchars($title ?? ($lang['strerror'] ?? 'Error'), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $backhome = htmlspecialchars($lang['strbacktohome'] ?? 'Back to home', ENT_QUOTES, 'UTF-8');
        $appLocale = htmlspecialchars($lang['applocale'] ?? 'en');
        $appLangDir = htmlspecialchars($lang['applangdir'] ?? 'ltr');
        $home = htmlspecialchars('index.php');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" id="Layer_12" data-name="Layer 12" viewBox="0 0 512 512" class="error-icon"><defs><linearGradient id="linear-gradient" x1="376.2" x2="135.8" y1="376.2" y2="135.8" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ef3739"/><stop offset=".5" stop-color="#ef3739"/><stop offset="1" stop-color="#ff8c8b"/></linearGradient><linearGradient id="linear-gradient-2" x1="320.4" x2="192.3" y1="318.4" y2="190.3" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ffd2d2"/><stop offset=".6" stop-color="#fff"/><stop offset="1" stop-color="#fff"/></linearGradient><radialGradient id="radial-gradient" cx="256" cy="-5704" r="200.8" fx="256" fy="-5704" gradientTransform="matrix(1 0 0 .45 0 2994.7)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ef3739" stop-opacity=".6"/><stop offset="1" stop-color="#ef3739" stop-opacity="0"/></radialGradient></defs><path d="M496.5 129.9c-13.4-49.5-64.9-101-114.4-114.4A501 501 0 0 0 256 0a510 510 0 0 0-126.1 15.5C80.4 28.9 28.9 80.4 15.5 129.9 7.9 160.2 0 200.9 0 256c.1 55.2 8 95.8 15.5 126.2C28.9 431.6 80.4 483 129.9 496.5c30.3 7.6 71 15.4 126.1 15.5 55.2-.1 95.8-8 126.2-15.5 49.4-13.4 100.9-64.9 114.3-114.4 7.6-30.3 15.4-71 15.5-126.1-.1-55.2-7.9-95.8-15.5-126.2" style="stroke-width:0;fill:#ffe5e5"/><path d="M444.7 366.2c-10.6-17.6-51-35.9-89.7-40.6a861 861 0 0 0-99-5.5c-43.3 0-75.2 2.8-99 5.5-38.8 4.7-79.1 23-89.7 40.6A91 91 0 0 0 55.2 411a91 91 0 0 0 12.1 45c10.6 17.5 51 35.8 89.7 40.6 23.8 2.7 55.7 5.5 99 5.5s75.2-2.8 99-5.5c38.8-4.8 79.1-23 89.7-40.6a91 91 0 0 0 12.1-44.9c0-19.6-6.2-34-12.1-44.8" style="stroke-width:0;fill:url(#radial-gradient)"/><g id="INFO"><path d="M256 86a170 170 0 1 0 0 340 170 170 0 0 0 0-340" style="fill:url(#linear-gradient);stroke-width:0"/><path d="M256 371.3a27.6 27.6 0 1 1 0-55.2 27.6 27.6 0 0 1 0 55.2m26.7-95.1q-.7 6.5-5.4 11.2-4.9 4.6-11.5 5.4h-.1q-8 .6-8.2.4s-4.5.1-9.8-.4h-.1q-6.7-.8-11.5-5.4c-3-3-5-7-5.4-11.2a1625 1625 0 0 1 0-118.5q.7-6.6 5.4-11.3t11.5-5.4h.1c5.3-.5 9.8-.3 9.8-.3s2.9-.2 8.2.3q6.8.8 11.6 5.4c3 3 5 7 5.4 11.2a1633 1633 0 0 1 0 118.6" style="stroke-width:0;fill:url(#linear-gradient-2)"/></g></svg>';

        echo "<!DOCTYPE html>\n";
        echo '<html lang="' . $appLocale . '" dir="' . $appLangDir . '">' . "\n";
        echo "<head>\n";
        echo '<meta charset="UTF-8">' . "\n";
        echo '<link rel="icon" type="image/svg+xml" href="images/themes/bootstrap/favicon.svg">' . "\n";
        echo "<title>{$errortitle}</title>\n";
        echo "<style>\n";
        echo "body {\n";
        echo "    margin: 0;\n";
        echo "    min-height: 100vh;\n";
        echo "    display: flex;\n";
        echo "    align-items: flex-start;\n";
        echo "    justify-content: center;\n";
        echo "    font-family: sans-serif;\n";
        echo "    background: #f5f5f5;\n";
        echo "}\n";
        echo ".error-wrap {\n";
        echo "    display: flex;\n";
        echo "    flex-direction: column;\n";
        echo "    align-items: center;\n";
        echo "    padding-top: 40px;\n";
        echo "}\n";
        echo ".error-icon {\n";
        echo "    display: block;\n";
        echo "    width: 200px;\n";
        echo "    height: 200px;\n";
        echo "    margin-bottom: 1em;\n";
        echo "}\n";
        echo ".error-box {\n";
        echo "    max-width: 480px;\n";
        echo "    padding: 2em;\n";
        echo "    background: #fff;\n";
        echo "    border: 1px solid #ddd;\n";
        echo "    border-radius: 8px;\n";
        echo "    box-shadow: 0 2px 12px rgba(0,0,0,0.08);\n";
        echo "    text-align: center;\n";
        echo "}\n";
        echo ".error-box h1 {\n";
        echo "    margin: 0 0 0.5em;\n";
        echo "    font-size: 1.3em;\n";
        echo "    color: #cc0000;\n";
        echo "}\n";
        echo ".error-box p {\n";
        echo "    margin: 0 0 1.5em;\n";
        echo "    color: #000;\n";
        echo "    line-height: 1.6;\n";
        echo "    max-width: 36em;\n";
        echo "    margin-left: auto;\n";
        echo "    margin-right: auto;\n";
        echo "}\n";
        echo ".error-box a {\n";
        echo "    display: inline-block;\n";
        echo "    padding: 0.5em 1em;\n";
        echo "    background: #1a518c;\n";
        echo "    color: #fff;\n";
        echo "    text-decoration: none;\n";
        echo "    border-radius: 4px;\n";
        echo "}\n";
        echo ".error-box a:hover {\n";
        echo "    background: #cc0000;\n";
        echo "}\n";
        echo "</style>\n";
        echo "</head>\n";
        echo "<body>\n";
        echo '<div class="error-wrap">' . "\n";
        echo $svg . "\n";
        echo '<div class="error-box">' . "\n";
        echo "<h1>{$errortitle}</h1>\n";
        echo "<p>{$title}</p>\n";
        echo "<a href=\"{$home}\">{$backhome}</a>\n";
        echo "</div>\n";
        echo "</div>\n";
        echo "</body>\n";
        echo "</html>\n";

        exit;
    }
}
