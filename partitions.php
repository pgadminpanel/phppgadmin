<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Database\Actions\TableActions;

/**
 * Manage partitions of a partitioned table
 *
 * Part of phpPgAdmin partition support (PostgreSQL 10+)
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Show form to create a new partition or attach an existing table
 * @param string $msg Message to display
 * @param bool $is_attach True for attach mode, false for create mode
 */
function doCreateOrAttach($msg = '', $is_attach = false)
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $misc->printTrail('table');

    if ($is_attach) {
        $misc->printTitle($lang['strattachpartition'], 'pg.partition.attach');
        echo "<p>{$lang['strattachpartitiondesc']}</p>\n";
    } else {
        $misc->printTitle($lang['strcreatepartition'], 'pg.partition.create');
    }

    $misc->printMsg($msg);

    // Get partition info
    $partInfo = $partitionActions->getPartitionInfo($_REQUEST['table']);

    if (!is_object($partInfo) || $partInfo->recordCount() == 0) {
        echo "<p>{$lang['strinvalidparam']}</p>\n";
        return;
    }

    $strategy = $partInfo->fields['partstrat'];
    $partition_keys = $partInfo->fields['partition_keys'];

    // Convert PostgreSQL array format to PHP array
    $partition_keys = trim($partition_keys, '{}');
    $key_columns = explode(',', $partition_keys);

    // Map strategy code to name
    $strategy_name = PartitionActions::PARTITION_STRATEGY_MAP[$strategy] ?? $strategy;

    // Get list of tables for attach mode
    if ($is_attach) {
        $tableActions = new TableActions($pg);
        $tables = $tableActions->getTables();
    }

    ?>
    <form action="partitions.php" method="post">
        <table>
            <tr>
                <th class="data left"><?= $lang['strpartitionedby']; ?></th>
                <td class="data">
                    <strong><?= htmlspecialchars($strategy_name); ?></strong>
                    (<?= htmlspecialchars(implode(', ', $key_columns)); ?>)
                </td>
            </tr>
            <?php if ($is_attach): ?>
                <tr>
                    <th class="data left required"><?= $lang['strtable']; ?></th>
                    <td class="data">
                        <select name="attach_table">
                            <option value=""><?= $lang['strselecttable']; ?></option>
                            <?php
                            if (is_object($tables)) {
                                while (!$tables->EOF) {
                                    // Only show regular tables, not partitions
                                    if (!$partitionActions->isPartition($tables->fields['relname'])) {
                                        echo '<option value="', htmlspecialchars($tables->fields['relname']), '">',
                                            htmlspecialchars($tables->fields['relname']), '</option>';
                                    }
                                    $tables->moveNext();
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            <?php else: ?>
                <tr>
                    <th class="data left required"><?= $lang['strname']; ?></th>
                    <td class="data"><input name="partition_name" size="32" maxlength="<?= $pg->_maxNameLen; ?>" /></td>
                </tr>
            <?php endif; ?>
            <?php if ($strategy == 'r'): /* RANGE */ ?>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionfrom']; ?></th>
                    <td class="data"><input name="from_value" size="32" placeholder="2024-01-01" /></td>
                </tr>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionto']; ?></th>
                    <td class="data">
                        <input name="to_value" size="32" placeholder="2024-02-01" /><br>
                        <small class="form-text">
                            <?= $lang['strexample']; ?>: FROM ('2024-01-01') TO ('2024-02-01')
                        </small>
                    </td>
                </tr>
            <?php elseif ($strategy == 'l'): /* LIST */ ?>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionvalues']; ?></th>
                    <td class="data">
                        <textarea name="list_values" rows="3" cols="32" placeholder="USA, Canada, Mexico"></textarea><br>
                        <small class="form-text"><?= $lang['strexample']; ?>: IN (USA, Canada, Mexico)
                        </small>
                    </td>
                </tr>
            <?php elseif ($strategy == 'h'): /* HASH */ ?>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionmodulus']; ?></th>
                    <td class="data"><input name="modulus" type="number" min="1" size="10" /></td>
                </tr>
                <tr>
                    <th class="data left required"><?= $lang['strpartitionremainder']; ?></th>
                    <td class="data">
                        <input name="remainder" type="number" min="0" size="10" /><br>
                        <small class="form-text"><?= $lang['strexample']; ?>: MODULUS 4, REMAINDER 0
                        </small>
                    </td>
                </tr>
            <?php endif ?>
            <?php

            // Default partition option (PG11+)
            if ($pg->major_version >= 11) {
                ?>
                <tr>
                    <th class="data left"><?= $lang['strdefault'] ?></th>
                    <td class="data"><label><input type="checkbox" name="is_default" id="is_default" />
                            <?= $lang['strdefaultpartition']; ?></label></td>
                </tr>
                <?php
            }
            ?>
        </table>
        <input type="hidden" name="action" value="<?= $is_attach ? 'save_attach' : 'save_create'; ?>" />
        <input type="hidden" name="strategy" value="<?= htmlspecialchars($strategy); ?>" />
        <?= $misc->form; ?>
        <input type="hidden" name="table" value="<?= html_esc($_REQUEST['table']); ?>" />
        <p>
            <input type="submit" name="save" value="<?= $is_attach ? $lang['strattach'] : $lang['strcreate']; ?>" />
            <input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
        </p>
    </form>
    <?php
}

/**
 * Show form to create a new partition
 */
function doCreate($msg = '')
{
    doCreateOrAttach($msg, false);
}

/**
 * Show form to attach an existing table as a partition
 */
function doAttach($msg = '')
{
    doCreateOrAttach($msg, true);
}

function doSaveCreate()
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    // Prepare partition values based on strategy
    $values = [];
    $isDefault = isset($_POST['is_default']) && $_POST['is_default'];

    if (!$isDefault) {
        switch ($_POST['strategy']) {
            case 'r': // RANGE
                $values = [
                    'from' => $_POST['from_value'],
                    'to' => $_POST['to_value']
                ];
                break;

            case 'l': // LIST
                $values = [
                    'values' => $_POST['list_values']
                ];
                break;

            case 'h': // HASH
                $values = [
                    'modulus' => $_POST['modulus'],
                    'remainder' => $_POST['remainder']
                ];
                break;
        }
    }

    // Create the partition
    $status = $partitionActions->createPartition(
        $_REQUEST['table'],
        $_POST['partition_name'],
        $_POST['strategy'],
        $values,
        $isDefault
    );

    if ($status == 0) {
        AppContainer::setShouldReloadTree(true);
        doDefault($lang['strpartitioncreated']);
    } else {
        doCreate($lang['strpartitioncreatedbad']);
    }
}

