<?php

namespace PhpPgAdmin\Database\Export;

/**
 * CSV Format Formatter
 * Converts table data to RFC 4180 compliant CSV
 */
class CsvFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/csv; charset=utf-8';
    /** @var string */
    protected $fileExtension;
    /** @var bool */
    protected $supportsGzip = true;

    /** @var \PhpPgAdmin\Database\Postgres */
    private $pg;

    private $exportNulls;

    /** @var string */
    private $delimiter;
    /** @var string */
    private $lineEnding;

    private $byteaEncoding;

    /** @var array */
    private $isBytea = [];

    /** @var array */
    private $isJson = [];

    public function __construct($delimiter = ',', $lineEnding = "\r\n", $fileExtension = 'csv')
    {
        $this->delimiter = $delimiter;
        $this->lineEnding = $lineEnding;
        $this->fileExtension = $fileExtension;
    }

    /**
     * Write header information before data rows.
     *
     * @param array $fields Metadata about fields (types, names, etc.)
     * @param array $metadata Optional additional metadata provided by caller
     */
    public function writeHeader($fields, $metadata = [])
    {
        $this->pg = $this->postgres();
        $this->exportNulls = $metadata['export_nulls'] ?? '';
        $this->byteaEncoding = $metadata['bytea_encoding'] ?? 'hex';
        $this->isBytea = [];
        $this->isJson = [];

        $out = '';
        $sep = '';

        foreach ($fields as $i => $field) {
            $type = strtolower($field['type'] ?? '');
            $this->isBytea[$i] = ($type === 'bytea');
            $this->isJson[$i] = ($type === 'json' || $type === 'jsonb');

            $out .= $sep;
            $out .= $this->csvField($field['name'] ?? "Column $i");
            $sep = $this->delimiter;
        }

        if (!empty($metadata['column_names'])) {
            $this->write($out . $this->lineEnding);
        }

    }

    /**
     * Write a single row of data.
     *
     * @param array $row Numeric array of values
     */
    public function writeRow($row)
    {
        $out = '';
        $sep = '';

        foreach ($row as $i => $value) {
            $out .= $sep;

            if ($value === null) {
                // append NULL representation
                $value = $this->exportNulls;
            } elseif ($this->isJson[$i]) {
                // JSON → special escaping for CSV
                // Escape literal newlines in JSON to prevent reimport corruption
                $json = str_replace("\n", "\\n", $value);
                // Preserve escaped quotes in JSON while CSV-escaping outer quotes
                $temp = str_replace('\\"', "\x1A", $json);
                $temp = str_replace('"', '""', $temp);
                $escaped = str_replace("\x1A", '\\"', $temp);

                $value = '"' . $escaped . '"';
            } else {
                if ($this->isBytea[$i]) {
                    // bytea → encode → then CSV-escape
                    $value = self::encodeBytea($value, $this->byteaEncoding);
                }
                $value = $this->csvField($value);
            }

            $out .= $value;
            $sep = $this->delimiter;
        }

        $this->write($out . $this->lineEnding);
    }

    /**
     * Fast CSV field escaping
     */
    private function csvField($value): string
    {
        $value = (string) $value;

        if (strpbrk($value, $this->delimiter . "\"\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

}
