<?php

namespace PhpPgAdmin\Database\Import\Data;

/**
 * JsonRowParser
 *
 * Chunk-capable, stack-based JSON streaming parser.
 * Expects format:
 * {
 *   "columns": [ { "name": "...", "type": "..." }, ... ],
 *   "data": [ { "col": value, ... }, ... ]
 * }
 *
 * Features:
 * - Streaming / incremental: handles arbitrarily large streams in chunks
 * - Supports strings, literals (number, true, false, null), nested JSON values (obj/array)
 * - Returns completed rows immediately
 * - header (columns) is returned as soon as fully read
 */
class JsonRowParser implements RowStreamingParser
{
    /**
     * Parse a chunk and update the parser state.
     *
     * @param string $chunk
     * @param array  $state  Persistent parser state between calls
     * @return array ['rows' => array, 'remainder' => string, 'header' => array|null]
     */
    public function parse(string $chunk, array &$state): array
    {
        $state += [
            // mode: root | columns | columns-array | data | data-array | row
            // Note: top-level key may be "columns" or "header" (both map to columns parsing)
            'mode' => 'root',
            'columns' => null,
            'rows' => [],
            'currentRow' => null,
            'currentKey' => null,
            'stack' => [],
            'valueMode' => null,
            'valueBuffer' => '',
            'valueStack' => [],
            // derived header (compat with other parsers that return only names)
            'columnNames' => null,
        ];

        // Tokenize & consume
        foreach ($this->tokenize($chunk) as $token) {
            // If we're currently collecting a JSON value, route tokens to consumeJsonValue
            if ($state['valueMode'] === 'json') {
                $this->consumeJsonValue($token, $state);
            } else {
                $this->consume($token, $state);
            }
        }

        // Extract rows and return
        $rows = $state['rows'];
        $state['rows'] = [];

        return [
            'rows' => $rows,
            'remainder' => $chunk,
            'header' => $state['columnNames'],
        ];
    }

    /**
     * Tokenizer: reads as many complete tokens as possible from $buffer
     * and leaves incomplete remainder in $buffer.
     *
     * Supported token types:
     * - structural characters: { } [ ] : ,
     * - string: "string" (decoded)
     * - literal: number, true, false, null (decoded)
     *
     * @param string &$buffer
     * @return \Generator yields token arrays like ['t' => '{'] or ['t' => 'string', 'v' => '...']
     */
    private function tokenize(string &$buffer): \Generator
    {
        $len = strlen($buffer);
        $i = 0;

        while ($i < $len) {
            $c = $buffer[$i];

            // Skip whitespace
            if ($c <= " ") {
                $i++;
                continue;
            }

            // Structural characters
            if (strpos("{}[]:,", $c) !== false) {
                yield ['t' => $c];
                $i++;
                continue;
            }

            // String
            if ($c === '"') {
                $tokenStart = $i; // include opening quote in remainder on incomplete token
                $i++; // skip opening quote
                $str = '';
                $escaped = false;

                while ($i < $len) {
                    $ch = $buffer[$i];

                    if ($escaped) {
                        $str .= '\\' . $ch;
                        $escaped = false;
                        $i++;
                        continue;
                    }

                    if ($ch === '\\') {
                        $escaped = true;
                        $i++;
                        continue;
                    }

                    if ($ch === '"') {
                        $decoded = json_decode('"' . $str . '"');
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            // treat as incomplete/invalid -> keep remainder starting at opening quote
                            $i = $tokenStart;
                            break 2;
                        }

                        yield ['t' => 'string', 'v' => $decoded];
                        $i++; // skip closing quote
                        continue 2;
                    }

                    $str .= $ch;
                    $i++;
                }

                // Incomplete string -> rewind to opening quote and keep remainder
                $i = $tokenStart;
                break;
            }

            // Literal (number, true, false, null) or negative number
            if (
                $c === '-' ||
                ($c >= '0' && $c <= '9') ||
                $c === 't' || $c === 'f' || $c === 'n'
            ) {
                $start = $i;
                while ($i < $len) {
                    $ch = $buffer[$i];
                    // allow digits, letters (for true/false/null), decimal point and exponent/sign chars
                    if (
                        ($ch >= '0' && $ch <= '9') ||
                        ($ch >= 'a' && $ch <= 'z') ||
                        ($ch >= 'A' && $ch <= 'Z') ||
                        strpos('.eE+-', $ch) !== false
                    ) {
                        $i++;
                        continue;
                    }
                    break;
                }

                $token = substr($buffer, $start, $i - $start);
                $decoded = json_decode($token, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    yield ['t' => 'literal', 'v' => $decoded];
                    continue;
                }

                // Possibly cut mid-literal -> rewind so it becomes remainder
                $i = $start;
                break;
            }

            // Unknown character -> break and keep remainder
            break;
        }