function doSaveAttach()
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    if (empty($_POST['attach_table'])) {
        doAttach($lang['strselecttable']);
        return;
    }

    // Prepare partition values based on strategy
    $values = [];
    $isDefault = isset($_POST['is_default']) && $_POST['is_default'];

    if (!$isDefault) {
        switch ($_POST['strategy']) {
            case 'r': // RANGE
                $values = [
                    'from' => $_POST['from_value'],
                    'to' => $_POST['to_value']
                ];
                break;

            case 'l': // LIST
                $values = [
                    'values' => $_POST['list_values']
                ];
                break;

            case 'h': // HASH
                $values = [
                    'modulus' => $_POST['modulus'],
                    'remainder' => $_POST['remainder']
                ];
                break;
        }
    }

    // Attach the partition
    $status = $partitionActions->attachPartition(
        $_REQUEST['table'],
        $_POST['attach_table'],
        $_POST['strategy'],
        $values,
        $isDefault
    );

    if ($status == 0) {
        AppContainer::setShouldReloadTree(true);
        doDefault($lang['strpartitionattached']);
    } else {
        doAttach($lang['strpartitionattachedbad']);
    }
}

/**
 * Show confirmation and detach a partition
 */
function doDetach($confirm)
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();

    if (!$confirm) {
        $f_schema = $pg->_schema;
        $pg->fieldClean($f_schema);
        $pg->fieldClean($_REQUEST['table']);
        $pg->fieldClean($_POST['partition']);

        $sql = "ALTER TABLE \"{$f_schema}\".\"{$_REQUEST['table']}\" DETACH PARTITION \"{$f_schema}\".\"{$_POST['partition']}\"";

        if (isset($_POST['finalize']) && $_POST['finalize']) {
            $sql .= " FINALIZE";
        } elseif (isset($_POST['cascade']) && $_POST['cascade']) {
            $sql .= " CASCADE";
        }

        $status = $pg->execute($sql);

        if ($status == 0) {
            AppContainer::setShouldReloadTree(true);
            doDefault($lang['strpartitiondetached']);
        } else {
            doDefault($lang['strpartitiondetachedbad']);
        }
    }

    $misc->printTrail('table');
    $misc->printTitle($lang['strdetachpartition'], 'pg.partition.detach');

    ?>
    <p><?= sprintf($lang['strconfdetachpartition'], $_REQUEST['partition']); ?></p>

    <form action="partitions.php" method="post">
        <p><input type="checkbox" id="cascade" name="cascade" /> <label for="cascade"><?= $lang['strcascade']; ?></label>
        </p>

        <?php
        // DETACH ... FINALIZE option for PG14+
        if ($pg->major_version >= 14) {
            ?>
            <p>
                <input type="checkbox" id="finalize" name="finalize" /> <label
                    for="finalize"><?= $lang['strpartitionfinalize']; ?></label>
                <a href="#" onclick="return false;" title="<?= $lang['strpartitiondetachhelp']; ?>">ⓘ</a>
            </p>
            <?php
        }
        ?>

        <input type="hidden" name="action" value="detach" />
        <?= $misc->form; ?>
        <input type="hidden" name="table" value="<?= htmlspecialchars($_REQUEST['table']); ?>" />
        <input type="hidden" name="partition" value="<?= htmlspecialchars($_REQUEST['partition']); ?>" />
        <input type="submit" name="detach" value="<?= $lang['strdetachpartition']; ?>" />
        <input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
    </form>
    <?php
}

/**
 * Analyze all partitions
 */
