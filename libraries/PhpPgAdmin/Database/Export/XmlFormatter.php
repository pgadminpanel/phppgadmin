<?php

namespace PhpPgAdmin\Database\Export;

class XmlFormatter extends OutputFormatter
{
    protected $mimeType = 'text/xml; charset=utf-8';
    protected $fileExtension = 'xml';
    protected $supportsGzip = true;

    /** @var array */
    private $columns = [];

    /** @var int */
    private $colCount = 0;

    /** @var string */
    private $byteaEncoding = 'hex';

    /**
     * Write header information before data rows.
     *
     * @param array $fields Metadata about fields (types, names, etc.)
     * @param array $metadata Optional additional metadata provided by caller
     */
    public function writeHeader($fields, $metadata = [])
    {
        $this->byteaEncoding = $metadata['bytea_encoding'] ?? 'hex';
        $this->columns = [];
        $this->colCount = count($fields);

        $this->write('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $this->write("<data>\n");

        $this->write("<header>\n");
        foreach ($fields as $i => $field) {
            $name = $field['name'] ?? "column_$i";
            $type = strtolower($field['type'] ?? 'unknown');
            $mappedType = self::DATA_TYPE_MAPPING[$type] ?? $type;
            $escapedName = htmlspecialchars($name, ENT_XML1, 'UTF-8');
            $escapedType = htmlspecialchars($mappedType, ENT_XML1, 'UTF-8');

            $this->write("\t<col name=\"{$escapedName}\" type=\"{$escapedType}\" />\n");

            $this->columns[$i] = [
                'name' => $name,
                'escaped_name' => $escapedName,
                'type' => $type
            ];
        }
        $this->write("</header>\n");
        $this->write("<records>\n");
    }

    /**
     * Write a single row of data.
     *
     * @param array $row Numeric array of values
     */
    public function writeRow($row)
    {
        $this->write("\t<row>\n");

        for ($i = 0; $i < $this->colCount; $i++) {
            $col = $this->columns[$i] ?? ['name' => "column_$i", 'type' => 'unknown'];
            $name = $col['escaped_name'] ?? htmlspecialchars($col['name'], ENT_XML1, 'UTF-8');
            $type = $col['type'] ?? 'unknown';
            $value = $row[$i] ?? null;

            // NULL
            if ($value === null) {
                $this->write("\t\t<col name=\"{$name}\" isNull=\"true\" />\n");
                continue;
            }

            // BYTEA → Base64
            if ($type === 'bytea') {
                $encoded = self::encodeBytea($value, $this->byteaEncoding);
                $this->write("\t\t<col name=\"{$name}\">{$encoded}</col>\n");
                continue;
            }

            if ($type === 'xml') {
                // XML data → embed directly
                $this->write("\t\t<col name=\"{$name}\">");
                $this->write($value);
                $this->write("</col>\n");
                continue;
            }

            // Normal text
            $encoded = htmlspecialchars($value, ENT_XML1, 'UTF-8');
            $this->write("\t\t<col name=\"{$name}\">{$encoded}</col>\n");
        }

        $this->write("\t</row>\n");
    }

    /**
     * Write footer information after data rows.
     */
    public function writeFooter()
    {
        $this->write("</records>\n");
        $this->write("</data>\n");
    }

}
