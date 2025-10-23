<?php

namespace PhpPgAdmin\Database\Export;

/**
 * Tab-Delimited Format Formatter
 * Converts table data to tab-separated values with quoted fields
 */
class TabFormatter extends CsvFormatter
{
    public function __construct()
    {
        parent::__construct("\t", "\n", 'tsv');
    }
}
