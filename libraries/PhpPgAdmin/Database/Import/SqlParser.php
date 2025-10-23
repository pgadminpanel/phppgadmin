<?php

namespace PhpPgAdmin\Database\Import;

class SqlParser
{

    /**
     * Attempt to stream/split an INSERT ... VALUES statement at $start.
     *
     * Conservative rules (to preserve correctness):
     * - Only emits tuples that are followed by an explicit ',' or ';' within the current buffer.
     * - If after a tuple we see something other than ',', ';', or end-of-buffer, streaming is aborted.
     *   (This avoids breaking statements with trailing clauses like ON CONFLICT / RETURNING).
     *
     * Returns null if not applicable or if more data is needed.
     *
     * On full statement termination (";" seen), returns:
     *  ['statements' => string[], 'newStart' => int]
     *
     * On partial progress (no ';' yet, but at least one tuple emitted), returns:
     *  ['statements' => string[], 'remainder' => string]
     */
    private static function tryStreamInsertValues(string $buf, int $start, int $len, bool $stdConforming = true, int $maxTuplesPerStmt = 500, int $maxStmtBytes = 262144): ?array
    {
        $sub = substr($buf, $start);
        // quick filter: must look like INSERT ... VALUES
        if (!preg_match('/^\s*INSERT\s+INTO\b.*?\bVALUES\b/si', $sub)) {
            return null;
        }

        if (!preg_match('/\bVALUES\b/si', $sub, $kv, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $valuesPosAbs = $start + $kv[0][1] + strlen($kv[0][0]);
        if ($valuesPosAbs >= $len) {
            return null;
        }

        $header = substr($buf, $start, $valuesPosAbs - $start);

        $out = [];
        $batch = [];
        $batchBytes = 0;
        $batchTuples = 0;

        $j = $valuesPosAbs;
        // skip whitespace after VALUES
        while ($j < $len && ($buf[$j] === " " || $buf[$j] === "\t" || $buf[$j] === "\r" || $buf[$j] === "\n" || $buf[$j] === "\f")) {
            $j++;
        }

        $tupleStart = null;
        $depth = 0;
        $inS = false;
        $stringBackslashEscapes = false;
        $inD = false;
        $inDol = null;

        $tailPos = null; // position after ',' following last emitted tuple

        $flush = function (bool $final = false) use (&$out, &$batch, &$batchBytes, &$batchTuples, $header) {
            if (empty($batch)) {
                return;
            }
            // Keep formatting compact; db accepts it.
            $out[] = rtrim($header) . ' ' . implode(',', $batch) . ';';
            $batch = [];
            $batchBytes = 0;
            $batchTuples = 0;
        };

        while ($j < $len) {
            $ch = $buf[$j];

            if ($inS) {
                // Handle backslash escapes (e.g. E'...\'...') by skipping
                // the escaped char.
                if ($stringBackslashEscapes && $ch === "\\") {
                    if (($j + 1) < $len) {
                        $j += 2;
                        continue;
                    }
                    $j++;
                    continue;
                }
                if ($ch === "'") {
                    if (!$stringBackslashEscapes) {
                        if (($j + 1) < $len && $buf[$j + 1] === "'") {
                            $j += 2;
                            continue;
                        }
                    }
                    $inS = false;
                    $stringBackslashEscapes = false;
                }
                $j++;
                continue;
            }

            if ($inD) {
                if ($ch === '"') {
                    if (($j + 1) < $len && $buf[$j + 1] === '"') {
                        $j += 2;
                        continue;
                    }
                    $inD = false;
                }
                $j++;
                continue;
            }

            if ($inDol !== null) {
                $tlen = strlen($inDol);
                if ($tlen > 0 && ($j + $tlen) <= $len && substr($buf, $j, $tlen) === $inDol) {
                    $inDol = null;
                    $j += $tlen;
                    continue;
                }
                $j++;
                continue;
            }

            if ($ch === '$') {
                $rest = substr($buf, $j);
                if (preg_match('/^\$[A-Za-z0-9_]*\$/', $rest, $mm)) {
                    $inDol = $mm[0];
                    $j += strlen($inDol);
                    continue;
                }
            }

            if ($ch === "'") {
                $inS = true;
                $isEscapeLiteral = false;
                if ($j > 0 && ($buf[$j - 1] === 'E' || $buf[$j - 1] === 'e')) {
                    $prev = ($j - 2) >= 0 ? $buf[$j - 2] : '';
                    if (!preg_match('/[A-Za-z0-9_]/', $prev)) {
                        $isEscapeLiteral = true;
                    }
                }
                $stringBackslashEscapes = (!$stdConforming) || $isEscapeLiteral;
                $j++;
                continue;
            }
            if ($ch === '"') {
                $inD = true;
                $j++;
                continue;
            }

            if ($ch === '(') {
                if ($depth === 0) {
                    $tupleStart = $j;
                }
                $depth++;
                $j++;
                continue;
            }

            if ($ch === ')') {
                if ($depth > 0) {
                    $depth--;
                    if ($depth === 0 && $tupleStart !== null) {
                        $tupleEnd = $j + 1;

                        // Find delimiter after tuple end
                        $k = $tupleEnd;
                        while ($k < $len && ($buf[$k] === " " || $buf[$k] === "\t" || $buf[$k] === "\r" || $buf[$k] === "\n" || $buf[$k] === "\f")) {
                            $k++;
                        }
                        if ($k >= $len) {
                            // Need more data to decide if this is followed by ',' ';' or a trailing clause.
                            break;
                        }

                        $delim = $buf[$k];
                        if ($delim !== ',' && $delim !== ';') {
                            // Likely ON CONFLICT / RETURNING; do not stream.
                            return null;
                        }

                        $tupleText = substr($buf, $tupleStart, $tupleEnd - $tupleStart);
                        $batch[] = $tupleText;
                        $batchTuples++;
                        $batchBytes += strlen($tupleText) + 1;
                        $tupleStart = null;

                        if ($delim === ',') {
                            $tailPos = $k + 1;
                            // Flush if batch is big enough.
                            if ($batchTuples >= $maxTuplesPerStmt || $batchBytes >= $maxStmtBytes) {
                                $flush(false);
                            }
                            $j = $tailPos;
                            continue;
                        }

                        // delim is ';' => statement ended
                        $flush(true);
                        $newStart = self::skipNoise($buf, $k + 1, $len);
                        return ['statements' => $out, 'newStart' => $newStart];
                    }
                }
                $j++;
                continue;
            }

            $j++;
        }

        // Partial: we only make progress if we flushed at least one statement,
        // or we have tuples that we know are followed by ',' (tailPos is set).
        if (!empty($batch)) {
            // Only flush if we know we can safely advance (tailPos indicates
            // a comma after last tuple).
            if ($tailPos !== null) {
                $flush(false);
            }
        }

        if (empty($out) || $tailPos === null) {
            // No safe progress yet.
            return null;
        }

        $tail = ltrim(substr($buf, $tailPos));
        $remainder = rtrim($header) . ' ' . $tail;

        return ['statements' => $out, 'remainder' => $remainder];
    }

    /**
     * Skip whitespace and comments starting at $pos.
     * Does NOT skip psql meta-commands (they're now returned as items).
     * Returns the next position where a real SQL token may begin.
     */
    private static function skipNoise(string $buf, int $pos, int $len): int
    {
        while ($pos < $len) {
            // skip whitespace
            while ($pos < $len && ($buf[$pos] === " " || $buf[$pos] === "\t" || $buf[$pos] === "\r" || $buf[$pos] === "\n" || $buf[$pos] === "\f")) {
                $pos++;
            }
            if ($pos >= $len)
                break;

            // line comment --
            if ($buf[$pos] === '-' && ($pos + 1) < $len && $buf[$pos + 1] === '-') {
                $pos += 2;
                while ($pos < $len && $buf[$pos] !== "\n") {
                    $pos++;
                }
                continue;
            }

            // block comment /* ... */
            if ($buf[$pos] === '/' && ($pos + 1) < $len && $buf[$pos + 1] === '*') {
                $end = strpos($buf, '*/', $pos + 2);
                if ($end === false) {
                    // Unclosed block comment; treat as noise till end
                    return $len;
                }
                $pos = $end + 2;
                continue;
            }

            break; // nothing skipped => meaningful token at $pos
        }
        return $pos;
    }


    /**
     * Parse from a raw string chunk plus existing buffer. Useful for HTTP streaming.
     * Returns ordered items array with statements and meta-commands in sequence.
     */
    public static function parseFromString(string $data, string $existingBuffer = '', bool $eof = false, bool $stdConformingInitial = true): array
    {
        // Reuse the logic of parseFromReader by inlining the same steps
        $buf = $existingBuffer . $data;
        $len = strlen($buf);
        if ($len === 0) {
            return [
                'items' => [],
                'consumed' => 0,
                'eof' => $eof,
                'remainder' => $existingBuffer,
                'standard_conforming_strings' => $stdConformingInitial,
            ];
        }

        $items = [];
        $start = self::skipNoise($buf, 0, $len);
        $inSingle = false;
        $stringBackslashEscapes = false;
        $inDouble = false;
        $inBlock = false;
        $inDollar = null;
        $stdConforming = $stdConformingInitial;

        for ($i = 0; $i < $len; $i++) {
            if ($i < $start) {
                $i = $start;
                if ($i >= $len) {
                    break;
                }
            }
            $c = $buf[$i];

            if ($inBlock) {
                if ($c === '*' && ($i + 1) < $len && $buf[$i + 1] === '/') {
                    $inBlock = false;
                    $i++;
                }
                continue;
            }

            if ($inSingle) {
                if ($stringBackslashEscapes && $c === "\\") {
                    if (($i + 1) < $len) {
                        $i++;
                    }
                    continue;
                }
                if ($c === "'") {
                    if (($i + 1) < $len && $buf[$i + 1] === "'") {
                        $i++;
                        continue;
                    }
                    $inSingle = false;
                    $stringBackslashEscapes = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($c === '"') {
                    if (($i + 1) < $len && $buf[$i + 1] === '"') {
                        $i++;
                        continue;
                    }
                    $inDouble = false;
                }
                continue;
            }

            if ($inDollar !== null) {
                $tag = $inDollar;
                $tlen = strlen($tag);
                if ($tlen > 0 && substr($buf, $i, $tlen) === $tag) {
                    $inDollar = null;
                    $i += $tlen - 1;
                }
                continue;
            }

            if ($c === '/' && ($i + 1) < $len && $buf[$i + 1] === '*') {
                $inBlock = true;
                $i++;
                continue;
            }

            if ($c === '-' && ($i + 1) < $len && $buf[$i + 1] === '-') {
                $i += 2;
                while ($i < $len && $buf[$i] !== "\n") {
                    $i++;
                }
                // Re-align start if we're still at statement start
                if ($start === $i) {
                    $start = self::skipNoise($buf, $start, $len);
                }
                continue;
            }

            if ($c === "'") {
                $isEscapeLiteral = false;
                if ($i > 0 && ($buf[$i - 1] === 'E' || $buf[$i - 1] === 'e')) {
                    $prev = ($i - 2) >= 0 ? $buf[$i - 2] : '';
                    if (!preg_match('/[A-Za-z0-9_]/', $prev)) {
                        $isEscapeLiteral = true;
                    }
                }
                $inSingle = true;
                $stringBackslashEscapes = (!$stdConforming) || $isEscapeLiteral;
                continue;
            }
            if ($c === '"') {
                $inDouble = true;
                continue;
            }

            if ($c === '$') {
                $rest = substr($buf, $i);
                if (preg_match('/^\$[A-Za-z0-9_]*\$/', $rest, $m)) {
                    $inDollar = $m[0];
                    $i += strlen($inDollar) - 1;
                    continue;
                }
            }

            if ($i === $start) {
                // Skip psql meta-commands at statement start
                if ($buf[$start] === '\\') {
                    $lineStart = $start;
                    while ($i < $len && $buf[$i] !== "\n") {
                        $i++;
                    }
                    $metaCmd = rtrim(substr($buf, $lineStart, $i - $lineStart), "\r\n");
                    $items[] = ['type' => 'meta', 'content' => $metaCmd];
                    $start = self::skipNoise($buf, $i + 1, $len);
                    $i = $start - 1;
                    continue;
                }
                // COPY streaming
                if (preg_match('/^\s*COPY\b/i', substr($buf, $start))) {
                    if (preg_match('/\r?\n\\\.\r?\n/', $buf, $m, PREG_OFFSET_CAPTURE, $start)) {
                        $pos = $m[0][1];
                        $matchLen = strlen($m[0][0]);
                        $stmt = substr($buf, $start, $pos + $matchLen - $start);
                        $items[] = ['type' => 'statement', 'content' => $stmt];
                        $start = self::skipNoise($buf, $pos + $matchLen, $len);
                        $i = $start - 1;
                        continue;
                    } else {
                        break;
                    }
                }
                // INSERT ... VALUES streaming
                $ins = self::tryStreamInsertValues($buf, $start, $len, $stdConforming);
                if (is_array($ins)) {
                    if (!empty($ins['statements'])) {
                        foreach ($ins['statements'] as $s) {
                            $items[] = ['type' => 'statement', 'content' => $s];
                        }
                    }
                    if (isset($ins['newStart'])) {
                        $start = (int) $ins['newStart'];
                        $i = $start - 1;
                        continue;
                    }
                    if (isset($ins['remainder'])) {
                        return [
                            'items' => $items,
                            'consumed' => strlen($data),
                            'eof' => $eof,
                            'remainder' => (string) $ins['remainder'],
                            'standard_conforming_strings' => $stdConforming,
                        ];
                    }
                }
            }

            if ($c === ';') {
                $stmt = substr($buf, $start, $i - $start + 1);
                $items[] = ['type' => 'statement', 'content' => $stmt];
                $stmtTrim = strtolower(trim($stmt, " \t\n\r;"));
                if (preg_match('/^set\s+standard_conforming_strings\s*=\s*off/', $stmtTrim)) {
                    $stdConforming = false;
                } elseif (preg_match('/^set\s+standard_conforming_strings\s*=\s*on/', $stmtTrim)) {
                    $stdConforming = true;
                }
                $start = self::skipNoise($buf, $i + 1, $len);
            }
        }

        $remainder = '';
        if ($start < $len) {
            $remainder = substr($buf, $start);
        }
        $consumed = strlen($data);

        return [
            'items' => $items,
            'consumed' => $consumed,
            'eof' => $eof,
            'remainder' => $remainder,
            'standard_conforming_strings' => $stdConforming,
        ];
    }
}
