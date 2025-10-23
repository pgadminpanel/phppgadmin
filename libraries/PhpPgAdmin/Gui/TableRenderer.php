<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;

/**
 * Table rendering: data grid with actions and multi-select
 * Extracts printTable() from legacy Misc class
 */
class TableRenderer extends AppContext
{
    /**
     * Display a data table with optional actions and multi-select support.
     * 
     * @param \ADORecordSet $tabledata A recordset to display in table format.
     * @param $columns An associative array that describes the structure of the table.
     *        Keys are the field names, values are associative arrays with the following keys:
     *        'title' - Title of the column header
     *        'class' - Optional CSS class for td/th
     *        'field' - Name of the field, defaults to key
     *        'type' - Type of the field (see printVal)
     *        'params' - Optional parameters for printVal
     *        'url' - URL to link the field to, optional
     *        'vars' - Optional associate array of variables to pass to the URL
     *        'help' - Optional help identifier for printHelp
     * @param $actions An associative array with action buttons.
     *        Keys are the action names, values are arrays with keys:
     *        'content' - Link text
     *        'icon' - Link icon
     *        'title' - Link title
     *        'url' - Link URL
     *        'urlvars' - Optional URL vars
     *        'multiaction' - If present, action is a multi-action option
     *        'disable' - Set to true to disable action for certain rows (used in pre_fn)
     *        Special 'multiactions' key: array with 'url', 'vars', 'keycols', 'default'
     * @param $place - A string describing the location (used for plugin hooks)
     * @param $nodata - A message to be shown if there are no rows to display
     * @param $pre_fn - A callback function ($tabledata, $actions) that returns alternate actions
     *        or null to use default actions. Useful for disabling actions on certain rows.
     * @param $footerCfg - Optional footer configuration array.
     *        If provided, a <tfoot> row will be rendered after the data rows (only when there are rows).
     *        Footer cells are rendered as <td> and align with the displayed columns.
     *
     *        Format:
     *        - Keyed by column id (same keys as in $columns), values are specs:
     *          - 'agg' => 'sum'|'count'|'avg'|'min'|'max' (optional)
     *          - 'field' => string (optional, defaults to column 'field')
     *          - 'value' => callable (optional, overrides 'field'; fn(array $rowFields, array $column, string $columnId, TableRenderer $renderer): mixed)
     *          - 'text' => string (optional fixed text; no accumulation)
     *          - 'label' => string (optional, prepended to rendered value)
     *          - 'class' => string (optional extra CSS class for the footer cell)
     *          - 'type'/'params' => override display formatting using misc()->formatVal()
     *          - 'format' => callable (optional; fn($finalValue, array $acc, int $rowCount, array $column, string $columnId, TableRenderer $renderer): string)
     *          - 'escape' => bool (optional; only applies when using 'format' or 'text'; default true)
     * @return bool true if rows were displayed, false otherwise
     */
    public function printTable($tabledata, $columns, $actions, $place, $nodata = null, $pre_fn = null, $footerCfg = null): bool
    {
        $conf = $this->conf();
        $lang = $this->lang();
        $pluginManager = $this->pluginManager();

        // Action buttons hook's place
        $plugin_functions_parameters = [
            'actionbuttons' => &$actions,
            'place' => $place
        ];
        if ($pluginManager) {
            $pluginManager->do_hook('actionbuttons', $plugin_functions_parameters);
        }

        if ($has_ma = isset($actions['multiactions']))
            $ma = $actions['multiactions'];
        unset($actions['multiactions']);

        if (!is_object($tabledata) || $tabledata->EOF) {
            if (!empty($nodata)) {
                echo "<p class=\"nodata\">{$nodata}</p>\n";
            }
            return false;
        }

        // Remove the 'comment' column if they have been disabled
        if (!$conf['show_comments']) {
            unset($columns['comment']);
        }

        if (isset($columns['comment'])) {
            // Uncomment this for clipped comments.
            // TODO: This should be a user option.
            //$columns['comment']['params']['clip'] = true;
        }

        if ($has_ma) {
            echo "<form id=\"multi_form\" action=\"{$ma['url']}\" method=\"post\" enctype=\"multipart/form-data\">\n";
            if (isset($ma['vars']))
                foreach ($ma['vars'] as $k => $v)
                    echo "<input type=\"hidden\" name=\"$k\" value=\"$v\" />";
        }

        echo "<table class=\"data\">\n";
        echo "<thead class=\"sticky-thead\">\n";
        echo "<tr>\n";

        // Display column headings
        if ($has_ma)
            echo "<th class=\"empty\">" . $this->lang()['stroptions'] . "</th>";
        foreach ($columns as $column_id => $column) {

            $class = $column['class'] ?? '';

            echo "<th class=\"data {$class}\">";
            if (isset($column['help']))
                $this->misc()->printHelp($column['title'], $column['help']);
            else
                echo $column['title'];
            echo "</th>\n";
        }
        echo "</tr>\n";
        echo "</thead>\n";
        echo "<tbody>\n";

        $footerAcc = [];

        // Display table rows
        $i = 0;
        while (!$tabledata->EOF) {
            $id = ($i & 1) + 1;

            unset($alt_actions);
            if (!is_null($pre_fn))
                $alt_actions = $pre_fn($tabledata, $actions);
            if (!isset($alt_actions))
                $alt_actions = $actions;

            echo "<tr class=\"data{$id}\">\n";
            if ($has_ma) {
                foreach ($ma['keycols'] as $k => $v)
                    $a[$k] = $tabledata->fields[$v];
                echo "<td>";
                echo "<input type=\"checkbox\" name=\"ma[]\" value=\"" . htmlentities(serialize($a), ENT_COMPAT, 'UTF-8') . "\" />";
                echo "</td>\n";
            }

            foreach ($columns as $column_id => $column) {

                $spec = $footerCfg[$column_id] ?? null;
                $agg = $spec['agg'] ?? null;
                if (isset($agg)) {
                    if (!isset($footerAcc[$column_id])) {
                        $footerAcc[$column_id] = [
                            'sum' => 0.0,
                            'count' => 0,
                            'min' => null,
                            'max' => null
                        ];
                    }
                    if (isset($spec['value']) && is_callable($spec['value'])) {
                        $raw = $spec['value']($tabledata->fields, $column, $column_id, $this);
                    } else {
                        $field = $spec['field'] ?? ($column['field'] ?? $column_id);
                        $raw = value($field, $tabledata->fields);
                    }

                    switch ($agg) {
                        case 'count':
                            $footerAcc[$column_id]['count']++;
                            break;
                        case 'sum':
                        case 'avg':
                        case 'min':
                        case 'max':
                            if ($raw !== null && $raw !== '') {
                                if (is_numeric($raw)) {
                                    $num = (float) $raw;
                                } elseif (is_string($raw)) {
                                    $raw2 = str_replace(' ', '', $raw);
                                    $num = is_numeric($raw2) ? (float) $raw2 : null;
                                } else {
                                    $num = null;
                                }

                                if ($num !== null) {
                                    $footerAcc[$column_id]['sum'] += $num;
                                    $footerAcc[$column_id]['count']++;
                                    if ($footerAcc[$column_id]['min'] === null || $num < $footerAcc[$column_id]['min']) {
                                        $footerAcc[$column_id]['min'] = $num;
                                    }
                                    if ($footerAcc[$column_id]['max'] === null || $num > $footerAcc[$column_id]['max']) {
                                        $footerAcc[$column_id]['max'] = $num;
                                    }
                                }
                            }
                            break;
                    }
                }

                $class = $column['class'] ?? '';
                $classAttr = empty($class) ? '' : " class='$class'";

                // Apply default values for missing parameters
                if (isset($column['url']) && !isset($column['vars']))
                    $column['vars'] = [];

                switch ($column_id) {
                    case 'actions':
                        echo "<td class=\"action-buttons {$class}\">";
                        foreach ($alt_actions as $action) {
                            if (value($action['disable'] ?? false, $tabledata->fields)) {
                                continue;
                            }
                            echo "<span class=\"opbutton{$id} op-button\">";
                            $action['fields'] = $tabledata->fields;
                            $this->misc()->printLink($action);
                            echo "</span>\n";
                        }
                        echo "</td>\n";
                        break;
                    case 'comment':
                        echo "<td class='comment_cell'>";
                        $val = value($column['field'], $tabledata->fields);
                        if ($val !== null) {
                            echo htmlentities($val);
                        }
                        echo "</td>";
                        break;
                    default:
                        echo "<td$classAttr>";
                        $val = value($column['field'], $tabledata->fields);
                        $val = $this->misc()->formatVal(
                            $val,
                            $column['type'] ?? 'text',
                            $column['params'] ?? []
                        );
                        if ($val !== null && $val !== '') {
                            if (isset($column['url'])) {
                                echo "<a href=\"{$column['url']}";
                                $this->misc()->printUrlVars($column['vars'], $tabledata->fields);
                                echo "\">";
                            }
                            // Render icon if specified in column config
                            if (isset($column['icon'])) {
                                $icon = value($column['icon'], $tabledata->fields);
                                $icon = $this->misc()->icon($icon) ?: $icon;
                                echo '<img src="' . htmlspecialchars($icon) . '" class="icon" alt="" />';
                            }
                            echo $val;
                            if (isset($column['url'])) {
                                echo "</a>";
                            }
                        }

                        echo "</td>\n";
                        break;
                }
            }
            echo "</tr>\n";

            $tabledata->moveNext();
            $i++;
        }
        echo "</tbody>\n";

        if ($footerCfg !== null) {
            echo "<tfoot>\n";
            echo "<tr class=\"table-footer\">\n";

            if ($has_ma) {
                echo "<td class=\"empty table-footer-cell\"></td>\n";
            }

            foreach ($columns as $column_id => $column) {
                $spec = $footerCfg[$column_id] ?? null;
                if ($spec === null) {
                    continue;
                }

                //$columnClass = $column['class'] ?? '';
                $extraClass = $spec['class'] ?? '';
                $cellClass = trim('table-footer-cell ' . $extraClass);

                $labelHtml = '';
                $valueHtml = '';
                $colspan = $spec['colspan'] ?? 1;

                if ($spec !== null) {
                    if (isset($spec['label'])) {
                        $labelHtml = htmlspecialchars((string) $spec['label']);
                    }

                    if (isset($spec['text'])) {
                        $escape = $spec['escape'] ?? true;
                        $text = (string) $spec['text'];
                        $valueHtml = $escape ? htmlspecialchars($text) : $text;
                    } else {
                        $agg = $spec['agg'] ?? null;
                        $acc = $footerAcc[$column_id] ?? null;
                        $final = null;
                        if ($agg !== null && $acc !== null) {
                            switch ($agg) {
                                case 'sum':
                                    $final = $acc['sum'];
                                    break;
                                case 'count':
                                    $final = $acc['count'];
                                    break;
                                case 'avg':
                                    $final = ($acc['count'] > 0) ? ($acc['sum'] / $acc['count']) : null;
                                    break;
                                case 'min':
                                    $final = $acc['min'];
                                    break;
                                case 'max':
                                    $final = $acc['max'];
                                    break;
                            }
                        }

                        if (isset($spec['format']) && is_callable($spec['format'])) {
                            $escape = $spec['escape'] ?? true;
                            $formatted = (string) $spec['format'](
                                $final,
                                (array) ($acc ?? []),
                                (int) $i,
                                (array) $column,
                                (string) $column_id,
                                $this
                            );
                            $valueHtml = $escape ? htmlspecialchars($formatted) : $formatted;
                        } elseif ($final !== null) {
                            $displayType = $spec['type'] ?? ($column['type'] ?? 'text');
                            $displayParams = $spec['params'] ?? ($column['params'] ?? []);
                            $valueHtml = $this->misc()->formatVal(
                                $final,
                                $displayType,
                                $displayParams
                            );
                        }
                    }
                }

                echo "<td" . (empty($cellClass) ? '' : " class=\"{$cellClass}\"") . " colspan=\"{$colspan}\">";
                if ($labelHtml !== '') {
                    echo "<span class=\"label\">" . $labelHtml . "</span>";
                    if ($valueHtml !== '') {
                        echo ' ';
                    }
                }
                echo $valueHtml;
                echo "</td>\n";
            }

            echo "</tr>\n";
            echo "</tfoot>\n";
        }
        echo "</table>\n";

        // Multi action table footer w/ options & [un]check'em all
        if ($has_ma) {
            // if default is not set or doesn't exist, set it to null
            if (!isset($ma['default']) || !isset($actions[$ma['default']])) {
                $ma['default'] = null;
            }
            ?>
            <br />
            <table>
                <tr>
                    <th class="data" style="text-align: left" colspan="4">
                        <?= $lang['stractionsonmultiplelines'] ?>
                    </th>
                </tr>
                <tr class="row1">
                    <td>
                        <input type="checkbox" onchange="toggleAllMf(this.checked);" />
                        <a href="#" onclick="this.previousElementSibling.click(); return false;">
                            <?= $lang['strselectall'] ?>
                        </a>
                    </td>
                    <td>&nbsp;<span class="psm">⮞</span>&nbsp;</td>
                    <td>
                        <select name="action">
                            <?php if ($ma['default'] == null): ?>
                                <option value="">--</option>
                            <?php endif; ?>

                            <?php foreach ($actions as $k => $a): ?>
                                <?php if (isset($a['multiaction'])): ?>
                                    <option value="<?= $a['multiaction'] ?>" <?= ($ma['default'] == $k ? ' selected="selected"' : '') ?>>
                                        <?= $a['content'] ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="submit" value="<?= $lang['strexecute'] ?>" />
                        <?= $this->misc()->form ?>
                    </td>
                </tr>
            </table>
            </form>
            <?php
        }

        return true;
    }
}
