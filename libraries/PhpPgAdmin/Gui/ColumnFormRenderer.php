<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\TypeActions;
use PhpPgAdmin\Database\Actions\ColumnActions;

/**
 * Reusable renderer for column add/edit forms
 * Handles both single and multi-row column forms with array-based inputs
 */
class ColumnFormRenderer
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
     * Get standard column default presets
     * @return array Associative array of preset values and labels
     */
    public function getColumnDefaults()
    {
        return [
            '' => '',
            'NULL' => 'NULL',
            'CURRENT_TIMESTAMP' => 'CURRENT_TIMESTAMP',
            'CURRENT_TIME' => 'CURRENT_TIME',
            'CURRENT_DATE' => 'CURRENT_DATE',
            'gen_random_uuid()' => 'gen_random_uuid()',
            'uuid_generate_v4()' => 'uuid_generate_v4()',
            "'{}'::jsonb" => "'{}'::jsonb",
            'custom' => $this->lang['strcustom'] ?? 'Custom:',
        ];
    }

    /**
     * Get all available column types
     * @return array Array of type names excluding types that cannot be used for columns
     */
    public function getAllTypes()
    {
        static $allTypes = null;
        if ($allTypes === null) {
            $typeActions = new TypeActions($this->pg);
            $types = $typeActions->getTypes(true, false, true);
            $allTypes = $this->pg->extraTypes;
            while (!$types->EOF) {
                $allTypes[] = $types->fields['typname'];
                $types->moveNext();
            }
            $allTypes = array_diff($allTypes, ColumnActions::EXCLUDE_TYPES);
        }
        return $allTypes;
    }

    /**
     * Render the column form table
     * @param array $columns Array of column data. Each element should be an associative array with keys:
     *                       'attname', 'base_type', 'length', 'attnotnull', 'adsrc', 'comment', 'default_preset'
     *                       For new columns, use empty strings/nulls
     * @param array $postData Optional POST data to repopulate form (used after validation errors)
     * @param array $options Optional rendering options:
     *                       'showUniqueKey' => bool (default: false)
     *                       'showPrimaryKey' => bool (default: false)
     *                       'showPartitionKey' => bool (default: false)
     */
    public function renderTable($columns, $postData = null, $options = [])
    {
        ?>
        <table id="columnsTable">
            <tr>
                <th class="data required"><?= $this->lang['strname'] ?></th>
                <th class="data required" colspan="2"><?= $this->lang['strtype'] ?></th>
                <th class="data"><?= $this->lang['strlength'] ?></th>
                <th class="data text-center"><?= $this->lang['strnotnull'] ?></th>
                <?php if ($this->pg->hasGeneratedColumns() && !($options['isAlter'] ?? false)): ?>
                    <th class="data text-center"><?= $this->lang['strgenerated'] ?></th>
                <?php endif; ?>
                <?php if ($options['showUniqueKey'] ?? false): ?>
                    <th class="data text-center"><?= $this->lang['struniquekey'] ?></th>
                <?php endif; ?>
                <?php if ($options['showPrimaryKey'] ?? false): ?>
                    <th class="data text-center"><?= $this->lang['strprimarykey'] ?></th>
                <?php endif; ?>
                <th class="data"><?= $this->lang['strdefault'] ?></th>
                <?php if ($options['showPartitionKey'] ?? false): ?>
                    <th class="data text-center"><?= $this->lang['strpartitionkey'] ?></th>
                <?php endif; ?>
                <th class="data"><?= $this->lang['strcomment'] ?></th>
            </tr>
            <?php
            $this->renderRows($columns, $postData, $options);
            ?>
        </table>
        <?php
    }

    /**
     * Render table rows for column form
     * @param array $columns Array of column data. Each element should be an associative array with keys:
     *                       'attname', 'base_type', 'length', 'attnotnull', 'adsrc', 'comment', 'default_preset'
     *                       Optional keys: 'uniquekey', 'primarykey', 'partitionkey'
     *                       For new columns, use empty strings/nulls
     * @param array $postData Optional POST data to repopulate form (used after validation errors)
     * @param array $options Optional rendering options (showUniqueKey, showPrimaryKey, showPartitionKey)
     */
    public function renderRows($columns, $postData = null, $options = [])
    {
        // Default options
        $allTypes = $this->getAllTypes();
        $columnDefaults = $this->getColumnDefaults();
        $numColumns = count($columns);

        // Prepare predefined size types for potential JS use (kept for parity)
        //$predefined_size_types = array_intersect($this->pg->predefinedSizeTypes, $allTypes);

        for ($i = 0; $i < $numColumns; $i++) {
            $col = $columns[$i];

            // Initialize form values from POST data or column data
            if ($postData !== null && isset($postData['field'][$i])) {
                $field = $postData['field'][$i];
                $type = $postData['type'][$i] ?? '';
                $array = $postData['array'][$i] ?? '';
                $length = $postData['length'][$i] ?? '';
                $notnull = isset($postData['notnull'][$i]);
                $default_preset = $postData['default_preset'][$i] ?? '';
                $default = $postData['default'][$i] ?? '';
                $comment = $postData['comment'][$i] ?? '';
                $uniquekey = isset($postData['uniquekey'][$i]);
                $primarykey = isset($postData['primarykey'][$i]);
                $partitionkey = isset($postData['partitionkey'][$i]);
                $isGenerated = isset($postData['is_generated'][$i]);
                $generatedExpr = $postData['generated_expr'][$i] ?? '';
            } else {
                $field = $col['attname'] ?? '';
                $type = $col['base_type'] ?? '';

                // Check if it is an array type
                $array = strstr($type, '[]') ?: '';
                if (!empty($array)) {
                    $type = substr($type, 0, -2);
                }

                $length = $col['length'] ?? '';
                $notnull = isset($col['attnotnull']) && $col['attnotnull'];
                $default = $col['adsrc'] ?? '';
                $comment = $col['comment'] ?? '';
                $uniquekey = isset($col['uniquekey']) && $col['uniquekey'];
                $primarykey = isset($col['primarykey']) && $col['primarykey'];
                $partitionkey = isset($col['partitionkey']) && $col['partitionkey'];
                $isGenerated = isset($col['attgenerated']) && $col['attgenerated'] === 's';
                $generatedExpr = $isGenerated ? ($col['adsrc'] ?? '') : ($col['generated_expr'] ?? '');

                // Determine default preset
                if (isset($col['default_preset'])) {
                    $default_preset = $col['default_preset'];
                } else {
                    $existingDefault = trim((string) $default);
                    if ($existingDefault === '') {
                        $default_preset = '';
                    } elseif (array_key_exists($existingDefault, $columnDefaults) && $existingDefault !== '' && $existingDefault !== 'custom') {
                        $default_preset = $existingDefault;
                    } else {
                        $default_preset = 'custom';
                    }
                }
            }

            $showCustom = ($default_preset == 'custom' || ($default_preset == '' && $default != ''));

            ?>
            <tr class="data<?= (($i & 1) == 0 ? '1' : '2') ?>" data-row-index="<?= $i ?>">
                <td>
                    <input name="field[<?= $i ?>]" size="16" maxlength="<?= (int) $this->pg->_maxNameLen ?>"
                        value="<?= html_esc($field) ?>" />
                </td>

                <td>
                    <select name="type[<?= $i ?>]" id="types<?= $i ?>" onchange="checkLengths(this.value, <?= $i ?>);">
                        <?php foreach ($allTypes as $t): ?>
                            <option value="<?= html_esc($t) ?>" <?= ($t == $type) ? ' selected="selected"' : '' ?>>
                                <?= $this->misc->formatVal($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>

                <td>
                    <select name="array[<?= $i ?>]">
                        <option value="" <?= ($array == '') ? ' selected="selected"' : '' ?>></option>
                        <option value="[]" <?= ($array == '[]') ? ' selected="selected"' : '' ?>>[ ]</option>
                    </select>
                </td>

                <td>
                    <input name="length[<?= $i ?>]" id="lengths<?= $i ?>" size="8" value="<?= html_esc($length) ?>" />
                </td>

                <td class="text-center">
                    <input type="checkbox" name="notnull[<?= $i ?>]" id="notnull<?= $i ?>" <?php if ($notnull)
                            echo ' checked="checked"'; ?> />
                </td>

                <?php if ($this->pg->hasGeneratedColumns() && !($options['isAlter'] ?? false)): ?>
                    <td class="text-center">
                        <input type="checkbox" name="is_generated[<?= $i ?>]" id="is_generated<?= $i ?>" <?php if ($isGenerated)
                                echo ' checked="checked"'; ?> onchange="handleGeneratedChange(<?= $i ?>);" />
                    </td>
                <?php endif; ?>

                <?php if ($options['showUniqueKey'] ?? false): ?>
                    <td class="text-center">
                        <input type="checkbox" name="uniquekey[<?= $i ?>]" <?php if ($uniquekey)
                              echo ' checked="checked"'; ?> />
                    </td>
                <?php endif; ?>

                <?php if ($options['showPrimaryKey'] ?? false): ?>
                    <td class="text-center">
                        <input type="checkbox" name="primarykey[<?= $i ?>]" <?php if ($primarykey)
                              echo ' checked="checked"'; ?> />
                    </td>
                <?php endif; ?>

                <td>
                    <div id="default_container<?= $i ?>" style="display: <?= $isGenerated ? 'none' : 'block' ?>;">
                        <select name="default_preset[<?= $i ?>]" id="default_preset<?= $i ?>"
                            onchange="handleDefaultPresetChange(<?= $i ?>);" style="margin-bottom: 2px;">
                            <?php foreach ($columnDefaults as $value => $label): ?>
                                <option value="<?= html_esc($value) ?>" <?= ($default_preset == $value) ? ' selected="selected"' : '' ?>>
                                    <?= html_esc($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input name="default[<?= $i ?>]" id="default<?= $i ?>" size="7"
                            value="<?= html_esc($isGenerated ? '' : $default) ?>"
                            style="display: <?= $showCustom ? 'inline' : 'none' ?>;" />
                    </div>

                    <?php if ($this->pg->hasGeneratedColumns()): ?>
                        <div id="generated_container<?= $i ?>" style="display: <?= $isGenerated ? 'block' : 'none' ?>;">
                            <input type="text" name="generated_expr[<?= $i ?>]" id="generated_expr<?= $i ?>" size="30"
                                value="<?= html_esc($generatedExpr) ?>"
                                placeholder="<?= html_esc($this->lang['strgeneratedexpression']) ?>" />
                        </div>
                    <?php endif; ?>
                </td>

                <?php if ($options['showPartitionKey'] ?? false): ?>
                    <td class="text-center">
                        <input type="checkbox" name="partitionkey[<?= $i ?>]" <?php if ($partitionkey)
                              echo ' checked="checked"'; ?> />
                    </td>
                <?php endif; ?>

                <td>
                    <input name="comment[<?= $i ?>]" size="40" value="<?= html_esc($comment) ?>" />
                </td>
            </tr>
            <?php
        }
    }

    /**
     * Generate JavaScript initialization code for column form
     * @param int $numColumns Number of columns in the form
     */
    public function renderJavaScriptInit($numColumns)
    {
        $allTypes = $this->getAllTypes();
        $predefined_size_types = array_intersect($this->pg->predefinedSizeTypes, $allTypes);
        ?>
        <script src="js/tables.js" type="text/javascript"></script>
        <script type="text/javascript">
            var predefined_lengths = <?= json_encode(array_values($predefined_size_types)) ?>;
            var maxNameLen = <?= (int) $this->pg->_maxNameLen ?>;
            var allTypes = <?= json_encode(array_values($allTypes)) ?>;
            var hasGeneratedColumns = <?= $this->pg->hasGeneratedColumns() ? 'true' : 'false' ?>;

            // Initialize all rows
            for (var i = 0; i < <?= (int) $numColumns ?>; i++) {
                var typeEl = document.getElementById('types' + i);
                if (typeEl) {
                    checkLengths(typeEl.value, i);
                }
                handleDefaultPresetChange(i, false);
                if (hasGeneratedColumns) {
                    handleGeneratedChange(i, false);
                }
            }
        </script>
        <?php
    }
}
