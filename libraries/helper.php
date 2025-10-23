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
