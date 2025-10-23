<?php
// $Id: decorator.php,v 1.8 2007/04/05 11:09:38 mr-russ Exp $

// This group of functions and classes provides support for
// resolving values in a lazy manner (ie, as and when required)
// using the Decorator pattern.

// Construction functions:

/**
 * Build a decorator lazily reading a field value from a record.
 *
 * @param string $fieldName Column name to resolve when evaluated.
 * @param mixed|null $default Value returned when the field is absent.
 *
 * @return FieldDecorator
 */
function field($fieldName, $default = null)
{
	return new FieldDecorator($fieldName, $default);
}

/**
 * Create a decorator that merges multiple arrays at evaluation time.
 * Any arguments may be decorators or arrays whose resolution results in arrays.
 *
 * @param mixed $args
 * @return ArrayMergeDecorator
 */
function merge(...$args)
{
	return new ArrayMergeDecorator($args);
}

/**
 * Concatenate multiple values or decorators into a single string.
 * Arguments can be decorators that resolve into strings or literals.
 *
 * @param mixed $args
 * @return ConcatDecorator
 */
function concat(...$args)
{
	return new ConcatDecorator($args);
}

/**
 * Wraps a callback so it is invoked when the decorator resolves.
 *
 * @param callable $callback Function receiving ($fields, $params) when evaluated.
 * @param mixed|null $params Extra data forwarded to the callback.
 *
 * @return CallbackDecorator
 */
function callback($callback, $params = null)
{
	return new CallbackDecorator($callback, $params);
}

/**
 * Provides an alternate value when the primary value is empty.
 *
 * @param mixed $value Value to test for emptiness.
 * @param mixed $empty Value returned when the first argument is empty.
 * @param mixed|null $full Optional alternate returned when the value is present.
 *
 * @return IfEmptyDecorator
 */
function ifempty($value, $empty, $full = null)
{
	return new IfEmptyDecorator($value, $empty, $full);
}

/**
 * Lazily build an URL with optional query arguments resolved on demand.
 * Additional arrays of query vars are merged when more than one is provided.
 *
 * @param mixed $base Base path or decorator that provides the path.
 * @param mixed $vars Optional decorator or array supplying query vars.
 *
 * @return UrlDecorator
 */
function url($base, ...$vars)
{
    $varsCount = count($vars);

    if ($varsCount <= 1) {
        return new UrlDecorator($base, $vars[0] ?? null);
    }

    return new UrlDecorator($base, new ArrayMergeDecorator($vars));
}



/**
 * Replace placeholders in a template string with resolved values.
 *
 * @param string $str Template containing tokens to replace.
 * @param array $params Map of search strings to decorators or literals.
 *
 * @return ReplaceDecorator
 */
function replace($str, $params)
{
	return new ReplaceDecorator($str, $params);
}

/**
 * Resolve a decorator (or literal) and optionally escape the result.
 *
 * @param mixed $var Decorator or literal to resolve with the provided fields.
 * @param array $fields Context used when decorators need row data.
 * @param string|null $esc Escaping strategy: 'xml', 'html', 'url'.
 *
 * @return mixed
 */
function value($var, $fields, $esc = null)
{
	if ($var instanceof Decorator)
		$val = $var->value($fields);
	else
		$val = $var;

	if (!is_string($val)) {
		return $val;
	}

	switch ($esc) {
		case 'xml':
			return htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');

		case 'html':
			return htmlspecialchars($val, ENT_COMPAT, 'UTF-8');

		case 'url':
			return rawurlencode($val);

		default:
			return $val;
	}
}

/**
 * Resolve a decorator and escape it safely for XML content.
 *
 * @param mixed $var Decorator or literal value to escape.
 * @param array $fields Context for decorators.
 *
 * @return string
 */
function value_xml(&$var, $fields)
{
	return value($var, $fields, 'xml');
}

/**
 * Escape a decorator for use in XML attributes.
 *
 * @param string $attr Attribute name.
 * @param mixed $var Decorator or literal whose value is escaped.
 * @param array $fields Row data used for resolution.
 *
 * @return string
 */
function value_xml_attr($attr, &$var, $fields)
{
	$val = value($var, $fields, 'xml');
	if (!empty($val))
		return " {$attr}=\"{$val}\"";
	else
		return '';
}

/**
 * Resolve a decorator and escape it for inclusion in URLs.
 *
 * @param mixed $var Decorator or literal value.
 * @param array $fields Context for decorators.
 *
 * @return string
 */
