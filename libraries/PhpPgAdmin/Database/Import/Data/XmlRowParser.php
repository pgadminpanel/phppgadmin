<?php

namespace PhpPgAdmin\Database\Import\Data;

class XmlRowParser implements RowStreamingParser
{
    public function parse(string $chunk, array &$state): array
    {
        // Initial state
        $state += [
            'mode' => 'root',
            'header' => null,
            'header_seen' => false,
            'in_cdata' => false,
            'cdata_buffer' => '',
            'currentRow' => [],
            'currentCol' => null,
            'currentColName' => null,
            'currentColNull' => false,
            'rows' => [],
        ];

        // Tokenize
        foreach ($this->tokenize($chunk, $state) as $token) {
            $this->consumeToken($token, $state);
        }

        // Extract rows and clear them from state
        $rows = $state['rows'];
        $state['rows'] = [];

        // Validate header if seen
        if ($state['header_seen'] && is_array($state['header'])) {
            $state['header_seen'] = false;
            foreach ($state['header'] as $i => $name) {
                if (empty($name)) {
                    $state['header'] = null;
                    break;
                }
            }
        }

        return [
            'rows' => $rows,
            'remainder' => $chunk,
            'header' => $state['header'],
        ];

    }

    /**
     * Tokenizer: yields tokens and keeps incomplete data in $buffer
     */
    private function tokenize(string &$buffer, array &$state): \Generator
    {
        $i = 0;
        $len = strlen($buffer);

        while ($i < $len) {

            // --- CDATA MODE -----------------------------------------------------
            if ($state['in_cdata']) {
                $end = strpos($buffer, ']]>', $i);

                if ($end === false) {
                    // Entire chunk is CDATA content
                    $state['cdata_buffer'] .= substr($buffer, $i);
                    $buffer = '';
                    return;
                }

                // CDATA ends in this chunk
                $state['cdata_buffer'] .= substr($buffer, $i, $end - $i);

                yield ['type' => 'text', 'value' => $state['cdata_buffer']];

                // Reset CDATA state
                $state['in_cdata'] = false;
                $state['cdata_buffer'] = '';

                $i = $end + 3;
                continue;
            }

            // --- NORMAL MODE ----------------------------------------------------
            $lt = strpos($buffer, '<', $i);

            if ($lt === false) {
                // Keep remaining buffer as remainder (text may be continued in next chunk)
                $buffer = substr($buffer, $i);
                return;
            }

            // Emit text before '<'
            if ($lt > $i) {
                $text = substr($buffer, $i, $lt - $i);
                if ($text !== '') {
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    yield ['type' => 'text', 'value' => $text];
                }
            }

            // XML comment? <!-- ... -->
            if (substr_compare($buffer, '<!--', $lt, 4) === 0) {
                $end = strpos($buffer, '-->', $lt + 4);
                if ($end === false) {
                    // Incomplete comment → wait for next chunk
                    $buffer = substr($buffer, $lt);
                    return;
                }
                $i = $end + 3;
                continue;
            }

            // CDATA start?
            if (substr_compare($buffer, '<![CDATA[', $lt, 9) === 0) {
                $i = $lt + 9;
                $state['in_cdata'] = true;
                $state['cdata_buffer'] = '';
                continue;
            }

            // Normal tag
            $gt = strpos($buffer, '>', $lt);
            if ($gt === false) {
                // Incomplete tag → wait for next chunk (keep from '<' to avoid duplicating emitted text)
                $buffer = substr($buffer, $lt);
                return;
            }

            $tagContent = trim(substr($buffer, $lt + 1, $gt - $lt - 1));
            if ($tagContent === '') {
                $i = $gt + 1;
                continue;
            }

            // Self-closing tag?
            $selfClosing = false;
            if (substr($tagContent, -1) === '/') {
                $selfClosing = true;
                $tagContent = rtrim(substr($tagContent, 0, -1));
            }

            if ($tagContent !== '' && $tagContent[0] === '/') {
                yield ['type' => 'end', 'name' => strtolower(trim(substr($tagContent, 1)))];
            } else {
                $nameLen = strcspn($tagContent, " \t\r\n");
                $name = strtolower(substr($tagContent, 0, $nameLen));
                $attrs = [];

                if ($nameLen < strlen($tagContent)) {
                    $attrs = $this->parseAttributes(substr($tagContent, $nameLen + 1));
                }

                if ($name !== '') {
                    yield ['type' => 'start', 'name' => $name, 'attrs' => $attrs];
                    if ($selfClosing) {
                        yield ['type' => 'end', 'name' => $name];
                    }
                }
            }

            $i = $gt + 1;
        }

        // Keep remainder
        $buffer = substr($buffer, $i);
    }

