<?php

namespace PhpPgAdmin\Database\Import\Data;

/**
 * Streaming CSV/TSV parser that tolerates split rows across chunks.
 */
class CsvRowParser implements RowStreamingParser
{
    private $delimiter;
    private $useHeader;

    public function __construct(string $delimiter = ',', bool $useHeader = false)
    {
        $this->delimiter = $delimiter;
        $this->useHeader = $useHeader;
    }

    public function parse(string $chunk, array &$state): array
    {
        $rows = [];
        $header = null;
        $remainder = '';

        $headerSeen = $state['header_seen'] ?? false;

        // Use php://temp so fgetcsv can handle quoted fields spanning lines
        $combined = $chunk;
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $combined);
        rewind($handle);

        while (($fields = fgetcsv($handle, 0, $this->delimiter, '"', '\\')) !== false) {

            // Skip empty trailing line
            if ($fields === [null] || $fields === false) {
                continue;
            }

            if ($this->useHeader && !$headerSeen) {
                $header = $fields;
                $headerSeen = true;
                $state['header_seen'] = true;
                continue;
            }

            $rows[] = $fields;
        }

        fclose($handle);

        $lastNewline = strrpos($combined, "\n");

        if ($lastNewline === false) {
            $remainder = $combined;
        } else {
            $remainder = substr($combined, $lastNewline + 1);
        }

        // If there's a remainder and some rows, the last row is incomplete
        if ($remainder !== '' && !empty($rows)) {
            array_pop($rows);
        }

        return [
            'rows' => $rows,
            'remainder' => $remainder,
            'header' => $header,
        ];
    }

    public function isAssociative(): bool
    {
        return false;
    }
}