        // Leave remainder in buffer
        $buffer = substr($buffer, $i);
    }

    /**
     * Main consume function for tokens (except when valueMode === 'json')
     *
     * @param array $t
     * @param array &$state
     */
    private function consume(array $t, array &$state): void
    {
        switch ($t['t']) {
            case '{':
                // If a nested object begins as a field value, do NOT touch the main stack.
                if ($state['mode'] === 'row' && $state['currentKey'] !== null) {
                    $this->startJsonValue('{', $state);
                    break;
                }

                $state['stack'][] = '{';

                if ($state['mode'] === 'data-array') {
                    $state['currentRow'] = [];
                    $state['mode'] = 'row';
                } elseif ($state['mode'] === 'columns-array') {
                    // Start of a column/header object
                    $state['currentRow'] = [];
                    $state['currentKey'] = null;
                }
                break;

            case '}':
                array_pop($state['stack']);
                if ($state['mode'] === 'row') {
                    $state['rows'][] = $state['currentRow'];
                    $state['currentRow'] = null;
                    $state['mode'] = 'data-array';
                } elseif ($state['mode'] === 'columns-array' && $state['currentRow'] !== null) {
                    $state['columns'][] = $state['currentRow'];
                    $state['currentRow'] = null;
                    $state['currentKey'] = null;
                }
                break;

            case '[':
                // If a nested array begins as a field value, do NOT touch the main stack.
                if ($state['mode'] === 'row' && $state['currentKey'] !== null) {
                    $this->startJsonValue('[', $state);
                    break;
                }

                $state['stack'][] = '[';

                if ($state['mode'] === 'columns') {
                    $state['columns'] = [];
                    $state['mode'] = 'columns-array';
                } elseif ($state['mode'] === 'data') {
                    $state['mode'] = 'data-array';
                }
                break;

            case 'string':
                $this->handleString($t['v'], $state);
                break;

            case 'literal':
                $this->handleLiteral($t['v'], $state);
                break;

            case ':':
            case ',':
                // no action needed on structural separators
                break;

            case ']':
                array_pop($state['stack']);

                if ($state['mode'] === 'columns-array') {
                    // finished header/columns array, resume at root to catch "data"
                    $state['mode'] = 'root';
                    $this->finalizeColumns($state);
                } elseif ($state['mode'] === 'data-array') {
                    // finished data array
                    $state['mode'] = 'root';
                }
                break;
        }
    }

    /**
     * If we are currently collecting a JSON value (valueMode === 'json'),
     * all tokens are routed here.
     *
     * @param array $t
     * @param array &$state
     */
    private function consumeJsonValue(array $t, array &$state): void
    {
        // Append raw token text to valueBuffer in JSON-syntactic form
        if ($t['t'] === 'string') {
            // json_encode provides correct escaping
            $state['valueBuffer'] .= json_encode($t['v'], JSON_UNESCAPED_UNICODE);
        } elseif ($t['t'] === 'literal') {
            // json_encode converts literals correctly to JSON text
            $state['valueBuffer'] .= json_encode($t['v']);
        } else {
            // structural characters: { } [ ] : ,
            $state['valueBuffer'] .= $t['t'];
        }

        // Stack tracking for nested JSON values
        if ($t['t'] === '{' || $t['t'] === '[') {
            $state['valueStack'][] = $t['t'];
            return;
        }

        if ($t['t'] === '}' || $t['t'] === ']') {
            array_pop($state['valueStack']);
            // When stack is empty, the JSON value is complete
            if (empty($state['valueStack'])) {
                // decode the collected JSON value into PHP structure
                $decoded = json_decode($state['valueBuffer'], true);

                // If decode fails, fallback: store raw buffer
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $state['currentRow'][$state['currentKey']] = $state['valueBuffer'];
                } else {
                    $state['currentRow'][$state['currentKey']] = $decoded;
                }

                // Reset value mode
                $state['valueMode'] = null;
                $state['valueBuffer'] = '';
                $state['valueStack'] = [];
                $state['currentKey'] = null;
            }
        }
    }

    /**
     * String handler (for keys and string values)
     *
     * @param string $v
     * @param array &$state
     */
    private function handleString(string $v, array &$state): void
    {
        if ($state['valueMode'] === 'json') {
            return;
        }

        if ($state['mode'] === 'root') {
            // Top-level key: "columns" or "header" or "data"
            if ($v === 'columns' || $v === 'header') {
                $state['mode'] = 'columns';
            } elseif ($v === 'data') {
                $state['mode'] = 'data';
            }
            return;
        }

        if ($state['mode'] === 'columns-array') {
            // Parse header/columns entry object like a normal JSON object.
            // Keys/values are strings in this schema (name/type), but we ignore unknown keys safely.
            if ($state['currentRow'] === null) {
                // Not inside a header object; ignore defensively.
                return;
            }

            if ($state['currentKey'] === null) {
                $state['currentKey'] = $v; // key
            } else {
                $state['currentRow'][$state['currentKey']] = $v; // value
                $state['currentKey'] = null;
            }
            return;
        }

        if ($state['mode'] === 'row') {
            if ($state['currentKey'] === null) {
                $state['currentKey'] = $v;
            } else {
                $state['currentRow'][$state['currentKey']] = $v;
                $state['currentKey'] = null;
            }
        }
    }

    /**
     * Literal handler (numbers, true, false, null)
     *
     * @param mixed $v
     * @param array &$state
     */
    private function handleLiteral($v, array &$state): void
    {
        // If we are currently collecting a JSON value, ignore literals here
        if ($state['valueMode'] === 'json') {
            return;
        }

        if ($state['mode'] === 'row' && $state['currentKey'] !== null) {
            // If the literal is a primitive, assign directly
            $state['currentRow'][$state['currentKey']] = $v;
            $state['currentKey'] = null;
        }
    }

    /**
     * Helper: when a '{' or '[' appears as a value for a field,
     * start valueMode. This function is called from consume() when
     * such an opening is detected while in a row with a currentKey set.
     *
     * @param string $opening '{' or '['
     * @param array &$state
     */
    private function startJsonValue(string $opening, array &$state): void
    {
        $state['valueMode'] = 'json';
        $state['valueBuffer'] = $opening;
        $state['valueStack'] = [$opening];
    }

    /**
     * Build a simple ["col1","col2",...] list from parsed column metadata.
     */
    private function finalizeColumns(array &$state): void
    {
        if (!is_array($state['columns'])) {
            $state['columnNames'] = null;
            return;
        }

        $names = [];
        foreach ($state['columns'] as $col) {
            if (isset($col['name'])) {
                $names[] = $col['name'];
            } else {
                $names[] = '???';
            }
        }
        $state['columnNames'] = $names;
    }

    public function isAssociative(): bool
    {
        return true;
    }
}
