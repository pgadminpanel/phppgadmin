<?php

use PhpPgAdmin\Database\Actions\DatabaseActions;
use PhpPgAdmin\Core\AppContainer;

/**
 * AJAX endpoint for database statistics
 * Returns JSON with current statistics for the connected database
 *
 * $Id: stats.php $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Show database statistics dashboard with real-time charts
 */
function doDefault()
{
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $conf = AppContainer::getConf();

    $misc->printHeader($lang['strstatistics']);
    $misc->printBody();
    $misc->printTrail('database');
    $misc->printTabs('database', 'statistics');

    // Get ajax refresh interval in milliseconds
    $ajax_refresh = ($conf['ajax_refresh'] ?? 3) * 1000;

    ?>
    <style>
    </style>

    <div class="stats-controls">
        <button id="pauseResumeBtn" class="active">
            <span class="pause-icon">⏸</span> <?= htmlspecialchars($lang['strpauserefresh']) ?>
        </button>
    </div>

    <div class="stats-container">
        <div class="chart-wrapper">
            <h3><?= htmlspecialchars($lang['strdatabasesessions']) ?></h3>
            <canvas id="sessionsChart"></canvas>
        </div>

        <div class="chart-wrapper">
            <h3><?= htmlspecialchars($lang['strtransactionspersecond']) ?></h3>
            <canvas id="transactionsChart"></canvas>
        </div>

        <div class="chart-wrapper">
            <h3><?= htmlspecialchars($lang['strtuplinesin']) ?></h3>
            <canvas id="tuplesInChart"></canvas>
        </div>

        <div class="chart-wrapper">
            <h3><?= htmlspecialchars($lang['strtuplinesout']) ?></h3>
            <canvas id="tuplesOutChart"></canvas>
        </div>

        <div class="chart-wrapper">
            <h3><?= htmlspecialchars($lang['strblockio']) ?></h3>
            <canvas id="blockIOChart"></canvas>
        </div>
    </div>

    <script src="js/lib/chart.js/dist/chart.umd.min.js"></script>
    <script>
        var Database = Database || {};
        Database.ajax_time_refresh = <?= (int) $ajax_refresh ?>;
        Database.server = <?= json_encode($_REQUEST['server'] ?? '') ?>;
        Database.dbname = <?= json_encode($_REQUEST['database'] ?? '') ?>;
        Database.lang = <?= json_encode([
            'total' => $lang['strtotal'],
            'active' => $lang['stractive'],
            'idle' => $lang['stridle'],
            'commits' => $lang['strcommits'],
            'rollbacks' => $lang['strrollbacks'],
            'transactions' => $lang['strtransactions'],
            'inserts' => $lang['strinserts'],
            'updates' => $lang['strupdates'],
            'deletes' => $lang['strdeletes'],
            'fetched' => $lang['strfetched'],
            'returned' => $lang['strreturned'],
            'reads' => $lang['strreads'],
            'hits' => $lang['strhits'],
            'pauseRefresh' => $lang['strpauserefresh'],
            'resumeRefresh' => $lang['strresumerefresh']
        ]) ?>;
    </script>
    <script src="js/statistics.js"></script>
    <?php
    $misc->printFooter();
}

function doJsonStats()
{
    // Set content type to JSON
    header('Content-Type: application/json');

    try {
        $pg = AppContainer::getPostgres();
        $databaseActions = new DatabaseActions($pg);

        // Get statistics
        $stats = $databaseActions->getDatabaseStats();

        // Return JSON response
        echo json_encode($stats);
    } catch (Exception $e) {
        // Return error in JSON format
        http_response_code(500);
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage()
        ]);
    }
}

// Determine action based on request
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'json_stats':
        doJsonStats();
        break;
    default:
        doDefault();
        break;
}