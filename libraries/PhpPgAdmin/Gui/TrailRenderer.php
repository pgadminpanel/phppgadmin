<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\ViewActions;
use PhpPgAdmin\Database\Actions\TableActions;

/**
 * Trail rendering: breadcrumb navigation hierarchy
 * Extracts getTrail() and printTrail() from legacy Misc class
 */
class TrailRenderer extends AppContext
{

    /**
     * Display a bread crumb trail.
     */
    public function printTrail($trail = []): void
    {
        $lang = $this->lang();

        if (is_string($trail)) {
            $trail = $this->getTrail($trail);
        }

        echo "<div class=\"trail\"><table><tr>";

        foreach ($trail as $crumb) {
            echo "<td class=\"crumb\">";
            $crumblink = "<a";

            if (isset($crumb['url']))
                $crumblink .= " href=\"{$crumb['url']}\"";

            if (isset($crumb['title']))
                $crumblink .= " title=\"{$crumb['title']}\"";

            $crumblink .= ">";

            if (isset($crumb['title']))
                $iconalt = $crumb['title'];
            else
                $iconalt = 'Database Root';

            if (isset($crumb['icon']) && $icon = $this->misc()->icon($crumb['icon']))
                $crumblink .= "<span class=\"icon\"><img src=\"{$icon}\" alt=\"{$iconalt}\" /></span>";

            $crumblink .= "<span class=\"label\">" . htmlspecialchars($crumb['text']) . "</span></a>";

            if (isset($crumb['help']))
                $this->misc()->printHelp($crumblink, $crumb['help']);
            else
                echo $crumblink;

            echo "{$lang['strseparator']}";
            echo "</td>";
        }

        echo "</tr></table></div>\n";
    }

    /**
     * Create a bread crumb trail of the object hierarchy.
     * @param string $subject The type of object at the end of the trail.
     */
    public function getTrail($subject = null): array
    {
        $lang = $this->lang();
        $pluginManager = $this->pluginManager();

        $appName = $GLOBALS['appName'] ?? 'phpPgAdmin';

        $trail = [];
        $vars = '';
        $done = false;

        $trail['root'] = [
            'text' => $appName,
            'url' => 'redirect.php?subject=root',
            'icon' => 'Introduction'
        ];

        if ($subject == 'root')
            $done = true;

        if (!$done) {
            $server_info = $this->misc()->getServerInfo();
            $trail['server'] = [
                'title' => $lang['strserver'],
                'text' => $server_info['desc'],
                'url' => $this->misc()->getHREFSubject('server'),
                'help' => 'pg.server',
                'icon' => 'Server'
            ];
        }
        if ($subject == 'server')
            $done = true;

        if (isset($_REQUEST['database']) && !$done) {
            $trail['database'] = [
                'title' => $lang['strdatabase'],
                'text' => $_REQUEST['database'],
                'url' => $this->misc()->getHREFSubject('database'),
                'help' => 'pg.database',
                'icon' => 'Database'
            ];
        } elseif (isset($_REQUEST['rolename']) && !$done) {
            $trail['role'] = [
                'title' => $lang['strrole'],
                'text' => $_REQUEST['rolename'],
                'url' => $this->misc()->getHREFSubject('role'),
                'help' => 'pg.role',
                'icon' => 'Roles'
            ];
        }
        if ($subject == 'database' || $subject == 'role')
            $done = true;

        if (isset($_REQUEST['schema']) && !$done) {
            $isCatalog = $this->misc()->isCatalogSchema($_REQUEST['schema']);
            $trail['schema'] = [
                'title' => $lang['strschema'],
                'text' => $_REQUEST['schema'],
                'url' => $this->misc()->getHREFSubject('schema'),
                'help' => 'pg.schema',
                'icon' => $isCatalog ? 'Catalog' : 'Schema'
            ];
        }
        if ($subject == 'schema')
            $done = true;

        if (isset($_REQUEST['table']) && !$done) {
            $tableActions = new TableActions($this->postgres());
            $tableType = $tableActions->getTableType(
                $this->postgres()->_schema,
                $_REQUEST['table'],
                true
            );
            switch ($tableType) {
                case 'partitioned_table':
                    $icon = 'PartitionedTable';
                    break;
                case 'partition':
                    $icon = 'Partition';
                    break;
                default:
                    $icon = 'Table';
            }
            $trail['table'] = [
                'title' => $lang['strtable'],
                'text' => $_REQUEST['table'],
                'url' => $this->misc()->getHREFSubject('table'),
                'help' => 'pg.table',
                'icon' => $icon
            ];
        } elseif (isset($_REQUEST['view']) && !$done) {
            $viewActions = new ViewActions($this->postgres());
            $isMaterialized = $viewActions->isMaterializedView(
                $_REQUEST['view']
            );
            $icon = $isMaterialized ? 'MaterializedView' : 'View';
            $trail['view'] = [
                'title' => $lang['strview'],
                'text' => $_REQUEST['view'],
                'url' => $this->misc()->getHREFSubject('view'),
                'help' => 'pg.view',
                'icon' => $icon
            ];
        } elseif (isset($_REQUEST['ftscfg']) && !$done) {
            $trail['ftscfg'] = [
                'title' => $lang['strftsconfig'],
                'text' => $_REQUEST['ftscfg'],
                'url' => $this->misc()->getHREFSubject('ftscfg'),
                'help' => 'pg.ftscfg.example',
                'icon' => 'Fts'
            ];
        }
        if ($subject == 'table' || $subject == 'view' || $subject == 'ftscfg')
            $done = true;

        if (!$done && !is_null($subject)) {
            switch ($subject) {
                case 'function':
                    $trail[$subject] = [
                        'title' => $lang['str' . $subject],
                        'text' => $_REQUEST[$subject],
                        'url' => $this->misc()->getHREFSubject('function'),
                        'help' => 'pg.function',
                        'icon' => 'Function'
                    ];
                    break;
                case 'aggregate':
                    $trail[$subject] = [
                        'title' => $lang['straggregate'],
                        'text' => $_REQUEST['aggrname'],
                        'url' => $this->misc()->getHREFSubject('aggregate'),
                        'help' => 'pg.aggregate',
                        'icon' => 'Aggregate'
                    ];
                    break;
                case 'column':
                    $trail['column'] = [
                        'title' => $lang['strcolumn'],
                        'text' => $_REQUEST['column'],
                        'icon' => 'Column',
                        'url' => $this->misc()->getHREFSubject('column')
                    ];
                    break;
                default:
                    if (isset($_REQUEST[$subject])) {
                        switch ($subject) {
                            case 'domain':
                                $icon = 'Domain';
                                break;
                            case 'sequence':
                                $icon = 'Sequence';
                                break;
                            case 'type':
                                $icon = 'Type';
                                break;
                            case 'operator':
                                $icon = 'Operator';
                                break;
                            case 'trigger':
                                $icon = 'Trigger';
                                break;
                            case 'index':
                                $icon = 'Index';
                                break;
                            case 'constraint':
                                $icon = 'Constraint';
                                break;
                            default:
                                $icon = null;
                                break;
                        }
                        $trail[$subject] = [
                            'title' => $lang['str' . $subject],
                            'text' => $_REQUEST[$subject],
                            'help' => 'pg.' . $subject,
                            'icon' => $icon,
                        ];
                    }
            }
        }

        // Trail hook's place
        if ($pluginManager) {
            $plugin_functions_parameters = [
                'trail' => &$trail,
                'section' => $subject
            ];
            $pluginManager->do_hook('trail', $plugin_functions_parameters);
        }

        return $trail;
    }
}