function doAnalyzeAll()
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $status = $partitionActions->analyzeAllPartitions($_REQUEST['table']);

    if ($status == 0) {
        doDefault($lang['strpartitionsanalyzed']);
    } else {
        doDefault($lang['strpartitionsanalyzebad']);
    }
}

/**
 * Test query pruning - shows which partitions would be accessed by a query
 */
function doTestPruning($msg = '')
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $misc->printTrail('table');
    $misc->printTitle($lang['strtestquerypruning'], 'pg.partition');
    $misc->printMsg($msg);

    $partInfo = $partitionActions->getPartitionInfo($_REQUEST['table']);

    if (!is_object($partInfo) || $partInfo->recordCount() == 0) {
        echo "<p>{$lang['strinvalidparam']}</p>\n";
        return;
    }

    $strategy = $partInfo->fields['partstrat'];
    $partition_keys = $partInfo->fields['partition_keys'];

    // Convert PostgreSQL array format to PHP array
    $partition_keys = trim($partition_keys, '{}');
    $key_columns = explode(',', $partition_keys);

    // Map strategy code to name
    $strategy_name = PartitionActions::PARTITION_STRATEGY_MAP[$strategy] ?? $strategy;

    // Get partition count
    $partitions = $partitionActions->getPartitions($_REQUEST['table']);
    $partition_count = 0;
    if (is_object($partitions)) {
        while (!$partitions->EOF) {
            $partition_count++;
            $partitions->moveNext();
        }
    }

    if (!isset($_POST['query'])) {
        $_POST['query'] = "SELECT * FROM {$_REQUEST['table']} WHERE ";
    }

    ?>
    <p><?= $lang['strpartitionpruningtest']; ?></p>

    <form action="partitions.php" method="post">
        <table>
            <tr>
                <th class="data left"><?= $lang['strpartitionedby']; ?>
                </th>
                <td class="data">
                    <strong>
                        <?= htmlspecialchars($strategy_name); ?>
                    </strong>
                    (<?= htmlspecialchars(implode(', ', $key_columns)); ?>)
                </td>
            </tr>
            <tr>
                <th class="data left required"><?= $lang['strsqlquery']; ?></th>
                <td class="data" style="min-width: 500px;">
                    <textarea name="query" rows="8" cols="80"
                        class="sql-editor frame resizable medium"><?= htmlspecialchars($_POST['query']); ?></textarea>
                </td>
            </tr>
        </table>

        <input type="hidden" name="action" value="test_pruning_execute" />
        <?= $misc->form; ?>
        <input type="hidden" name="table" value="<?= htmlspecialchars($_REQUEST['table']); ?>" />
        <p>
            <input type="submit" name="execute" value="<?= $lang['strtest']; ?>" />
            <input type="submit" name="cancel" value="<?= $lang['strback']; ?>" />
        </p>
    </form>
    <?php

    /**
     * Parse EXPLAIN JSON output to extract partition names
     * @param array $plan The EXPLAIN plan (decoded JSON)
     * @param array &$partitions Array to collect partition names
     */
    function parseExplainForPartitions($plan, &$partitions)
    {
        if (!is_array($plan)) {
            return;
        }

        // Check for 'Relation Name' field (indicates table access)
        if (isset($plan['Relation Name'])) {
            $relation = $plan['Relation Name'];
            // Only add if it looks like a partition (not the parent table)
            if (!in_array($relation, $partitions)) {
                $partitions[] = $relation;
            }
        }

        // Recursively search in Plans array
        if (isset($plan['Plans']) && is_array($plan['Plans'])) {
            foreach ($plan['Plans'] as $subplan) {
                parseExplainForPartitions($subplan, $partitions);
            }
        }

        // Also check Plan key (some formats)
        if (isset($plan['Plan'])) {
            parseExplainForPartitions($plan['Plan'], $partitions);
        }

        // Handle array of plans at top level
        if (isset($plan[0]) && is_array($plan[0])) {
            foreach ($plan as $item) {
                parseExplainForPartitions($item, $partitions);
            }
        }
    }


    // Show results if query was submitted
    if (!isset($_POST['execute']) || empty($_POST['query'])) {
        return;
    }

    $query = $_POST['query'];

    // Run EXPLAIN to see partition pruning
    $explain_sql = "EXPLAIN (FORMAT JSON) " . $query;
    $result = $pg->selectSet($explain_sql);

    if (is_object($result) && !$result->EOF) {
        $json = $result->fields['QUERY PLAN'];
        $plan = json_decode($json, true);

        // Parse plan to find accessed partitions
        $accessed_partitions = [];
        parseExplainForPartitions($plan, $accessed_partitions);

        $accessed_count = count($accessed_partitions);

        echo "<h3>{$lang['strresults']}</h3>\n";
        echo "<div class=\"partition-pruning-result\">\n";

        // Show pruning effectiveness
        if ($accessed_count == 0) {
            echo "<p class=\"info\">{$lang['strpartitionpruningnone']}</p>\n";
        } else {
            $ratio = $accessed_count / max($partition_count, 1);

            if ($ratio <= 0.2) {
                $badge = 'success';
                $message = sprintf($lang['strpartitionpruningexcellent'], $accessed_count, $partition_count);
            } elseif ($ratio <= 0.5) {
                $badge = 'info';
                $message = sprintf($lang['strpartitionpruninggood'], $accessed_count, $partition_count);
            } elseif ($ratio <= 0.8) {
                $badge = 'warning';
                $message = sprintf($lang['strpartitionpruningpoor'], $accessed_count, $partition_count);
            } else {
                $badge = 'danger';
                $message = sprintf($lang['strpartitionpruningminimal'], $accessed_count, $partition_count);
            }

            echo "<div class=\"my-2 badge bg-{$badge}\">{$message}</div>\n";

            if ($accessed_count > 0) {
                echo "<h4>{$lang['strpartitionsaccessed']}:</h4>\n";
                echo "<ul>\n";
                foreach ($accessed_partitions as $partition) {
                    echo "<li>", $misc->getIcon('Partition'), ' ', htmlspecialchars($partition), "</li>\n";
                }
                echo "</ul>\n";
            }
        }

        // Show full EXPLAIN output
        echo "<details>\n";
        echo "<summary>{$lang['strfullexplain']}</summary>\n";
        echo "<pre class=\"sql-viewer\" data-language=\"json\">", htmlspecialchars(json_encode($plan, JSON_PRETTY_PRINT)), "</pre>\n";
        echo "</details>\n";

        echo "</div>\n";
    } else {
        echo "<p class=\"error\">{$lang['strqueryfailed']}</p>\n";
    }
}

