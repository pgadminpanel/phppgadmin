<?php

namespace PhpPgAdmin\Database\Export;

use PhpPgAdmin\Core\AppContainer;

/**
 * SQL Format Formatter
 * Outputs PostgreSQL SQL statements as-is or slightly processed
 */
class SqlFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'sql';
    /** @var bool */
    protected $supportsGzip = true;

    /** @var \PhpPgAdmin\Database\Postgres */
    private const INSERT_COPY = 1;
    private const INSERT_MULTI = 2;
    private const INSERT_SINGLE = 3;

    private const ESCAPE_NONE = 0;
    private const ESCAPE_STRING = 1;
    private const ESCAPE_BYTEA = 2;
    private const ESCAPE_BOOLEAN = 3;

    private $escapeModes = null;
    private $insertBegin = null;
    private $rowsInBatch = 0;
    private $batchSize = 0;
    private $insertFormat = 0;
    private $tableName = null;

    private $connection;

    public function __construct()
    {
        $this->connection = AppContainer::getPostgres();
    }


    public function writeHeader($fields = [], $metadata = [])
    {
        switch ($metadata['insert_format'] ?? 'copy') {
            default:
            case 'copy':
                $this->insertFormat = self::INSERT_COPY;
                break;
            case 'multi':
                $this->insertFormat = self::INSERT_MULTI;
                break;
            case 'single':
                $this->insertFormat = self::INSERT_SINGLE;
                break;
        }

        $this->rowsInBatch = 0;
        $this->batchSize = $metadata['batch_size'] ?? 1000;
        $this->tableName = $metadata['table'] ?? 'data';

        $columnNames = array_map(function ($field) {
            return $this->connection->escapeString(
                $field['name']
            );
        }, $fields);
        $this->escapeModes = $this->determineEscapeModes($fields);

        if ($this->insertFormat === self::INSERT_COPY) {
            $line = "COPY {$this->tableName} (" . implode(', ', $columnNames) . ") FROM stdin;\n";
            $this->write($line);
        } else {
            $this->insertBegin = "INSERT INTO {$this->tableName} (" . implode(', ', $columnNames) . ") VALUES";
            if ($this->insertFormat === self::INSERT_MULTI) {
                $this->write("{$this->insertBegin}\n");
            }
        }
    }

    public function writeRow($row)
    {
        // Write row data
        if ($this->insertFormat === self::INSERT_COPY) {
            $this->writeCopyRow($row, $this->escapeModes);
        } elseif ($this->insertFormat === self::INSERT_MULTI) {
            // Break into batches
            if ($this->rowsInBatch >= $this->batchSize) {
                $this->write(";\n\n{$this->insertBegin}\n");
                $this->rowsInBatch = 0;
            } elseif ($this->rowsInBatch > 0) {
                $this->write(",\n");
            }
            $this->writeInsertValues($row, $this->escapeModes);
            $this->rowsInBatch++;
        } else {
            // Single-row INSERT
            $this->write($this->insertBegin . " ");
            $this->writeInsertValues($row, $this->escapeModes);
            $this->write(";\n");
        }
    }

    public function writeFooter()
    {
        if ($this->insertFormat === self::INSERT_COPY) {
            // Finalize COPY
            $this->write("\\.\n");
        } elseif ($this->insertFormat === self::INSERT_MULTI && $this->rowsInBatch > 0) {
            // Finalize multi-row INSERT
            $this->write(";\n");
        }

    }

    /**
     * Determine escape modes for each field based on type
     * 
     * @param array $fields Field metadata
     * @return array Escape modes (0=none, 1=string, 2=bytea)
     */
    protected function determineEscapeModes($fields)
    {
        $escapeModes = [];

        foreach ($fields as $i => $field) {
            $type = strtolower($field['type'] ?? '');

            // Numeric types - no escaping
            if (
                in_array($type, [
                    'int2',
                    'int4',
                    'int8',
                    'integer',
                    'bigint',
                    'smallint',
                    'float4',
                    'float8',
                    'real',
                    'double precision',
                    'numeric',
                    'decimal'
                ])
            ) {
                $escapeModes[$i] = self::ESCAPE_NONE;
            }
            // Boolean - format specially for SQL/COPY output
            elseif (strpos($type, 'bool') !== false) {
                $escapeModes[$i] = self::ESCAPE_BOOLEAN;
            }
            // Bytea - special escaping
            elseif ($type === 'bytea') {
                $escapeModes[$i] = self::ESCAPE_BYTEA;
            }
            // Everything else - string escaping
            else {
                $escapeModes[$i] = self::ESCAPE_STRING;
            }
        }

        return $escapeModes;
    }

    /**
     * Write a row in COPY format
     * 
     * @param array $row Numeric array of values
     * @param array $escapeModes Escape modes for each column
     */
    protected function writeCopyRow($row, $escapeModes)
    {
        $line = '';
        $first = true;

        foreach ($row as $i => $v) {
            if (!$first) {
                $line .= "\t";
            }
            $first = false;

            if ($v === null) {
                $line .= '\\N';
            } else {
                if ($escapeModes[$i] === self::ESCAPE_BYTEA) {
                    // Bytea - octal escaping for COPY
                    $line .= bytea_to_octal($v);
                } elseif ($escapeModes[$i] === self::ESCAPE_BOOLEAN) {
                    // Booleans in COPY use 't'/'f'
                    if (is_bool($v)) {
                        $line .= ($v ? 't' : 'f');
                    } else {
                        $lv = strtolower((string)$v);
                        $line .= in_array($lv, ['t', 'true', '1', 'yes', 'y'], true) ? 't' : 'f';
                    }
                } else {
                    // COPY escaping: backslash and special chars
                    $v = addcslashes((string)$v, "\0\\\n\r\t");
                    $line .= $v;
                }
            }
        }

        $this->write($line . "\n");
    }

    /**
     * Write INSERT VALUES clause
     * 
     * @param array $row Numeric array of values
     * @param array $escapeModes Escape modes for each column
     */
    protected function writeInsertValues($row, $escapeModes)
    {
        $values = "(";
        $first = true;

        foreach ($row as $i => $v) {
            if (!$first) {
                $values .= ",";
            }
            $first = false;

            if ($v === null) {
                $values .= "NULL";
            } elseif ($escapeModes[$i] === self::ESCAPE_STRING) {
                // String escaping
                $values .= $this->connection->conn->qstr($v);
            } elseif ($escapeModes[$i] === self::ESCAPE_BYTEA) {
                // Bytea escaping
                $values .= "'\\x" . bin2hex($v) . "'";
            } elseif ($escapeModes[$i] === self::ESCAPE_BOOLEAN) {
                // Booleans should be emitted as SQL boolean literals
                if (is_bool($v)) {
                    $values .= ($v ? 'TRUE' : 'FALSE');
                } else {
                    $lv = strtolower((string)$v);
                    $values .= in_array($lv, ['t', 'true', '1', 'yes', 'y'], true) ? 'TRUE' : 'FALSE';
                }
            } else {
                // No escaping (numeric/boolean)
                $values .= $v;
            }
        }

        $values .= ")";
        $this->write($values);
    }
}
