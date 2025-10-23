<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Export\Compression\CompressionFactory;

/**
 * DumpRenderer - Renders database export forms for all subject types
 * Unifies export form rendering across server, database, schema, table, and view contexts
 */
class ExportFormRenderer
{
    private $pg;
    private $misc;
    private $lang;

    public function __construct()
    {
        $this->pg = AppContainer::getPostgres();
        $this->misc = AppContainer::getMisc();
        $this->lang = AppContainer::getLang();
    }

    /**
     * Render export form for a specific subject
     *
     * @param string $subject The export subject: 'server', 'database', 'schema'
     * @param array $params Optional parameters (database, schema, table, view names)
     */
    public function renderExportForm(string $subject, array $params = []): void
    {
        $subject = strtolower(trim($subject));
        $compressionCaps = CompressionFactory::capabilities();

        ?>
        <style>
        </style>
        <form action="dbexport.php" id="export-form" class="export-form" method="get">

            <!-- Export Method -->
            <fieldset>
                <legend><?= $this->lang['strexportmethod']; ?></legend>
                <div>
                    <div class="mx-1">
                        <input type="radio" id="dumper_internal" name="dumper" value="internal" checked="checked" />
                        <label for="dumper_internal"><?= $this->lang['strexportmethod_internal']; ?></label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="dumper_pgdump" name="dumper" value="pgdump" />
                        <label for="dumper_pgdump"><?= $this->lang['strexportmethod_pgdump']; ?></label>
                    </div>
                    <?php if ($subject === 'server'): ?>
                        <div class="mx-1">
                            <input type="radio" id="dumper_pg_dumpall" name="dumper" value="pg_dumpall" />
                            <label for="dumper_pg_dumpall"><?= $this->lang['strexportmethod_pgdumpall']; ?></label>
                        </div>
                    <?php endif; ?>
                </div>
            </fieldset>

            <!-- Export Type Selection -->
            <fieldset>
                <legend><?= $this->lang['strexporttype']; ?></legend>
                <div class="flex-row flex-wrap">
                    <div class="mx-1">
                        <input type="radio" id="what_both" name="what" value="structureanddata" checked="checked" />
                        <label for="what_both"><?= $this->lang['strstructureanddata']; ?></label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="what_struct" name="what" value="structureonly" />
                        <label for="what_struct"><?= $this->lang['strstructureonly']; ?></label>
                    </div>
                    <div class="mx-1">
                        <input type="radio" id="what_data" name="what" value="dataonly" />
                        <label for="what_data"><?= $this->lang['strdataonly']; ?></label>
                    </div>
                </div>
            </fieldset>

            <!-- Cluster-Level Objects (Server Export Only) -->
            <?php if ($subject === 'server'): ?>
                <fieldset>
                    <legend><?= $this->lang['strclusterlevelobjects']; ?></legend>
                    <div class="flex-row flex-wrap">
                        <div class="mx-1">
                            <input type="checkbox" id="export_roles" name="export_roles" value="true" checked="checked" />
                            <label for="export_roles">
                                <img src="<?= $this->misc->icon('Roles') ?>" class="icon">
                                <?= $this->lang['strexportroles']; ?>
                            </label>
                        </div>
                        <div class="mx-1">
                            <input type="checkbox" id="export_tablespaces" name="export_tablespaces" value="true"
                                checked="checked" />
                            <label for="export_tablespaces">
                                <img src="<?= $this->misc->icon('Tablespaces') ?>" class="icon">
                                <?= $this->lang['strexporttablespaces']; ?>
                            </label>
                        </div>
                    </div>
                </fieldset>

            <?php endif; ?>

            <!-- Object Selection for non-server exports -->
            <fieldset id="object_selection">
                <legend>
                    <!--
                    <?= sprintf(
                        $this->lang['strselectobjectstoexport'] ?? 'Select %s to export',
                        ucfirst($params['name'] ?? 'objects')
                    ) ?>
                    -->
                    <input type="checkbox" id="export_all_objects" name="export_all_objects" value="true" checked="checked" />
                    <label for="export_all_objects"><?= $this->lang['strexportallobjects'] ?? 'Export all objects'; ?></label>
                </legend>
                <?php
                $objectGroups = $params['objects_by_type'] ?? [];
                $iconGroups = $params['icons_by_type'] ?? [];
                $groupLabels = [
                    'databases' => $this->lang['strdatabases'] ?? 'Databases',
                    'schemas' => $this->lang['strschemas'] ?? 'Schemas',
                    'tables' => $this->lang['strtables'] ?? 'Tables',
                    'views' => $this->lang['strviews'] ?? 'Views',
                    'sequences' => $this->lang['strsequences'] ?? 'Sequences',
                ];
                $groupIcons = [
                    'databases' => 'Database',
                    'schemas' => 'Schema',
                    'tables' => 'Table',
                    'views' => 'View',
                    'sequences' => 'Sequence'
                ];
                foreach (array_keys($objectGroups) as $groupKey) {
                    $groupObjects = $objectGroups[$groupKey] ?? [];
                    $objectIcons = $iconGroups[$groupKey] ?? null;
                    $count = is_array($groupObjects) ? count($groupObjects) : 0;
                    ?>
                    <div class="object-selection-group" data-group="<?= html_esc($groupKey); ?>">
                        <div class="my-1">
                            <strong>
                                <?= html_esc($groupLabels[$groupKey] ?? ucfirst($groupKey)); ?> (<?= $count; ?>)
                            </strong>
                            &nbsp;
                            <a href="javascript:void(0);" onclick="toggleObjects(true, '<?= html_esc($groupKey); ?>')">
                                <?= $this->lang['strselectall'] ?>
                            </a>
                            &nbsp;|&nbsp;
                            <a href="javascript:void(0);" onclick="toggleObjects(false, '<?= html_esc($groupKey); ?>')">
                                <?= $this->lang['strunselectall'] ?>
                            </a>
                        </div>
                        <div class="flex-column flex-wrap object-selection-list">
                            <?php
                            foreach ($groupObjects as $objName) {
                                $objId = $subject . '_' . $groupKey . '_' . $objName;
                                ?>
                                <div>
                                    <input type="checkbox" id="<?= html_esc($objId); ?>" name="objects[]"
                                        value="<?= html_esc($objName); ?>" data-group="<?= html_esc($groupKey); ?>" checked="checked" />
                                    <label for="<?= html_esc($objId); ?>">
                                        <img src="<?= $this->misc->icon($objectIcons[$objName] ?? $groupIcons[$groupKey] ?? ''); ?>"
                                            class="icon">
                                        <?= html_esc($objName); ?>
                                    </label>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </fieldset>


            <!-- Structure Export Options -->
            <fieldset id="structure_options">
                <legend><?= $this->lang['strstructureoptions']; ?></legend>
                <?php if ($subject === 'schema'): ?>
                    <div class="my-1 ms-1">
                        <input type="checkbox" id="include_schema_objects" name="include_schema_objects" value="true"
                            checked="checked" />
                        <label for="include_schema_objects">
                            <?= $this->lang['strincludeschemaobjects'] ?? 'Include schema objects (functions, types, etc.)'; ?>
                        </label>
                    </div>
                <?php endif; ?>
                <div class="my-1 ms-1">
                    <input type="checkbox" id="include_comments" name="include_comments" value="true" checked="checked" />
                    <label for="include_comments"><?= $this->lang['strincludeobjectcomments']; ?></label>
                </div>
                <div class="my-1 ms-1">
                    <input type="checkbox" id="if_not_exists" name="if_not_exists" value="true" />
                    <label for="if_not_exists"><?= $this->lang['struseifnotexists']; ?></label>
                </div>
                <div class="my-1 ms-1">
                    <input type="checkbox" id="drop_objects" name="drop_objects" value="true" />
                    <label for="drop_objects"><?= $this->lang['stradddropstatements']; ?>
                    </label>
                </div>
                <div class="my-1 ms-1">
                    <input type="checkbox" id="no_owner" name="no_owner" value="true" />
                    <label
                        for="no_owner"><?= $this->lang['strnoowner'] ?? 'Do not output commands to set ownership of objects'; ?></label>
                </div>
                <div class="my-1 ms-1">
                    <input type="checkbox" id="no_privileges" name="no_privileges" value="true" />
                    <label
                        for="no_privileges"><?= $this->lang['strnoprivileges'] ?? 'Do not output privileges (GRANT/REVOKE)'; ?></label>
                </div>
                <?php if ($subject === 'server' || $subject === 'database'): ?>
                    <div class="my-1 ms-1">
                        <input type="checkbox" id="add_create_database" name="add_create_database" value="true" />
                        <label for="add_create_database"><?= $this->lang['stradddbcreation'] ?? 'Add database creation'; ?></label>
                    </div>
                <?php endif; ?>
                <?php if ($subject === 'schema' || $subject === 'database'): ?>
                    <div class="my-1 ms-1">
                        <input type="checkbox" id="add_create_schema" name="add_create_schema" value="true" />
                        <label for="add_create_schema"><?= $this->lang['straddschemacreation'] ?? 'Add schema creation'; ?></label>
                    </div>
                <?php endif; ?>
            </fieldset>

            <!-- Output Format Selection (Unified with INSERT options for SQL) -->
            <fieldset id="format_options">
                <legend><?= $this->lang['strexportformat']; ?></legend>

                <!-- Format Selection -->
                <div class="flex-row flex-wrap" id="format_selection">
                    <div class="mx-1">
                        <input type="radio" id="format_sql" name="output_format" value="sql" checked="checked" />
                        <label for="format_sql">SQL</label>
                    </div>
                </div>

                <!-- INSERT Format Options (only shown when SQL format is selected and data is included) -->
                <div id="insert_format_options" class="mt-2" style="display:none">
                    <hr>
                    <p><strong><?= $this->lang['strinsertformat_desc']; ?></strong></p>
                    <div class="ml-3">
                        <div>
                            <input type="radio" id="insert_copy" name="insert_format" value="copy" checked="checked" />
                            <label for="insert_copy"><?= $this->lang['strcopyformat']; ?></label>
                        </div>
                        <div>
                            <input type="radio" id="insert_multi" name="insert_format" value="multi" />
                            <label for="insert_multi"><?= $this->lang['strmultirowinserts']; ?></label>
                        </div>
                        <div>
                            <input type="radio" id="insert_single" name="insert_format" value="single" />
                            <label for="insert_single"><?= $this->lang['strsingleinserts']; ?></label>
                        </div>
                    </div>

                    <!-- TRUNCATE option -->
                    <div style="margin-top: 10px; margin-left: 20px;">
                        <input type="checkbox" id="truncate_tables" name="truncate_tables" value="true" />
                        <label for="truncate_tables"><?= $this->lang['strtruncatebeforeinsert']; ?></label>
                    </div>
                </div>
            </fieldset>

            <!-- Output Options: composite `output` values -->
            <fieldset>
                <legend><?= $this->lang['stroutput']; ?></legend>
                <div>
                    <input type="radio" id="output_show" name="output" value="show" checked="checked" />
                    <label for="output_show"><?= $this->lang['strshowinbrowser']; ?></label>
                </div>
                <div>
                    <input type="radio" id="output_download" name="output" value="download" />
                    <label for="output_download"><?= $this->lang['strdownloadasfile']; ?></label>
                </div>
                <?php if ($compressionCaps['gzip'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_gzip" name="output" value="download-gzip" />
                        <label for="output_download_gzip"><?= $this->lang['strdownloadasgzip'] ?></label>
                    </div>
                <?php endif ?>
                <?php if ($compressionCaps['bzip2'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_bzip2" name="output" value="download-bzip2" />
                        <label for="output_download_bzip2"><?= $this->lang['strdownloadasbzip2'] ?></label>
                    </div>
                <?php endif ?>
                <?php if ($compressionCaps['gzip'] ?? false): ?>
                    <div>
                        <input type="radio" id="output_download_zip" name="output" value="download-zip" />
                        <label for="output_download_zip"><?= $this->lang['strdownloadaszip'] ?></label>
                    </div>
                <?php endif ?>
            </fieldset>

            <p>
                <input type="hidden" name="subject" value="<?= html_esc($subject); ?>" />
                <?= $this->misc->form; ?>
                <input type="submit" value="<?= $this->lang['strexport']; ?>" />
            </p>
        </form>

        <script>
            {
                // Toggle object checkboxes in the selection fieldset (optionally by group)
                function toggleObjects(check, group) {
                    const form = document.getElementById('export-form');
                    if (!form) return;

                    const exportAllObjects = document.getElementById('export_all_objects');
                    if (exportAllObjects && exportAllObjects.checked) {
                        return;
                    }

                    let selector = 'input[name="objects[]"]';
                    if (group) {
                        selector += `[data-group="${group}"]`;
                    }
                    const objectCheckboxes = form.querySelectorAll(selector);
                    objectCheckboxes.forEach(cb => {
                        cb.checked = !!check;
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }

                // Show/hide options based on export type and dumper selection
                const form = document.getElementById('export-form');
                const whatRadios = form.querySelectorAll('input[name="what"]');
                const dumperRadios = form.querySelectorAll('input[name="dumper"]');
                const outputFormatRadios = form.querySelectorAll('input[name="output_format"]');
                const structureOptions = document.getElementById('structure_options');
                const formatOptions = document.getElementById('format_options');
                const insertFormatOptions = document.getElementById('insert_format_options');
                const insertMulti = document.getElementById('insert_multi');
                const ifNotExists = document.getElementById('if_not_exists');
                const includeComments = document.getElementById('include_comments');
                const truncateTables = document.getElementById('truncate_tables');
                const objectSelection = document.getElementById('object_selection');
                const exportRoles = document.getElementById('export_roles');
                const exportTablespaces = document.getElementById('export_tablespaces');
                const includeSchemaObjects = document.getElementById('include_schema_objects');
                const exportAllObjects = document.getElementById('export_all_objects');

                // Only setup if form elements exist on this page
                if (whatRadios.length > 0 && structureOptions) {
                    const addCreateSchema = document.getElementById('add_create_schema');
                    const addCreateDb = document.getElementById('add_create_database');

                    function updateOptions() {
                        const selectedWhat = form.querySelector('input[name="what"]:checked').value;
                        const dumperValue = form.querySelector('input[name="dumper"]:checked').value;
                        const selectedFormat = form.querySelector('input[name="output_format"]:checked').value;
                        const pgdumpSelected = dumperValue === 'pgdump';
                        const pgdumpallSelected = dumperValue === 'pg_dumpall';
                        const isSqlFormat = selectedFormat === 'sql';
                        const hasData = selectedWhat === 'dataonly' || selectedWhat === 'structureanddata';

                        // Show/hide structure options based on export type
                        if (selectedWhat === 'dataonly') {
                            structureOptions.style.display = 'none';
                        } else {
                            structureOptions.style.display = 'block';
                        }

                        // Show/hide INSERT format options only when:
                        // - SQL format is selected (INSERT options only apply to SQL)
                        if (isSqlFormat && insertFormatOptions) {
                            insertFormatOptions.style.display = 'block';
                        } else {
                            insertFormatOptions.style.display = 'none';
                        }

                        // Show/hide format options fieldset based on data export mode
                        if (formatOptions) {
                            if (selectedWhat === 'structureonly' && !pgdumpallSelected) {
                                formatOptions.style.display = 'none';
                            } else {
                                formatOptions.style.display = 'block';
                            }
                        }

                        // For pg_dumpall: hide structure options and DB selection
                        if (pgdumpallSelected) {
                            structureOptions.style.display = 'none';
                            if (objectSelection) objectSelection.style.display = 'none';
                        } else {
                            // For internal and pg_dump: show structure based on what
                            if (selectedWhat === 'dataonly') {
                                structureOptions.style.display = 'none';
                            } else {
                                structureOptions.style.display = 'block';
                            }
                            if (objectSelection) objectSelection.style.display = 'block';
                        }

                        // Count selected databases for pg_dump smart logic
                        const objectCheckboxes = form.querySelectorAll('input[name="objects[]"]');

                        // Handle "Export All Objects" checkbox
                        if (exportAllObjects) {
                            const exportAll = !!exportAllObjects.checked;
                            const enabled = !exportAll;
                            objectCheckboxes.forEach(cb => {
                                if (!enabled) {
                                    cb.checked = true;
                                }
                                cb.disabled = !enabled;
                            });

                            const toggleLinks = form.querySelectorAll('#object_selection a');
                            toggleLinks.forEach(a => {
                                if (enabled) {
                                    a.classList.remove('disabled');
                                } else {
                                    a.classList.add('disabled');
                                }
                            });
                        }

                        const checkedCount = Array.from(objectCheckboxes).filter(cb => cb.checked).length;
                        const totalCount = objectCheckboxes.length;
                        const allObjectsSelected = checkedCount === totalCount && totalCount > 0;

                        // Control cluster object checkboxes depending on dumper
                        if (exportRoles) {
                            if (pgdumpallSelected) {
                                exportRoles.checked = true;
                                exportRoles.disabled = true;
                            } else if (pgdumpSelected && allObjectsSelected) {
                                // Enable for pg_dump only if all DBs selected
                                exportRoles.disabled = false;
                            } else if (pgdumpSelected) {
                                exportRoles.checked = false;
                                exportRoles.disabled = true;
                            } else {
                                // internal: always enabled
                                exportRoles.disabled = false;
                            }
                        }
                        if (exportTablespaces) {
                            if (pgdumpallSelected) {
                                exportTablespaces.checked = true;
                                exportTablespaces.disabled = true;
                            } else if (pgdumpSelected && allObjectsSelected) {
                                // Enable for pg_dump only if all DBs selected
                                exportTablespaces.disabled = false;
                            } else if (pgdumpSelected) {
                                exportTablespaces.checked = false;
                                exportTablespaces.disabled = true;
                            } else {
                                // internal: always enabled
                                exportTablespaces.disabled = false;
                            }
                        }

                        // Disable INSERT format options that pg_dumpall doesn't support
                        if (insertMulti) {
                            insertMulti.disabled = pgdumpallSelected;
                            // If multi-row is selected and pg_dumpall is enabled, switch to COPY
                            if (pgdumpallSelected && insertMulti.checked) {
                                document.getElementById('insert_copy').checked = true;
                            }
                        }

                        if (ifNotExists) {
                            ifNotExists.disabled = pgdumpSelected || pgdumpallSelected;
                            // If IF NOT EXISTS is checked and pg_dump/pg_dumpall is enabled, uncheck it
                            if ((pgdumpSelected || pgdumpallSelected) && ifNotExists.checked) {
                                ifNotExists.checked = false;
                            }
                        }

                        if (truncateTables) {
                            truncateTables.disabled = pgdumpSelected || pgdumpallSelected;
                            // If TRUNCATE is checked and pg_dump/pg_dumpall is enabled, uncheck it
                            if ((pgdumpSelected || pgdumpallSelected) && truncateTables.checked) {
                                truncateTables.checked = false;
                            }
                        }

                        if (includeSchemaObjects) {
                            if (pgdumpSelected || pgdumpallSelected) {
                                includeSchemaObjects.checked = true;
                                includeSchemaObjects.disabled = true;
                            } else {
                                includeSchemaObjects.disabled = false;
                                if (!includeSchemaObjects.dataset.changed) {
                                    includeSchemaObjects.checked = exportAllObjects?.checked ?? true;
                                }
                            }
                        }

                        // Disable add-create options when using pg_dump/pg_dumpall (pg_dump controls creation)
                        if (addCreateSchema) {
                            addCreateSchema.disabled = pgdumpSelected || pgdumpallSelected;
                            if (addCreateSchema.disabled) {
                                addCreateSchema.checked = false;
                            }
                        }
                        if (addCreateDb) {
                            addCreateDb.disabled = pgdumpSelected || pgdumpallSelected;
                            if (addCreateDb.disabled) {
                                addCreateDb.checked = false;
                            }
                        }

                        // pg_dump and pg_dumpall always include comments, so check and disable the option
                        if (includeComments && (pgdumpSelected || pgdumpallSelected)) {
                            includeComments.checked = true;
                        }
                    }

                    // Prevent unchecking include_comments when pg_dump or pg_dumpall is selected
                    if (includeComments) {
                        includeComments.addEventListener('change', function (e) {
                            const dumperValue = form.querySelector('input[name="dumper"]:checked').value;
                            if ((dumperValue === 'pgdump' || dumperValue === 'pg_dumpall') && !this.checked) {
                                // User tried to uncheck while pg_dump/pg_dumpall is active - re-check it
                                this.checked = true;
                            }
                        });
                    }

                    // Disable "what" radio buttons for pg_dumpall since it always does full dump
                    function updateWhatRadios() {
                        const dumperValue = form.querySelector('input[name="dumper"]:checked').value;
                        const pgdumpallSelected = dumperValue === 'pg_dumpall';
                        whatRadios.forEach(radio => {
                            radio.disabled = pgdumpallSelected;
                            // For pg_dumpall, force structureanddata selection
                            if (pgdumpallSelected && radio.value === 'structureanddata') {
                                radio.checked = true;
                            }
                        });
                    }

                    whatRadios.forEach(radio => radio.addEventListener('change', updateOptions));
                    dumperRadios.forEach(radio => {
                        radio.addEventListener('change', updateOptions);
                        radio.addEventListener('change', updateWhatRadios);
                    });
                    outputFormatRadios.forEach(radio => radio.addEventListener('change', updateOptions));
                    const objectCheckboxes = form.querySelectorAll('input[name="objects[]"]');
                    objectCheckboxes.forEach(cb => cb.addEventListener('change', updateOptions));
                    if (exportAllObjects) exportAllObjects.addEventListener('change', updateOptions);
                    if (includeSchemaObjects) includeSchemaObjects.addEventListener('change', () => {
                        includeSchemaObjects.dataset.changed = '1';
                    });
                    updateOptions(); // Initial state
                    updateWhatRadios(); // Set initial what radio state
                }
            }
        </script>
        <?php
    }
}