/**
 * Bulk partition creation wizard
 */
function doBulkCreate($msg = '')
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $misc->printTrail('table');
    $misc->printTitle($lang['strbulkcreatepartitions'], 'pg.partition.create');
    $misc->printMsg($msg);

    // Get partition info
    $partInfo = $partitionActions->getPartitionInfo($_REQUEST['table']);

    if (!is_object($partInfo) || $partInfo->recordCount() == 0) {
        echo "<p>{$lang['strinvalidparam']}</p>\n";
        return;
    }

    $strategy = $partInfo->fields['partstrat'];

    // Only support RANGE strategy for now
    if ($strategy !== 'r') {
        echo "<p>{$lang['strpartitiontemplateonlyrange']}</p>\n";
        echo "<p><a href=\"partitions.php?{$misc->href}&amp;table=", urlencode($_REQUEST['table']), "\">{$lang['strback']}</a></p>\n";
        return;
    }

    ?>
    <p><?= $lang['strbulkcreatepartitionsdesc']; ?></p>

    <form action="partitions.php" method="post" onchange="this.save.disabled=true">
        <table>
            <tr>
                <th class="data left required"><?= $lang['strbulkcreatetemplatetype']; ?></th>
                <td class="data">
                    <select name="template_type" id="template_type" onchange="updateTemplateForm()">
                        <option value="monthly"><?= $lang['strbulkcreatetemplatemonthly']; ?></option>
                        <option value="daily"><?= $lang['strbulkcreatetemplatedaily']; ?></option>
                        <option value="yearly"><?= $lang['strbulkcreatetemplateyearly']; ?></option>
                        <option value="custom"><?= $lang['strbulkcreatetemplatecustom']; ?></option>
                    </select>
                </td>
            </tr>
            <tr id="custom_interval_row" style="display: none;">
                <th class="data left required"><?= $lang['strcustominterval']; ?></th>
                <td class="data flex-row">
                    <input type="number" name="custom_value" id="custom_value" min="1" value="1" size="5" class="mr-1" />
                    <select name="custom_unit" id="custom_unit">
                        <option value="days"><?= $lang['strdays']; ?></option>
                        <option value="weeks"><?= $lang['strweeks']; ?></option>
                        <option value="months" selected><?= $lang['strmonths']; ?></option>
                        <option value="years"><?= $lang['stryears']; ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th class="data left required"><?= $lang['strbulkcreatenamepattern']; ?></th>
                <td class="data">
                    <input name="name_pattern" size="40" value="<?= $_REQUEST['table']; ?>_{year}_{month}"
                        placeholder="<?= $_REQUEST['table']; ?>_{year}_{month}" />
                    <br /><small class="form-text"><?= $lang['strbulkcreatenamepatternhint']; ?>
                        <!--({year}, {month}, {day}, {week})--></small>
                </td>
            </tr>
            <tr>
                <th class="data left required"><?= $lang['strstartdate']; ?></th>
                <td class="data"><input name="start_date" type="date" value="<?= date('Y-m-01'); ?>" /></td>
            </tr>
            <tr>
                <th class="data left required"><?= $lang['strenddate']; ?></th>
                <td class="data"><input name="end_date" type="date"
                        value="<?= date('Y-m-t', strtotime('+11 months')); ?>" /></td>
            </tr>
        </table>

        <input type="hidden" name="action" value="save_bulk_create" />
        <?= $misc->form; ?>
        <input type="hidden" name="table" value="<?= htmlspecialchars($_REQUEST['table']); ?>" />
        <p>
            <button type="button" onclick="previewPartitions(this.form)"><?= $lang['strpreview']; ?>
            </button>
            <input type="submit" name="save" value="<?= $lang['strcreateall']; ?>" disabled />
            <input type="submit" name="cancel" value="<?= $lang['strcancel']; ?>" />
        </p>

        <div id="preview_area" class="mt-3" style="display: none;">
        </div>
    </form>

    <script>
        function getISOWeek(date) {
            var target = new Date(date.valueOf());
            var dayNr = (date.getDay() + 6) % 7;
            target.setDate(target.getDate() - dayNr + 3);
            var firstThursday = target.valueOf();
            target.setMonth(0, 1);
            if (target.getDay() !== 4) {
                target.setMonth(0, 1 + ((4 - target.getDay()) + 7) % 7);
            }
            return 1 + Math.ceil((firstThursday - target) / 604800000);
        }

        function updateTemplateForm() {
            var type = document.getElementById('template_type').value;
            var customRow = document.getElementById('custom_interval_row');
            customRow.style.display = (type === 'custom') ? '' : 'none';
        }

        function previewPartitions(form) {
            form.save.disabled = false;
            var type = document.getElementById('template_type').value;
            var pattern = document.querySelector('input[name="name_pattern"]').value;
            var start = new Date(document.querySelector('input[name="start_date"]').value);
            var end = new Date(document.querySelector('input[name="end_date"]').value);
            var preview = document.getElementById('preview_area');
            var html = '<strong><?= $lang['strpartitionswillbecreated'] ?>:</strong> (<?= $lang['strtoexcluded'] ?>)\n';
            html += '<ul class="my-2">';
            var current = new Date(start);
            var count = 0;

            while (current <= end) {
                // Calculate ISO week number
                var weekNum = getISOWeek(current);
                var name = pattern
                    .replace('{year}', current.getFullYear())
                    .replace('{month}', String(current.getMonth() + 1).padStart(2, '0'))
                    .replace('{day}', String(current.getDate()).padStart(2, '0'))
                    .replace('{week}', String(weekNum).padStart(2, '0'));
                var from = current.toISOString().split('T')[0];
                var next = new Date(current);

                if (type === 'monthly') {
                    // Fix month overflow by using UTC with day=1
                    next = new Date(Date.UTC(next.getFullYear(), next.getMonth() + 1, 1));
                } else if (type === 'daily') {
                    next.setDate(next.getDate() + 1);
                } else if (type === 'yearly') {
                    next = new Date(Date.UTC(next.getFullYear() + 1, next.getMonth(), 1));
                } else if (type === 'custom') {
                    var customValue = parseInt(document.getElementById('custom_value').value) || 1;
                    var customUnit = document.getElementById('custom_unit').value;

                    if (customUnit === 'days') {
                        next.setDate(next.getDate() + customValue);
                    } else if (customUnit === 'weeks') {
                        next.setDate(next.getDate() + (customValue * 7));
                    } else if (customUnit === 'months') {
                        // Fix month overflow by using UTC with day=1
                        next = new Date(Date.UTC(next.getFullYear(), next.getMonth() + customValue, 1));
                    } else if (customUnit === 'years') {
                        next = new Date(Date.UTC(next.getFullYear() + customValue, next.getMonth(), 1));
                    }
                }

                var icon = <?= json_encode($misc->getIcon('Partition')); ?>;

                var to = next.toISOString().split('T')[0];
                html += '<li>' + icon + ' ' + name + ': <span class="sql-viewer">VALUES FROM (\'' + from + '\') TO (\'' + to + '\')</span></li>';
                current = next;
                count++;
            }

            html += '</ul><p><strong><?= $lang['strtotal'] ?>:</strong> ' + count + ' <?= $lang['strpartitions'] ?></p>';
            preview.innerHTML = html;
            preview.style.display = 'block';
            createSqlViewers(preview);
        }
    </script>
    <?php
}

