<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Export\Compression\CompressionFactory;

/**
 * QueryExportRenderer - Renders export form for SQL query results
 * Simplifies format selection for exporting arbitrary query data
 */
class QueryExportRenderer
{
    /**
     * Render export form for query result data
     *
     * @param string $query The SQL query to export results from
     * @param array $params Optional parameters (search_path, schema, etc.)
     * @return void
     */
    public function renderExportForm($query, $params = [])
    {
        $misc = AppContainer::getMisc();
        $lang = AppContainer::getLang();
        $compressionCaps = CompressionFactory::capabilities();
        ?>
        <form action="dataexport.php" id="export-form" method="get">

            <!-- Export Format Selection (Unified with INSERT options for SQL) -->
            <fieldset id="format_options">
                <legend><?= $lang['strexportformat']; ?></legend>

                <!-- Format Selection -->
                <div class="flex-row flex-wrap" id="format_selection">
                    <div class="mx-1">
                        <input type="radio" id="format_sql" name="output_format" value="sql" checked="checked" />
                        <label for="format_sql">SQL</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_csv" name="output_format" value="csv" />
                        <label for="format_csv">CSV</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_tab" name="output_format" value="tab" />
                        <label for="format_tab">TSV</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_html" name="output_format" value="html" />
                        <label for="format_html">XHTML</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_xml" name="output_format" value="xml" />
                        <label for="format_xml">XML</label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="format_json" name="output_format" value="json" />
                        <label for="format_json">JSON</label>
                    </div>
                </div>

                <!-- INSERT Format Options (only shown when SQL format is selected) -->
                <div id="insert_format_options" class="mt-2" style="display:none">
                    <hr>
                    <p><strong><?= $lang['strinsertformat_desc']; ?></strong></p>
                    <div class="ml-3">
                        <div>
                            <input type="radio" id="insert_copy" name="insert_format" value="copy" checked="checked" />
                            <label for="insert_copy"><?= $lang['strcopyformat']; ?></label>
                        </div>
                        <div>
                            <input type="radio" id="insert_multi" name="insert_format" value="multi" />
                            <label for="insert_multi"><?= $lang['strmultirowinserts']; ?></label>
                        </div>
                        <div>
                            <input type="radio" id="insert_single" name="insert_format" value="single" />
                            <label for="insert_single"><?= $lang['strsingleinserts']; ?></label>
                        </div>
                    </div>
                </div>
                <div id="csv_options" class="mt-2" style="display:block">
                    <hr>
                    <p><strong><?= $lang['strcsvoptions']; ?></strong></p>
                    <div class="ml-3">
                        <div>
                            <input type="checkbox" id="column_names" name="column_names" value="true" checked="checked" />
                            <label for="column_names"><?= $lang['strcolumnnames']; ?>
                            </label>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset id="export_nulls">
                <legend><?= $lang['strexportnulls'] ?></legend>
                <div class="flex-row">
                    <label class="mx-1">
                        <input type="radio" name="export_nulls" value="\N" checked="checked" />
                        <?= $lang['strbackslashn'] ?>
                    </label>
                    <label class="mx-1">
                        <input type="radio" name="export_nulls" value="NULL" />
                        NULL
                    </label>
                    <label class="mx-1">
                        <input type="radio" name="export_nulls" value="" />
                        <?= $lang['stremptystring'] ?>
                    </label>
                </div>
            </fieldset>

            <fieldset id="bytea_encoding">
                <legend><?= $lang['strbyteaencoding'] ?></legend>
                <div class="flex-row">
                    <label class="mx-1">
                        <input type="radio" name="bytea_encoding" value="hex" checked="checked" />
                        Hexadecimal
                    </label>
                    <label class="mx-1">
                        <input type="radio" name="bytea_encoding" value="base64" />
                        Base64
                    </label>
                    <label class="mx-1">
                        <input type="radio" name="bytea_encoding" value="octal" />
                        Octal
                    </label>
                </div>
            </fieldset>

            <!-- Output Options -->
            <fieldset>
                <legend><?= $lang['stroutput']; ?></legend>
                <div>
                    <input type="radio" id="output_show" name="output" value="show" checked="checked" />
                    <label for="output_show"><?= $lang['strshowinbrowser']; ?></label>
                </div>
                <div>
                    <input type="radio" id="output_download" name="output" value="download" />
                    <label for="output_download"><?= $lang['strdownloadasfile']; ?></label>
                </div>
                <?php if ($compressionCaps['gzip'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_gzip" name="output" value="download-gzip" />
                        <label for="output_download_gzip"><?= $lang['strdownloadasgzip'] ?></label>
                    </div>
                <?php endif ?>
                <?php if ($compressionCaps['bzip2'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_bzip2" name="output" value="download-bzip2" />
                        <label for="output_download_bzip2"><?= $lang['strdownloadasbzip2'] ?></label>
                    </div>
                <?php endif ?>
                <?php if ($compressionCaps['gzip'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_zip" name="output" value="download-zip" />
                        <label for="output_download_zip"><?= $lang['strdownloadaszip'] ?></label>
                    </div>
                <?php endif ?>
            </fieldset>

            <p>
                <input type="hidden" name="action" value="export" />
                <input type="hidden" name="query" value="<?= html_esc($query); ?>" />
                <?php foreach ($params as $key => $value): ?>
                    <?php if (!in_array($key, ['action', 'query'])): ?>
                        <input type="hidden" name="<?= html_esc($key); ?>" value="<?= html_esc($value); ?>" />
                    <?php endif; ?>
                <?php endforeach; ?>
                <?= $misc->form; ?>
                <input type="submit" value="<?= $lang['strexport']; ?>" />
            </p>
        </form>

        <script>
            {
                const form = document.getElementById('export-form');
                const outputFormatRadios = form.querySelectorAll('input[name="output_format"]');
                const insertFormatOptions = document.getElementById('insert_format_options');
                const csvOptions = document.getElementById('csv_options');
                const exportNullsFieldset = document.getElementById('export_nulls');
                const byteaEncodingFieldset = document.getElementById('bytea_encoding');

                if (outputFormatRadios.length > 0) {
                    function updateOptions() {
                        const selectedFormat = form.querySelector('input[name="output_format"]:checked').value;
                        const isSqlFormat = selectedFormat === 'sql';
                        const showExportNulls = new Set(['csv', 'tab', 'html']).has(selectedFormat);
                        const isCsvTsvFormat = new Set(['csv', 'tab']).has(selectedFormat);

                        if (exportNullsFieldset) exportNullsFieldset.style.display = showExportNulls ? 'block' : 'none';

                        if (csvOptions) csvOptions.style.display = isCsvTsvFormat ? 'block' : 'none';

                        // Show/hide INSERT format options only when SQL format is selected
                        if (isSqlFormat) {
                            if (insertFormatOptions) insertFormatOptions.style.display = 'block';
                            if (byteaEncodingFieldset) byteaEncodingFieldset.style.display = 'none';
                        } else {
                            if (insertFormatOptions) insertFormatOptions.style.display = 'none';
                            if (byteaEncodingFieldset) byteaEncodingFieldset.style.display = 'block';
                        }
                    }

                    outputFormatRadios.forEach(radio => radio.addEventListener('change', updateOptions));
                    updateOptions(); // Initial state
                }
            }
        </script>
        <?php
    }
}
