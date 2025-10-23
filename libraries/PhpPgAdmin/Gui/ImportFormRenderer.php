<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Database\Export\Compression\CompressionFactory;
use PhpPgAdmin\Database\Actions\RoleActions;

class ImportFormRenderer extends AppContext
{
    public function renderImportForm(string $scope, array $options = []): void
    {
        $lang = $this->lang();
        $conf = $this->conf();
        $misc = $this->misc();
        $chunkSize = $conf['import_upload_chunk_size'] ?? 5 * 1024 * 1024;
        $chunkAttr = 'data-import-chunk-size="' . htmlspecialchars($chunkSize) . '"';

        // determine if current user is superuser to show admin controls
        $pg = $this->postgres();
        $roleActions = new RoleActions($pg);
        $isSuper = $roleActions->isSuperUser();
        ?>
        <form id="importForm" method="post" enctype="multipart/form-data" action="#" data-action="dbimport.php"
            data-import-type="db">
            <input type="hidden" name="scope" id="import_scope" value="<?= htmlspecialchars($scope) ?>" />
            <input type="hidden" name="scope_ident" id="import_scope_ident"
                value="<?= htmlspecialchars($options['scope_ident'] ?? '') ?>" />
            <?= $misc->form ?>

            <fieldset>
                <legend><?= $lang['struploadfile'] ?></legend>
                <input type="file" name="file" id="file" <?= $chunkAttr ?> />
                <div id="importCompressionCaps" style="margin-top:6px">
                    <strong><?= $lang['strimportcompressioncaps'] ?>:</strong> gzip, bzip2, zip
                </div>
                <div class="info mt-2">
                    <?= $lang['strimportintro'] ?>
                </div>
            </fieldset>

            <fieldset>
                <legend><?= $lang['stroptions'] ?></legend>
                <?php if ($scope === 'server'): ?>
                    <div class="my-1 ml-1"><label><input type="checkbox" name="opt_roles" checked />
                            <?= $lang['strimportroles'] ?></label></div>
                    <div class="my-1 ml-1"><label><input type="checkbox" name="opt_tablespaces" checked />
                            <?= $lang['strimporttablespaces'] ?></label></div>
                    <div class="my-1 ml-1"><label><input type="checkbox" name="opt_databases" checked />
                            <?= $lang['strimportdatabases'] ?></label></div>
                <?php endif; ?>
                <?php if ($scope === 'database' || $scope === 'server'): ?>
                    <div class="my-1 ml-1"><label><input type="checkbox" name="opt_schema_create" checked />
                            <?= $lang['strcreateschema'] ?></label></div>
                <?php endif; ?>
                <div class="my-1 ml-1"><label><input type="checkbox" name="opt_data" checked />
                        <?= $lang['strimportdata'] ?></label></div>
                <?php if ($scope === 'schema' || $scope === 'table'): ?>
                    <div class="my-1 ml-1"><label><input type="checkbox" name="opt_truncate" />
                            <?= $lang['strtruncatebefore'] ?></label>
                    </div>
                <?php endif; ?>
                <div class="my-1 ml-1"><label><input type="checkbox" name="opt_ownership" checked />
                        <?= $lang['strimportownership'] ?></label></div>
                <div class="my-1 ml-1"><label><input type="checkbox" name="opt_rights" checked />
                        <?= $lang['strimportrights'] ?></label></div>
                <div class="my-1 ml-1"><label><input type="checkbox" name="opt_defer_self" checked />
                        <?= $lang['strdeferself'] ?></label></div>
                <div class="my-1 ml-1"><label><input type="checkbox" name="opt_allow_drops" checked />
                        <?= $lang['strimportallowdrops'] ?></label></div>
                <div class="my-1 ml-1"><label><input type="checkbox" name="opt_ignore_connect" checked />
                        <?= $lang['strimportignoreconnect'] ?></label></div>
                <div class="my-1 ml-1"><label><input type="checkbox" name="opt_stop_on_error" checked />
                        <?= $lang['strimportstoponerror'] ?>
                    </label></div>
                <div class="my-1 ml-1"><label><input type="checkbox" name="opt_verbose" />
                        <?= $lang['strimportverbose'] ?>
                    </label></div>
                <?php if (function_exists('gzopen')): ?>
                    <div class="my-1 ml-1"><label><input type="checkbox" name="opt_compress_chunks" />
                            <?= $lang['strimportcompresschunks'] ?>
                        </label></div>
                <?php endif; ?>
            </fieldset>

            <div class="form-group">
                <button type="button" id="importStart"><?= $lang['strimport'] ?></button>
            </div>
        </form>

        <div id="importUI" class="mt-3" style="display:none;">
            <h4><?= $lang['strimport'] ?>
                <span id="importTitle" class="importTitle"></span> -
                <?= $lang['strprogress'] ?>
            </h4>
            <progress id="importProgress" value="0" max="100" style="width:100%"></progress>
            <div id="importStatus" class="importStatus">
                <?= $lang['strimport'] ?>
            </div>
            <div class="mt-2">
                <button id="importStopBtn" type="button" style="display:none">
                    <?= $lang['strstop'] ?>
                </button>
            </div>
            <pre id="importLog" class="importLog"></pre>
        </div>

        <script type="module" src="js/import/stream_upload.js"></script>
        <?php
    }