function doSaveBulkCreate()
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    // Execute bulk creation
    if (!isset($_POST['template_type']) || !isset($_POST['start_date']) || !isset($_POST['end_date'])) {
        doBulkCreate($lang['strinvalidparam']);
        return;
    }

    $template_type = $_POST['template_type'];
    $name_pattern = $_POST['name_pattern'];
    $start_date = new DateTime($_POST['start_date']);
    $end_date = new DateTime($_POST['end_date']);

    // Get custom interval parameters if custom type
    $custom_value = 1;
    $custom_unit = 'months';
    if ($template_type === 'custom') {
        $custom_value = isset($_POST['custom_value']) ? (int) $_POST['custom_value'] : 1;
        $custom_unit = isset($_POST['custom_unit']) ? $_POST['custom_unit'] : 'months';
        if ($custom_value < 1)
            $custom_value = 1;
    }

    $f_schema = $pg->_schema;
    $pg->fieldClean($f_schema);
    $pg->fieldClean($_REQUEST['table']);

    $created = 0;
    $failed = 0;
    $errors = [];

    $current = clone $start_date;
    while ($current <= $end_date) {
        // Generate partition name
        $name = str_replace(
            ['{year}', '{month}', '{day}', '{week}'],
            [
                $current->format('Y'),
                $current->format('m'),
                $current->format('d'),
                $current->format('W')
            ],
            $name_pattern
        );

        $pg->fieldClean($name);

        // Calculate bounds
        $from = $current->format('Y-m-d');
        $next = clone $current;

        switch ($template_type) {
            case 'daily':
                $next->modify('+1 day');
                break;
            case 'monthly':
                $next->modify('+1 month');
                break;
            case 'yearly':
                $next->modify('+1 year');
                break;
            case 'custom':
                switch ($custom_unit) {
                    case 'days':
                        $next->modify("+{$custom_value} days");
                        break;
                    case 'weeks':
                        $next->modify("+{$custom_value} weeks");
                        break;
                    case 'months':
                        $next->modify("+{$custom_value} months");
                        break;
                    case 'years':
                        $next->modify("+{$custom_value} years");
                        break;
                }
                break;
        }

        $to = $next->format('Y-m-d');

        //var_dump("Creating partition {$name} FROM ('{$from}') TO ('{$to}')");
        //$status = 0;

        // Create partition
        $status = $partitionActions->createPartition(
            $_REQUEST['table'],
            $name,
            'r',
            ['from' => $from, 'to' => $to],
            false
        );

        if ($status == 0) {
            $created++;
        } else {
            $failed++;
            $errors[] = "{$name}: {$pg->getLastError()}";
        }

        $current = $next;
    }

    if ($created > 0)
        AppContainer::setShouldReloadTree(true);

    if ($failed == 0) {
        doDefault(sprintf($lang['strpartitionbulkcreated'], $created));
    } else {
        $error_msg = sprintf($lang['strpartitionbulkpartial'], $created, $failed) . "<br/>" . implode("<br/>", array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $error_msg .= "<br/><em>... " . (count($errors) - 5) . " more errors</em>";
        }
        doDefault($error_msg);
    }
}

