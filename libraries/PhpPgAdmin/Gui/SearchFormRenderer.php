<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\TypeActions;

/**
 * Unified renderer for search/select forms for tables and views
 * 
 * Handles the form UI for selecting rows from tables or views with:
 * - Column selection checkboxes
 * - Operator selection (=, !=, <, >, etc.)
 * - Value input fields with FK autocomplete support
 * - Type-aware field rendering
 * - JavaScript "select all" functionality
 */
class SearchFormRenderer
{
    /**
     * Render the search/select rows form
     * 
     * @param bool $confirm Whether to show the form (true) or process submission (false)
     * @param string $msg Optional message to display
     * @param string $subject The subject type: 'table' or 'view'
     * @param string $objectName The name of the table or view
     * @param string $returnPage The page to return to after selection (e.g., 'selectrows', 'schema')
     */
    public static function renderSelectRowsForm($confirm, $msg = '', $subject = 'table', $objectName = '', $returnPage = 'selectrows')
    {
        $pg = AppContainer::getPostgres();
        $misc = AppContainer::getMisc();
        $lang = AppContainer::getLang();
        $tableActions = new TableActions($pg);
        $formRenderer = new FormRenderer();

        if (!$confirm) {
            // Process form submission
            if (!isset($_REQUEST['show']))
                $_REQUEST['show'] = [];
            if (!isset($_REQUEST['values']))
                $_REQUEST['values'] = [];
            if (!isset($_REQUEST['nulls']))
                $_REQUEST['nulls'] = [];

            // Verify that they haven't supplied a value for unary operators
            foreach ($_REQUEST['ops'] as $k => $v) {
                if ($pg->selectOps[$v] == 'p' && $_REQUEST['values'][$k] != '') {
                    self::renderSelectRowsForm(true, $lang['strselectunary'], $subject, $objectName, $returnPage);
                    return;
                }
            }

            // Generate query SQL
            $query = $pg->getSelectSQL(
                $objectName,
                array_keys($_REQUEST['show']),
                $_REQUEST['values'],
                $_REQUEST['ops']
            );
            $_REQUEST['query'] = $query;
            $_REQUEST['return'] = $returnPage;

            AppContainer::setSkipHtmlFrame(true);
            include ROOT_PATH . '/display.php';
            exit;
        }

        // Show form
        $misc->printTrail($subject);
        $misc->printTabs($subject, 'select');
        $misc->printMsg($msg);

        $attrs = $tableActions->getTableAttributes($objectName);
        if (!is_object($attrs) || $attrs->recordCount() == 0) {
            $misc->printMsg($lang['strinvalidparam']);
            return;
        }

        // Get type metadata for enhanced field rendering
        $typeNames = [];
        $typeActions = new TypeActions($pg);
        while (!$attrs->EOF) {
            $typeNames[] = $attrs->fields['type'];
            $attrs->moveNext();
        }
        $attrs->moveFirst();
        $typeMetas = $typeActions->getTypeMetasByNames($typeNames);

        // Get FK properties for search form if autocomplete is enabled
        $conf = AppContainer::getConf();
        $fksprops = false;
        if ($conf['autocomplete'] != 'disable') {
            $fksprops = $misc->getAutocompleteFKProperties($objectName, 'search');
            if ($fksprops) {
                echo $fksprops['code'];
            }
        }

        // Start form
        $formAction = $subject === 'view' ? 'views.php' : 'tables.php';
        ?>
        <form action="<?= $formAction ?>" method="get" id="selectform">
            <script>
                function selectAll() {
                    for (var i = 0; i < document.getElementById('selectform').elements.length; i++) {
                        var e = document.getElementById('selectform').elements[i];
                        if (e.name.indexOf('show') == 0) e.checked = document.getElementById('selectform').selectall.checked;
                    }
                }
            </script>

            <table>
                <tr>
                    <th class="data"><?= $lang['strshow'] ?></th>
                    <th class="data"><?= $lang['strcolumn'] ?></th>
                    <th class="data"><?= $lang['strtype'] ?></th>
                    <th class="data"><?= $lang['stroperator'] ?></th>
                    <th class="data"><?= $lang['strvalue'] ?></th>
                </tr>

                <?php
                $i = 0;
                while (!$attrs->EOF) {
                    $attrs->fields['attnotnull'] = $pg->phpBool($attrs->fields['attnotnull']);

                    if (!isset($_REQUEST['values'][$attrs->fields['attname']])) {
                        $_REQUEST['values'][$attrs->fields['attname']] = null;
                    }
                    if (!isset($_REQUEST['ops'][$attrs->fields['attname']])) {
                        $_REQUEST['ops'][$attrs->fields['attname']] = null;
                    }

                    $id = (($i & 1) == 0 ? '1' : '2');
                    ?>
                    <tr class="data<?= $id ?> data-row">
                        <td style="white-space:nowrap;">
                            <input type="checkbox" name="show[<?= html_esc($attrs->fields['attname']) ?>]"
                                <?= isset($_REQUEST['show'][$attrs->fields['attname']]) ? ' checked="checked"' : '' ?> />
                        </td>
                        <td style="white-space:nowrap;"><?= $misc->formatVal($attrs->fields['attname']) ?></td>
                        <td style="white-space:nowrap;">
                            <?= $misc->formatVal($pg->formatType($attrs->fields['type'], $attrs->fields['atttypmod'])) ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <select name="ops[<?= html_esc($attrs->fields['attname']) ?>]">
                                <?php foreach (array_keys($pg->selectOps) as $v): ?>
                                    <option value="<?= html_esc($v) ?>" <?= ($v == $_REQUEST['ops'][$attrs->fields['attname']]) ? ' selected="selected"' : '' ?>><?= html_esc($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td style="white-space:nowrap;" <?php if (isset($attrs->fields['attnum'])): ?>
                                id="row_att_search_<?= $attrs->fields['attnum'] ?>" <?php endif; ?>>
                            <?php
                            $extras = [];
                            if (($fksprops !== false) && isset($attrs->fields['attnum']) && isset($fksprops['byfield'][$attrs->fields['attnum']])) {
                                $extras['id'] = "attr_{$attrs->fields['attnum']}";
                                $extras['autocomplete'] = 'off';
                                $extras['data-fk-context'] = 'search';
                                $extras['data-attnum'] = $attrs->fields['attnum'];
                            }

                            $fieldOptions = [];
                            if (isset($typeMetas[$attrs->fields['type']])) {
                                $fieldOptions['is_large_type'] = $typeActions->isLargeTypeMeta($typeMetas[$attrs->fields['type']]);
                            }

                            $formRenderer->printField(
                                "values[{$attrs->fields['attname']}]",
                                $_REQUEST['values'][$attrs->fields['attname']],
                                $attrs->fields['type'],
                                $extras,
                                $fieldOptions
                            );
                            ?>
                        </td>
                    </tr>
                    <?php
                    $i++;
                    $attrs->moveNext();
                }
                ?>
                <tr>
                    <td colspan="5">
                        <input type="checkbox" id="selectall" name="selectall" accesskey="a" onclick="javascript:selectAll()" />
                        <label for="selectall"><?= $lang['strselectallfields'] ?></label>
                    </td>
                </tr>
            </table>

            <p>
                <input type="hidden" name="action" value="selectrows" />
                <input type="hidden" name="<?= $subject ?>" value="<?= html_esc($objectName) ?>" />
                <input type="hidden" name="subject" value="<?= $subject ?>" />
                <?= $misc->form ?>
                <input type="submit" name="select" accesskey="r" value="<?= $lang['strselect'] ?>" />
                <input type="submit" name="cancel" value="<?= $lang['strcancel'] ?>" />
            </p>
        </form>
        <?php
    }
}