    public function renderDataImportForm(string $scope, array $options = []): void
    {
        $conf = $this->conf();
        $lang = $this->lang();
        $misc = $this->misc();
        $importCfg = $conf['import'] ?? [];
        $maxSize = (int) ($importCfg['upload_max_size'] ?? 0);
        $chunkSize = (int) ($importCfg['upload_chunk_size'] ?? 2 * 1024 * 1024);
        $maxAttr = 'data-import-max-size="' . htmlspecialchars((string) $maxSize) . '"';
        $chunkAttr = 'data-import-chunk-size="' . htmlspecialchars((string) $chunkSize) . '"';
        ?>
        <form id="importForm" method="post" enctype="multipart/form-data" action="#" data-action="dataimport.php"
            data-import-type="data">
            <input type="hidden" name="scope" id="import_scope" value="<?= html_esc($scope) ?>" />
            <input type="hidden" name="scope_ident" id="import_scope_ident" value="<?= html_esc($_REQUEST[$scope]) ?>" />
            <?= $misc->form ?>
            <input type="hidden" name="<?= html_esc($scope) ?>" value="<?= html_esc($options[$scope] ?? '') ?>" />

            <fieldset>
                <legend><?= $lang['struploadfile'] ?></legend>
                <input type="file" name="file" id="file" <?= $maxAttr ?>         <?= $chunkAttr ?> />
                <div id="importCompressionCaps" style="margin-top:6px">
                    <strong><?= $lang['strimportcompressioncaps'] ?>:</strong> gzip, bzip2, zip
                </div>
                <div class="info mt-2">
                    <?= $lang['strimportintro'] ?>
                </div>
            </fieldset>

            <fieldset>
                <legend>
                    <?= $lang['strformat'] ?>
                </legend>
                <div class="flex-row">
                    <label>
                        <input type="radio" name="format" value="auto" checked />
                        <?= $lang['strauto'] ?>
                    </label>
                    <label class="ml-3">
                        <input type="radio" name="format" value="csv" /> CSV
                    </label>
                    <label class="ml-3">
                        <input type="radio" name="format" value="tab" /> TSV
                    </label>
                    <label class="ml-3">
                        <input type="radio" name="format" value="xml" /> XML
                    </label>
                    <label class="ml-3">
                        <input type="radio" name="format" value="json" /> JSON
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend><?= $lang['strallowednulls'] ?></legend>
                <div class="flex-row">
                    <label>
                        <input type="checkbox" name="allowed_nulls[0]" value="\N" checked="checked" />
                        <?= $lang['strbackslashn'] ?>
                    </label>
                    <label class="ml-3">
                        <input type="checkbox" name="allowed_nulls[1]" value="NULL" />
                        NULL
                    </label>
                    <label class="ml-3">
                        <input type="checkbox" name="allowed_nulls[2]" value="" />
                        <?= $lang['stremptystring'] ?>
                    </label>
                </div>
            </fieldset>

            <fieldset id="bytea_encoding">
                <legend>
                    <?= $lang['strbyteaencoding'] ?>
                </legend>
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

            <fieldset>
                <legend><?= $lang['stroptions'] ?></legend>
                <div><label><input type="checkbox" name="use_header" value="1" checked />
                        <?= $lang['strusefirstrowheaders'] ?></label></div>
                <div class="mt-1"><label><input type="checkbox" name="opt_truncate" value="1" />
                        <?= $lang['strtruncatebefore'] ?></label></div>
                <?php if (function_exists('gzopen')): ?>
                    <div class="mt-1"><label><input type="checkbox" name="opt_compress_chunks" />
                            <?= $lang['strimportcompresschunks'] ?>
                        </label></div>
                <?php endif; ?>
            </fieldset>

            <div class="form-group">
                <button type="button" id="importStart">
                    <?= $lang['strimport'] ?>
                </button>
            </div>
        </form>

        <div id="importUI" class="mt-3" style="display:none;">
            <h4><?= $lang['strimport'] ?> <span id="importTitle" class="importTitle"></span> -
                <?= $lang['strprogress'] ?>
            </h4>
            <progress id="importProgress" value="0" max="100" style="width:100%"></progress>
            <div id="importStatus" class="importStatus">
                <?= $lang['strimport'] ?>
            </div>
            <div class="mt-2">
                <button id="importStopBtn" type="button" style="display:none">
                    <?= $lang['strstop'] ?>
                </button>
            </div>
            <pre id="importLog" class="importLog"></pre>
        </div>

        <script type="module" src="js/import/stream_upload.js"></script>
        <?php
    }
}