    /**
     * Attribute parser (no regex)
     */
    private function parseAttributes(string $str): array
    {
        $attrs = [];
        $len = strlen($str);
        $i = 0;

        while ($i < $len) {
            while ($i < $len && ctype_space($str[$i])) {
                $i++;
            }

            // name
            $start = $i;
            while ($i < $len && !ctype_space($str[$i]) && $str[$i] !== '=') {
                $i++;
            }
            if ($i <= $start) {
                break;
            }

            $name = substr($str, $start, $i - $start);

            while ($i < $len && ctype_space($str[$i])) {
                $i++;
            }
            if ($i >= $len || $str[$i] !== '=') {
                break;
            }
            $i++; // '='

            while ($i < $len && ctype_space($str[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            $quote = $str[$i];
            if ($quote !== '"' && $quote !== "'") {
                break;
            }
            $i++; // opening quote

            $start = $i;
            while ($i < $len && $str[$i] !== $quote) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            $value = substr($str, $start, $i - $start);
            $attrs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

            $i++; // closing quote
        }

        return $attrs;
    }

    /**
     * State machine
     */
    private function consumeToken(array $token, array &$state): void
    {
        switch ($token['type']) {
            case 'start':
                $this->handleStart($token, $state);
                break;

            case 'end':
                $this->handleEnd($token, $state);
                break;

            case 'text':
                $this->handleText($token, $state);
                break;
        }
    }

    private function handleStart(array $t, array &$state): void
    {
        switch ($t['name']) {
            case 'header':
                $state['mode'] = 'header';
                $state['header'] = [];
                break;

            case 'col':
                if ($state['mode'] === 'header') {
                    $state['header'][] = $t['attrs']['name'] ?? '';
                } elseif ($state['mode'] === 'row') {
                    $state['currentColName'] = $t['attrs']['name'] ?? '';
                    $state['currentColNull'] = isset($t['attrs']['isNull']);
                    $state['currentCol'] = '';
                }
                break;

            case 'row':
                $state['mode'] = 'row';
                $state['currentRow'] = [];
                break;

            default:
                // If we're inside a column value, preserve unknown tags as literal markup
                if ($state['mode'] === 'row' && $state['currentCol'] !== null) {
                    $attrsStr = '';
                    foreach ($t['attrs'] as $k => $v) {
                        $attrsStr .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
                    }
                    $state['currentCol'] .= '<' . $t['name'] . $attrsStr . '>';
                }
                break;
        }
    }

    private function handleEnd(array $t, array &$state): void
    {
        switch ($t['name']) {
            case 'header':
                $state['mode'] = 'root';
                $state['header_seen'] = true;
                break;

            case 'col':
                if ($state['mode'] === 'row') {
                    $value = null;

                    if (!$state['currentColNull']) {
                        $value = $state['currentCol'];
                    }

                    $state['currentRow'][$state['currentColName']] = $value;
                }
                break;

            case 'row':
                $state['rows'][] = $state['currentRow'];
                $state['mode'] = 'records';
                break;

            default:
                // closing tag inside a column -> append literal closing tag
                if ($state['mode'] === 'row' && $state['currentCol'] !== null) {
                    $state['currentCol'] .= '</' . $t['name'] . '>';
                }
                break;
        }
    }

    private function handleText(array $t, array &$state): void
    {
        if ($state['mode'] === 'row' && $state['currentCol'] !== null) {
            $state['currentCol'] .= $t['value'];
        }
    }

    public function isAssociative(): bool
    {
        return true;
    }
}
