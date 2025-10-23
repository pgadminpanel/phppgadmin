<?php

namespace PhpPgAdmin\Database\Export;

use PhpPgAdmin\Core\AppContext;

/**
 * Abstract base class for export output formatters.
 * Each formatter is responsible for transforming dumped data into a specific output format.
 */
abstract class OutputFormatter extends AppContext
{
    /**
     * The MIME type for this format (e.g., 'text/plain', 'text/csv')
     * @var string
     */
    protected $mimeType = 'text/plain; charset=utf-8';

    /**
     * The file extension for this format (e.g., 'sql', 'csv', 'json')
     * @var string
     */
    protected $fileExtension = 'sql';

    /**
     * Whether gzip compression is supported for this format
     * @var bool
     */
    protected $supportsGzip = true;

    /**
     * Output stream for writing formatted data
     * @var resource|null
     */
    protected $outputStream = null;

    protected const DATA_TYPE_MAPPING = [

        'int2' => 'smallint',
        'int4' => 'integer',
        'int8' => 'bigint',
        'float4' => 'real',
        'float8' => 'double precision',

        'varchar' => 'character varying',
        'bpchar' => 'character', // blank-padded char

        'bool' => 'boolean',

        'time' => 'time without time zone',
        'timetz' => 'time with time zone',
        'timestamp' => 'timestamp without time zone',
        'timestamptz' => 'timestamp with time zone',

        '_int4' => 'integer[]',
        '_text' => 'text[]',
        '_varchar' => 'character varying[]',
        '_bool' => 'boolean[]',
        '_float8' => 'double precision[]',
        '_numeric' => 'numeric[]',

    ];

    /**
     * Encode bytea data according to specified encoding.
     * Note: PostgreSQL delivers bytea data in hex format by default.
     *
     * @param string $data The binary data to encode
     * @param string $encoding The encoding type ('hex', 'base64', 'octal', 'escape')
     * @param bool $escape Whether to apply additional escaping (for JSON, XML)
     * @return string The encoded bytea string
     */
    protected static function encodeBytea(string $data, string $encoding, $escape = false): string
    {
        switch ($encoding) {
            case 'base64':
                return base64_encode(pg_unescape_bytea($data));
            case 'octal':
                if ($escape) {
                    return bytea_to_octal_escaped(pg_unescape_bytea($data));
                } else {
                    return bytea_to_octal(pg_unescape_bytea($data));
                }
            case 'escape':
                return bytea_to_octal_escaped(pg_unescape_bytea($data));
            case 'hex':
            default:
                // Defaults to hex encoding
                // Data is already in hex format
                if ($escape) {
                    return '\\' . $data;
                } else {
                    return $data;
                }
        }
    }

    /**
     * Get the MIME type for this format
     * @return string
     */
    public function getMimeType()
    {
        return $this->mimeType;
    }

    /**
     * Get the file extension for this format
     * @return string
     */
    public function getFileExtension()
    {
        return $this->fileExtension;
    }

    /**
     * Check if gzip compression is supported
     * @return bool
     */
    public function supportsGzip()
    {
        return $this->supportsGzip;
    }

    /**
     * Set the output stream for writing formatted data.
     * If not set (null), format() will collect and return output as string.
     *
     * @param resource|null $stream File handle or stream resource, or null to collect as string
     */
    public function setOutputStream($stream)
    {
        $this->outputStream = $stream;
    }

    /**
     * Write data to output stream or echo output.
     *
     * @param string $data Data to write
     */
    protected function write($data)
    {
        if ($this->outputStream) {
            fwrite($this->outputStream, $data);
        } else {
            echo $data;
        }
    }

    /**
     * Write header information before data rows.
     *
     * @param array $fields Metadata about fields (types, names, etc.)
     * @param array $metadata Optional additional metadata provided by caller
     */
    public function writeHeader($fields, $metadata = [])
    {
        // Default implementation does nothing.
        // Subclasses may override to provide specific header output.
    }

    /**
     * Write a single row of data.
     *
     * @param array $row Associative array of column => value
     */
    abstract public function writeRow($row);

    /**
     * Write footer information after data rows.
     */
    public function writeFooter()
    {
        // Default implementation does nothing.
        // Subclasses may override to provide specific footer output.
    }
}