function value_url(&$var, $fields)
{
	return value($var, $fields, 'url');
}

// Underlying classes:

/**
 * Base decorator that wraps any value for lazy resolution.
 */
class Decorator
{
	/** @var mixed Stored value for resolution. */
	protected $value;

	function __construct($value)
	{
		$this->value = $value;
	}

	function value($fields)
	{
		return $this->value;
	}
}

/**
 * Decorator that pulls a value from the provided field collection.
 */
class FieldDecorator extends Decorator
{
	/** @var string Field name to resolve. */
	protected $fieldName;
	/** @var mixed|null Default value when field is missing. */
	protected $defaultValue;

	function __construct($fieldName, $default = null)
	{
		$this->fieldName = $fieldName;
		if ($default !== null)
			$this->defaultValue = $default;
	}

	function value($fields)
	{
		return isset($fields[$this->fieldName]) ? value($fields[$this->fieldName], $fields) : ($this->defaultValue ?? null);
	}
}

/**
 * Decorator that merges multiple arrays/decorators into one array.
 */
class ArrayMergeDecorator extends Decorator
{
	/** @var array Collection of arrays or decorators to merge. */
	protected $arraysToMerge;

	function __construct($arrays)
	{
		$this->arraysToMerge = $arrays;
	}

	function value($fields)
	{
		$accum = [];
		foreach ($this->arraysToMerge as $var) {
			$accum = array_merge($accum, value($var, $fields));
		}
		return $accum;
	}
}

/**
 * Decorator that concatenates multiple values into a trimmed string.
 */
class ConcatDecorator extends Decorator
{
	/** @var array Values or decorators to concatenate. */
	protected $parts;

	function __construct($values)
	{
		$this->parts = $values;
	}

	function value($fields)
	{
		$accum = '';
		foreach ($this->parts as $var) {
			$accum .= value($var, $fields);
		}
		return trim($accum);
	}
}

/**
 * Decorator that defers logic to a provided callback when resolved.
 */
class CallbackDecorator extends Decorator
{
	/** @var callable Callback invoked during resolution. */
	private $callback;
	/** @var mixed|null Optional parameters passed to the callback. */
	private $params;

	function __construct($callback, $params = null)
	{
		$this->callback = $callback;
		$this->params = $params;
	}

	function value($fields)
	{
		return call_user_func($this->callback, $fields, $this->params);
	}
}

/**
 * Decorator that returns an alternate value when the primary is empty.
 */
class IfEmptyDecorator extends Decorator
{
	/** @var mixed Value to evaluate. */
	protected $valueToCheck;
	/** @var mixed Value returned when the first value is empty. */
	protected $emptyValue;
	/** @var mixed|null Optional alternate value when full. */
	protected $fullValue;

	function __construct($value, $empty, $full = null)
	{
		$this->valueToCheck = $value;
		$this->emptyValue = $empty;
		if ($full !== null)
			$this->fullValue = $full;
	}

	function value($fields)
	{
		$val = value($this->valueToCheck, $fields);
		if (empty($val))
			return value($this->emptyValue, $fields);
		else
			return isset($this->fullValue) ? value($this->fullValue, $fields) : $val;
	}
}

/**
 * Decorator that builds a URL with optional query parameters.
 */
class UrlDecorator extends Decorator
{
	/** @var mixed Base URL or decorator. */
	protected $base;
	/** @var mixed|null Decorator or array providing query vars. */
	protected $queryVars;

	function __construct($base, $queryVars = null)
	{
		$this->base = $base;
		if ($queryVars !== null)
			$this->queryVars = $queryVars;
	}

	function value($fields)
	{
		$url = value($this->base, $fields);

		if ($url === false)
			return '';

		if (!empty($this->queryVars)) {
			$queryVars = value($this->queryVars, $fields);

			$sep = '?';
			foreach ($queryVars as $var => $value) {
				$url .= $sep . value_url($var, $fields) . '=' . value_url($value, $fields);
				$sep = '&';
			}
		}
		return $url;
	}
}

/**
 * Decorator that replaces placeholders inside a template string.
 */
class ReplaceDecorator extends Decorator
{
	/** @var string Template string. */
	protected $template;
	/** @var array Replacement mappings. */
	protected $paramsMap;

	function __construct($str, $params)
	{
		$this->template = $str;
		$this->paramsMap = $params;
	}

	function value($fields)
	{
		$str = $this->template;
		foreach ($this->paramsMap as $k => $v) {
			$str = str_replace($k, value($v, $fields), $str);
		}
		return $str;
	}
}
