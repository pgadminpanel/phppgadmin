<?php

namespace PhpPgAdmin\Database\Export;

/**
 * JSON Format Formatter
 * Converts table data to structured JSON with metadata
 */
class JsonFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'application/json; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'json';
    /** @var bool */
    protected $supportsGzip = true;

    private const TYPE_DEFAULT = 0;
    private const TYPE_INTEGER = 1;
    private const TYPE_DECIMAL = 2;
    private const TYPE_BOOLEAN = 3;
    private const TYPE_BYTEA = 4;
    private const TYPE_JSON = 5;

    /** @var array */
    private $fieldNamesEncoded = [];

    /** @var array */
    private $typeCodes = [];

    /** @var string */
    private $byteaEncoding = 'hex';

    /** @var string */
    private $rowSeparator = '';

    /**
     * Write header information before data rows.
     *
     * @param array $fields Metadata about fields (types, names, etc.)
     * @param array $metadata Optional additional metadata provided by caller
     */
    public function writeHeader($fields, $metadata = [])
    {
        $this->byteaEncoding = $metadata['bytea_encoding'] ?? 'hex';
        $this->fieldNamesEncoded = [];
        $this->typeCodes = [];

        $this->write("{\n");
        $this->write("\t\"header\": [\n");

        $sep = "";
        foreach ($fields as $i => $field) {
            $name = $field['name'] ?? "col_$i";
            $type = strtolower($field['type'] ?? 'unknown');

            $this->fieldNamesEncoded[$i] = json_encode($name, JSON_UNESCAPED_UNICODE);

            // integer types → no escaping
            if (
                isset([
                    'int2' => true,
                    'int4' => true,
                    'int8' => true,
                    'integer' => true,
                    'bigint' => true,
                    'smallint' => true,
                ][$type])
            ) {
                $this->typeCodes[$i] = self::TYPE_INTEGER;
            } elseif (
                isset([
                    'float4' => true,
                    'float8' => true,
                    'real' => true,
                    'double precision' => true,
                    'numeric' => true,
                    'decimal' => true
                ][$type])
            ) {
                $this->typeCodes[$i] = self::TYPE_DECIMAL;
            } elseif ($type === 'bool' || $type === 'boolean') {
                $this->typeCodes[$i] = self::TYPE_BOOLEAN;
            } elseif ($type === 'bytea') {
                $this->typeCodes[$i] = self::TYPE_BYTEA;
            } elseif ($type === 'json' || $type === 'jsonb') {
                $this->typeCodes[$i] = self::TYPE_JSON;
            } else {
                $this->typeCodes[$i] = self::TYPE_DEFAULT;
            }

            $col = [
                'name' => $name,
                'type' => self::DATA_TYPE_MAPPING[$type] ?? $type
            ];
            if ($type === 'bytea') {
                $col['encoding'] = $this->byteaEncoding;
            }

            $this->write($sep . "\t\t" . json_encode($col, JSON_UNESCAPED_UNICODE));
            $sep = ",\n";
        }

        $this->write("\n\t],\n");
        $this->write("\t\"data\": [\n");
        $this->rowSeparator = "";
    }

    /**
     * Write a single row of data.
     *
     * @param array $row Numeric array of values
     */
    public function writeRow($row)
    {
        $this->write($this->rowSeparator);
        $this->write("\t\t{");

        $innerSep = "";
        foreach ($row as $i => $value) {
            $this->write($innerSep . $this->fieldNamesEncoded[$i] . ":");
            $innerSep = ",";
            if ($value === null) {
                $this->write("null");
                continue;
            }
            switch ($this->typeCodes[$i] ?? self::TYPE_DEFAULT) {
                case self::TYPE_INTEGER:
                    $this->write($value);
                    break;
                case self::TYPE_DECIMAL:
                    // Handle special float values
                    if ($value === "NaN" || $value === "Infinity" || $value === "-Infinity") {
                        $this->write('"' . addcslashes($value, "\\\\\"\n\r\t\f\b") . '"');
                    } else {
                        $this->write($value);
                    }
                    break;
                case self::TYPE_BOOLEAN:
                    $this->write($value ? "true" : "false");
                    break;
                case self::TYPE_BYTEA:
                    $this->write('"' . self::encodeBytea($value, $this->byteaEncoding, true) . '"');
                    break;
                case self::TYPE_JSON:
                    $this->write($value);
                    break;
                default:
                    $this->write('"' . addcslashes($value, "\\\\\"\n\r\t\f\b") . '"');
            }
        }

        $this->write("}");
        $this->rowSeparator = ",\n";
    }

    /**
     * Write footer information after data rows.
     */
    public function writeFooter()
    {
        $this->write("\n\t]\n");
        $this->write("}\n");
    }



}