/**
 * Display list of partitions for a partitioned table
 */
function doDefault($msg = '')
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $partitionActions = new PartitionActions($pg);

    $misc->printTrail('table');
    $misc->printTabs('table', 'partitions');
    //$misc->printTitle($lang['strpartitions'], 'pg.partition');
    $misc->printMsg($msg);

    if ($pg->major_version < 10) {
        echo "<p>{$lang['strpartitionnotsupported']}</p>\n";
        return;
    }

    $partInfo = $partitionActions->getPartitionInfo($_REQUEST['table']);

    if (!is_object($partInfo) || $partInfo->recordCount() == 0) {
        echo "<p>{$lang['strinvalidparam']}</p>\n";
        return;
    }

    $strategy = $partInfo->fields['partstrat'];
    $partition_keys = $partInfo->fields['partition_keys'];

    // Convert PostgreSQL array format to PHP array
    $partition_keys = trim($partition_keys, '{}');
    $key_columns = explode(',', $partition_keys);

    // Map strategy code to name
    $strategy_name = PartitionActions::PARTITION_STRATEGY_MAP[$strategy] ?? $strategy;

    // Get partition pruning status
    $pruning = $partitionActions->getPartitionPruningEnabled();
    $pruning_badge = $pruning['enabled']
        ? "<span class=\"badge bg-success\">{$lang['strpartitionpruningenabled']}</span>"
        : "<span class=\"badge bg-warning\">{$lang['strpartitionpruningdisabled']}</span>";

    // Get total rows
    $total_rows = $partitionActions->getTotalPartitionRows($_REQUEST['table']);

    ?>
    <div class="partition-summary">
        <div class="flex-row my-3">
            <div class="me-2 ms-auto">
                <strong><?= $lang['strpartitionpruning']; ?>:</strong>
                <?= $pruning_badge; ?>
                (<?= $pruning['setting']; ?>)
            </div>
            <div class="mx-2">
                <strong>
                    <?= $lang['strpartitionedby'] ?>:
                </strong>
                <?= htmlspecialchars($strategy_name); ?>
                (<?= htmlspecialchars(implode(', ', $key_columns)); ?>)
            </div>
            <div class="ms-2 me-auto">
                <strong><?= $lang['strtotalpartitionrows']; ?>:</strong>
                <?= number_format($total_rows); ?>
            </div>
        </div>
        <!--
        <div class="my-3 text-center">
            <a href="partitions.php?action=analyze_all&amp;<?= $misc->href; ?>&amp;table=<?= urlencode($_REQUEST['table']); ?>"
                class="ui-btn"><?= $lang['stranalyzeallpartitions']; ?></a>
            <a href="partitions.php?action=test_pruning&amp;<?= $misc->href; ?>&amp;table=<?= urlencode($_REQUEST['table']); ?>"
                class="ui-btn"><?= $lang['strtestquerypruning']; ?></a>
            <a href="partitions.php?action=bulk_create&amp;<?= $misc->href; ?>&amp;table=<?= urlencode($_REQUEST['table']); ?>"
                class="ui-btn"><?= $lang['strbulkcreatepartitions']; ?></a>
        </div>
        -->
    </div>
    <?php

    // Get partitions
    $partitions = $partitionActions->getPartitions($_REQUEST['table']);

    // Calculate average size for warning detection
    $total_size = 0;
    $count = 0;

    if (is_object($partitions) && !$partitions->EOF) {
        while (!$partitions->EOF) {
            if ($partitions->fields['partition_bound'] !== null) {
                $total_size += $partitions->fields['size'];
                $count++;
            }
            $partitions->moveNext();
        }
    }
    $avg_size = $count > 0 ? $total_size / $count : 0;

    // Reset recordset pointer
    $partitions->moveFirst();

    // Pre-processing callback to add computed fields
    $partPre = function (&$rowdata) use ($misc, $lang, $avg_size) {
        $is_default = ($rowdata->fields['partition_bound'] === null || $rowdata->fields['partition_bound'] === '');
        $is_partitioned = ($rowdata->fields['relkind'] === 'p');

        // Set icon
        if ($is_default) {
            $rowdata->fields['icon'] = $misc->icon('DefaultPartition');
        } elseif ($is_partitioned) {
            $rowdata->fields['icon'] = $misc->icon('PartitionedTable');
        } else {
            $rowdata->fields['icon'] = $misc->icon('Partition');
        }

        // Format bounds field with badge for default partition
        if ($is_default) {
            $rowdata->fields['+bounds'] = $lang['strdefaultpartition'];
        } else {
            $rowdata->fields['+bounds'] = $rowdata->fields['partition_bound'];
        }

        // Format size with warning if oversized
        $size_formatted = $misc->formatVal($rowdata->fields['size'], 'prettysize');
        if (!$is_default && $avg_size > 0 && $rowdata->fields['size'] > ($avg_size * 2)) {
            $size_formatted .= " <span class=\"warning\" title=\"{$lang['strpartitionsizewarn']}\">⚠</span>";
        }
        $rowdata->fields['+size'] = $size_formatted;

        // Store flags for conditional actions
        $rowdata->fields['+is_partitioned'] = $is_partitioned;
    };

    // Define columns
    $columns = [
        'partition' => [
            'title' => $lang['strname'],
            'field' => field('relname'),
            'icon' => field('icon'),
            'url' => "display.php?subject=table&amp;{$misc->href}&amp;",
            'vars' => ['table' => 'relname'],
            'class' => 'nowrap',
        ],
        'bounds' => [
            'title' => $lang['strpartitionbounds'],
            'field' => field('+bounds'),
            'type' => 'sql',
            'params' => [
                'tag' => 'div',
            ],
        ],
        'rows' => [
            'title' => $lang['strrows'],
            'field' => field('reltuples'),
            'type' => 'numeric',
        ],
        'size' => [
            'title' => $lang['strsize'],
            'field' => field('+size'),
            'type' => 'html',
        ],
        'owner' => [
            'title' => $lang['strowner'],
            'field' => field('relowner'),
        ],
        'actions' => [
            'title' => $lang['stractions'],
        ],
        'comment' => [
            'title' => $lang['strcomment'],
            'field' => field('comment'),
        ],
    ];

    $footer = [
        'partition' => [
            'agg' => 'count',
            'format' => fn($v) => "$v {$lang['strpartitions']}",
        ],
        'bounds' => [
            'text' => $lang['strtotal'],
        ],
        'rows' => [
            'agg' => 'sum',
            'type' => 'prettyint',
            'class' => 'text-end',
            'params' => ['class' => 'numeric'],
        ],
        'size' => [
            'agg' => 'sum',
            'type' => 'prettysize',
            'class' => 'text-end',
            'field' => field('size'),
            //'params' => ['class' => 'numeric'],
        ],
        'actions' => [
            'text' => '',
            'colspan' => 2,
        ],
    ];

    // Define actions
    $actions = [
        'browse' => [
            'icon' => $misc->icon('Table'),
            'content' => $lang['strbrowse'],
            'attr' => [
                'href' => [
                    'url' => 'display.php',
                    'urlvars' => [
                        'subject' => 'table',
                        'table' => field('relname'),
                        'parent_table' => $_REQUEST['table'],
                    ],
                ],
            ],
        ],
        'manage_partitions' => [
            'icon' => $misc->icon('Partitions'),
            'content' => $lang['strpartitions'],
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'table' => field('relname'),
                    ],
                ],
            ],
        ],
        'detach' => [
            'icon' => $misc->icon('DetachPartition'),
            'content' => $lang['strdetach'],
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'confirm_detach',
                        'table' => $_REQUEST['table'],
                        'partition' => field('relname'),
                    ],
                ],
            ],
        ],
        'alter' => [
            'icon' => $misc->icon('Edit'),
            'content' => $lang['stredit'],
            'attr' => [
                'href' => [
                    'url' => 'tblproperties.php',
                    'urlvars' => [
                        'action' => 'confirm_alter',
                        'table' => field('relname'),
                        'parent_table' => $_REQUEST['table'],
                    ],
                ],
            ],
        ],
        'drop' => [
            'icon' => $misc->icon('Delete'),
            'content' => $lang['strdrop'],
            'attr' => [
                'href' => [
                    'url' => 'tables.php',
                    'urlvars' => [
                        'action' => 'confirm_drop',
                        'table' => field('relname'),
                        'parent_table' => $_REQUEST['table'],
                    ],
                ],
            ],
        ],
    ];

    // Hide "Manage Partitions" action for non-partitioned partitions
    $actions['manage_partitions']['disable'] = function ($row) {
        return !($row['+is_partitioned'] ?? false);
    };

    $misc->printTable(
        $partitions,
        $columns,
        $actions,
        'partitions-partitions',
        $lang['strnopartitions'],
        $partPre,
        $footer
    );

    // Navigation links
    $navlinks = [
        'create_partition' => [
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'create',
                        'server' => $_REQUEST['server'],
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table']
                    ]
                ]
            ],
            'icon' => $misc->icon('CreatePartition'),
            'content' => $lang['strcreatepartition']
        ],
        'bulk_create' => [
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'bulk_create',
                        'server' => $_REQUEST['server'],
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table']
                    ]
                ]
            ],
            'icon' => $misc->icon('BulkCreatePartitions'),
            'content' => $lang['strbulkcreatepartitions']
        ],
        'attach_partition' => [
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'attach',
                        'server' => $_REQUEST['server'],
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table']
                    ]
                ]
            ],
            'icon' => $misc->icon('AttachPartition'),
            'content' => $lang['strattachpartition']
        ],
        'analyze' => [
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'analyze_all',
                        'server' => $_REQUEST['server'],
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table']
                    ]
                ]
            ],
            'icon' => $misc->icon('Analyze'),
            'content' => $lang['stranalyzeallpartitions']
        ],
        'test_pruning' => [
            'attr' => [
                'href' => [
                    'url' => 'partitions.php',
                    'urlvars' => [
                        'action' => 'test_pruning',
                        'server' => $_REQUEST['server'],
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table']
                    ]
                ]
            ],
            'icon' => $misc->icon('Histories'),
            'content' => $lang['strtestquerypruning']
        ],
    ];

    if ($strategy !== 'r') {
        // Remove bulk create link for non-range partitioned tables
        unset($navlinks['bulk_create']);
    }

    $misc->printNavLinks($navlinks, 'partitions-partitions', get_defined_vars());
}

