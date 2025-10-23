<?php

namespace PhpPgAdmin\Database\Import\Data;

use RuntimeException;
use PhpPgAdmin\Database\Postgres;
use PhpPgAdmin\Database\Import\LogCollector;
use PhpPgAdmin\Database\Import\CopyStreamHandler;
use PhpPgAdmin\Database\Import\Exception\CopyException;

class DataImportExecutor
{
    /** @var Postgres */
    private $pg;
    /** @var LogCollector */
    private $logs;

    public function __construct(Postgres $pg, LogCollector $logs)
    {
        $this->pg = $pg;
        $this->logs = $logs;
    }

    /**
     * @param string $decoded    payload (remainder already prepended by client)
     * @param array  $options    ['format','use_header','allowed_nulls','schema','table']
     * @param array  $state      session state for this import
     *
     * @return array{remainder:string, errors:int}
     */
    public function process(string $decoded, array $options, array &$state): array
    {
        $errors = 0;

        $format = $options['format'] ?? 'csv';
        $useHeader = !empty($options['use_header']);
        $allowedNulls = $options['allowed_nulls'] ?? [];
        $byteaEncoding = $options['bytea_encoding'] ?? 'hex';
        $schema = $options['schema'] ?? '';
        $table = $options['table'] ?? '';

        $parser = $this->makeParser($format, $useHeader);

        $parseState = $state['parser'] ?? [];
        $result = $parser->parse($decoded, $parseState);
        $state['parser'] = $parseState;
        //print_r($result);

        $rows = $result['rows'];
        $remainder = $result['remainder'];
        $headerRow = $result['header'] ?? null;

        // If XML header was found, force useHeader
        if ($headerRow !== null) {
            $useHeader = true;
        }

        // Build header mapping once
        if (empty($state['header_validated'])) {
            // If we need header from first data row but none arrived yet,
            // wait for next chunk
            if ($headerRow === null && empty($rows)) {
                return ['remainder' => $remainder, 'errors' => $errors];
            }

            $colBuilder = new ColumnHeaderBuilder($this->pg);
            $firstDataCols = empty($rows) ? null : count($rows[0]);
            $mappingInfo = $colBuilder->build(
                $schema,
                $table,
                ($useHeader ? $headerRow : null),
                $firstDataCols
            );

            $state['mapping'] = $mappingInfo['mapping'];
            $state['meta'] = $mappingInfo['meta'];
            $state['serial_omitted'] = $mappingInfo['serial_omitted'];
            $state['header_validated'] = true;
            if ($useHeader && $headerRow !== null) {
                $this->logs->addInfo('Header mapped to table columns: ' . implode(', ', $state['mapping']));
            }
        }

        if (empty($rows)) {
            return ['remainder' => $remainder, 'errors' => $errors];
        }

        $converter = new RowToCopyConverter($allowedNulls, $byteaEncoding);
        $isAssoc = $parser->isAssociative();

        $copyHeader = $this->buildCopyHeader($schema, $table, $state['mapping']);

        $dataLines = '';
        foreach ($rows as $row) {
            $dataLines .= $converter->toCopyLine($row, $state['mapping'], $state['meta'], $isAssoc);
        }

        //$this->logs->addInfo("COPY header and data: " . $copyHeader . $dataLines);

        //ini_set('html_errors', '0');
        //echo $copyHeader, $dataLines;
        //exit;

        $copyHandler = new CopyStreamHandler($this->logs, $this->pg, $state, ['truncate' => !empty($options['truncate'])], 'table', $table, $schema);
        try {
            $copyHandler->stream($copyHeader, $dataLines);
            $this->logs->addInfo('Chunk inserted rows=' . count($rows));
        } catch (CopyException $e) {
            $errors++;
            $this->logs->addError($e->getMessage());
        } catch (RuntimeException $e) {
            $errors++;
            $this->logs->addError($e->getMessage());
        }

        return ['remainder' => $remainder, 'errors' => $errors];
    }

    private function makeParser(string $format, bool $useHeader): RowStreamingParser
    {
        switch ($format) {
            case 'json':
                return new JsonRowParser();
            case 'xml':
                return new XmlRowParser();
            case 'tsv':
                return new CsvRowParser("\t", $useHeader);
            case 'csv':
            default:
                return new CsvRowParser(',', $useHeader);
        }
    }

    private function buildCopyHeader(string $schema, string $table, array $columns): string
    {
        $parts = [];
        foreach ($columns as $col) {
            $parts[] = $this->pg->escapeIdentifier($col);
        }
        $ident = $this->pg->escapeIdentifier($schema) . '.' . $this->pg->escapeIdentifier($table);
        return 'COPY ' . $ident . ' (' . implode(', ', $parts) . ") FROM stdin;\n";
    }
}