/**
 * Generate XML for the browser tree
 */
function doTree()
{
    $misc = AppContainer::getMisc();
    $pg = AppContainer::getPostgres();
    $partitionActions = new PartitionActions($pg);

    $partitions = $partitionActions->getPartitions($_REQUEST['table']);

    $reqvars = $misc->getRequestVars('table');

    $getIcon = function ($f) use ($pg) {
        // Check if default partition
        if ($f['partition_bound'] === null || $f['partition_bound'] === '') {
            return 'DefaultPartition';
        }
        // Check if sub-partitioned
        if ($f['relkind'] === 'p') {
            return 'PartitionedTable';
        }
        return 'Partition';
    };

    $attrs = [
        'text' => field('relname'),
        'icon' => callback($getIcon),
        'toolTip' => field('comment'),
        'action' => url(
            'redirect.php',
            $reqvars,
            ['subject' => 'table', 'table' => field('relname')]
        )
    ];

    // Add branch for sub-partitioned partitions
    if ($pg->major_version >= 10) {
        $attrs['branch'] = ifempty(
            field('relkind'),
            '',
            url(
                'partitions.php',
                $reqvars,
                [
                    'action' => 'subtree',
                    'table' => field('relname')
                ]
            )
        );
    }

    $misc->printTree($partitions, $attrs, 'partitions');
    exit;
}

function doSubTree()
{
    $misc = AppContainer::getMisc();
    $pg = AppContainer::getPostgres();

    $tabs = $misc->getNavTabs('table');
    $partitionActions = new PartitionActions($pg);
    if (!$partitionActions->isPartitionedTable($_REQUEST['table'])) {
        // Remove 'Partitions' tab for non-partitioned tables
        unset($tabs['partitions']);
    }
    $items = $misc->adjustTabsForTree($tabs);
    $reqvars = $misc->getRequestVars('table');

    $attrs = [
        'text' => field('title'),
        'icon' => field('icon'),
        'action' => url(
            field('url'),
            $reqvars,
            field('urlvars'),
            ['table' => $_REQUEST['table']]
        ),
        'branch' => ifempty(
            field('branch'),
            '',
            url(
                field('url'),
                $reqvars,
                [
                    'action' => 'tree',
                    'table' => $_REQUEST['table']
                ]
            )
        ),
    ];

    $misc->printTree($items, $attrs, 'table');
    exit;
}

$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree') {
    doTree();
}
if ($action == 'subtree') {
    doSubTree();
}

$misc->printHeader($lang['strpartitions']);
$misc->printBody();

switch ($action) {
    case 'create':
        doCreate();
        break;
    case 'save_create':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doSaveCreate();
        }
        break;
    case 'attach':
        doAttach();
        break;
    case 'save_attach':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doSaveAttach();
        }
        break;
    case 'confirm_detach':
        doDetach(true);
        break;
    case 'detach':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doDetach(false);
        }
        break;
    case 'analyze_all':
        doAnalyzeAll();
        break;
    case 'test_pruning':
        doTestPruning();
        break;
    case 'test_pruning_execute':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doTestPruning();
        }
        break;
    case 'bulk_create':
        doBulkCreate();
        break;
    case 'save_bulk_create':
        if (isset($_POST['cancel'])) {
            doDefault();
        } else {
            doSaveBulkCreate();
        }
        break;
    default:
        doDefault();
        break;
}

$misc->printFooter();
