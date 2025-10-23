<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\PartitionActions;
use PhpPgAdmin\Database\Actions\ViewActions;

class TabsRenderer extends AppContext
{

    /**
     * @param array|string $tabs
     * @param string $activeTab
     */
    public function printTabs($tabs, $activeTab): void
    {
        if (is_string($tabs)) {
            $_SESSION['webdbLastTab'][$tabs] = $activeTab;
            $tabs = $this->getNavTabs($tabs);
        }

        if (count($tabs) > 0) {
            $width = number_format(100 / count($tabs), 2) . '%';
        } else {
            $width = '1';
        }

        echo "<table class=\"tabs\"><tr>\n";
        foreach ($tabs as $tabId => $tab) {
            $active = ($tabId == $activeTab) ? ' active' : '';
            if (($tab['hide'] ?? false) === true) {
                continue;
            }

            $tablink = '<a href="' . htmlentities($this->misc()->getActionUrl($tab, $_REQUEST)) . '">';
            if (isset($tab['icon']) && ($icon = $this->misc()->icon($tab['icon']))) {
                $tablink .= "<span class=\"icon\"><img src=\"{$icon}\" alt=\"{$tab['title']}\" /></span>";
            }
            $tablink .= "<span class=\"label\">{$tab['title']}</span></a>";

            echo "<td style=\"width: {$width}\" class=\"tab{$active}\">";
            if (isset($tab['help'])) {
                $this->misc()->printHelp($tablink, $tab['help']);
            } else {
                echo $tablink;
            }
            echo "</td>\n";
        }
        echo "</tr></table>\n";
    }

    /**
     * @param string $section
     * @return array|array[]|mixed
     */
    public function getNavTabs($section)
    {
        $pg = $this->postgres();
        $lang = $this->lang();
        $conf = $this->conf();
        $pluginManager = $this->pluginManager();
        $roleActions = new RoleActions($pg);

        $hideAdvanced = ($conf['show_advanced'] === false);
        $tabs = [];

        switch ($section) {
            case 'root':
                $tabs = [
                    'intro' => [
                        'title' => $lang['strintroduction'],
                        'url' => 'intro.php',
                        'icon' => 'Introduction',
                    ],
                    'servers' => [
                        'title' => $lang['strservers'],
                        'url' => 'servers.php',
                        'icon' => 'Servers',
                    ],
                ];
                break;

            case 'server':
                $hideUsers = !$roleActions->isSuperUser();
                $tabs = [
                    'databases' => [
                        'title' => $lang['strdatabases'],
                        'url' => 'all_db.php',
                        'urlvars' => ['subject' => 'server'],
                        'help' => 'pg.database',
                        'icon' => 'Databases',
                    ],
                    'roles' => [
                        'title' => $lang['strroles'],
                        'url' => 'roles.php',
                        'urlvars' => ['subject' => 'server'],
                        'hide' => $hideUsers,
                        'help' => 'pg.role',
                        'icon' => 'Roles',
                    ],
                    'account' => [
                        'title' => $lang['straccount'],
                        'url' => 'roles.php',
                        'urlvars' => ['subject' => 'server', 'action' => 'account'],
                        'hide' => !$hideUsers,
                        'help' => 'pg.role',
                        'icon' => 'User',
                    ],
                    'tablespaces' => [
                        'title' => $lang['strtablespaces'],
                        'url' => 'tablespaces.php',
                        'urlvars' => ['subject' => 'server'],
                        'hide' => (!$pg->hasTablespaces()),
                        'help' => 'pg.tablespace',
                        'icon' => 'Tablespaces',
                    ],
                    'export' => [
                        'title' => $lang['strexport'],
                        'url' => 'all_db.php',
                        'urlvars' => ['subject' => 'server', 'action' => 'export'],
                        'hide' => (!$this->misc()->isDumpEnabled()),
                        'icon' => 'Export',
                    ],
                    'import' => [
                        'title' => $lang['strimport'],
                        'url' => 'all_db.php',
                        'urlvars' => ['subject' => 'server', 'action' => 'import'],
                        'hide' => empty($conf['import']['enabled']),
                        'icon' => 'Import',
                    ],
                ];
                break;
            case 'database':
                $tabs = [
                    'schemas' => [
                        'title' => $lang['strschemas'],
                        'url' => 'schemas.php',
                        'urlvars' => ['subject' => 'database'],
                        'help' => 'pg.schema',
                        'icon' => 'Schemas',
                    ],
                    'catalogs' => [
                        'title' => $lang['strcatalogs'],
                        'url' => 'catalogs.php',
                        'urlvars' => ['subject' => 'database'],
                        'help' => 'pg.catalog',
                        'tree' => true,
                        'icon' => 'Catalogs',
                    ],
                    'sql' => [
                        'title' => $lang['strsqleditor'],
                        'url' => 'database.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'sql', 'new' => 1],
                        'help' => 'pg.sql',
                        'tree' => false,
                        'icon' => 'SqlEditor'
                    ],
                    'find' => [
                        'title' => $lang['strfind'],
                        'url' => 'database.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'find'],
                        'tree' => false,
                        'icon' => 'Search'
                    ],
                    'variables' => [
                        'title' => $lang['strvariables'],
                        'url' => 'database.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'variables'],
                        'help' => 'pg.variable',
                        'tree' => false,
                        'icon' => 'Variables',
                    ],
                    'statistics' => [
                        'title' => $lang['strstatistics'],
                        'url' => 'statistics.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'statistics'],
                        'help' => 'pg.statistics',
                        'tree' => false,
                        'icon' => 'Statistics',
                    ],
                    'processes' => [
                        'title' => $lang['strprocesses'],
                        'url' => 'database.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'processes'],
                        'help' => 'pg.process',
                        'tree' => false,
                        'icon' => 'Processes',
                    ],
                    'locks' => [
                        'title' => $lang['strlocks'],
                        'url' => 'database.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'locks'],
                        'help' => 'pg.locks',
                        'tree' => false,
                        'icon' => 'Key',
                    ],
                    'admin' => [
                        'title' => $lang['stradmin'],
                        'url' => 'database.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'admin'],
                        'tree' => false,
                        'icon' => 'Admin',
                    ],
                    'privileges' => [
                        'title' => $lang['strprivileges'],
                        'url' => 'privileges.php',
                        'urlvars' => ['subject' => 'database'],
                        //'hide' => (!isset(AclActions::PRIV_LIST['database'])),
                        'help' => 'pg.privilege',
                        'tree' => false,
                        'icon' => 'Privileges',
                    ],
                    'languages' => [
                        'title' => $lang['strlanguages'],
                        'url' => 'languages.php',
                        'urlvars' => ['subject' => 'database'],
                        'hide' => $hideAdvanced,
                        'help' => 'pg.language',
                        'icon' => 'Languages',
                    ],
                    'casts' => [
                        'title' => $lang['strcasts'],
                        'url' => 'casts.php',
                        'urlvars' => ['subject' => 'database'],
                        'hide' => ($hideAdvanced),
                        'help' => 'pg.cast',
                        'icon' => 'Casts',
                    ],
                    'export' => [
                        'title' => $lang['strexport'],
                        'url' => 'database.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'export'],
                        //'hide' => (!$this->misc()->isDumpEnabled()),
                        'tree' => false,
                        'icon' => 'Export',
                    ],
                    'import' => [
                        'title' => $lang['strimport'],
                        'url' => 'database.php',
                        'urlvars' => ['subject' => 'database', 'action' => 'import'],
                        //'hide' => false,
                        'tree' => false,
                        'icon' => 'Import',
                    ],
                ];
                break;

            case 'schema':
                $tabs = [
                    'tables' => [
                        'title' => $lang['strtables'],
                        'url' => 'tables.php',
                        'urlvars' => ['subject' => 'schema'],
                        'help' => 'pg.table',
                        'icon' => 'Tables',
                    ],
                    'views' => [
                        'title' => $lang['strviews'],
                        'url' => 'views.php',
                        'urlvars' => ['subject' => 'schema'],
                        'help' => 'pg.view',
                        'icon' => 'Views',
                    ],
                    'sequences' => [
                        'title' => $lang['strsequences'],
                        'url' => 'sequences.php',
                        'urlvars' => ['subject' => 'schema'],
                        'help' => 'pg.sequence',
                        'icon' => 'Sequences',
                    ],
                    'functions' => [
                        'title' => $lang['strfunctions'],
                        'url' => 'functions.php',
                        'urlvars' => ['subject' => 'schema'],
                        'help' => 'pg.function',
                        'icon' => 'Functions',
                    ],
                    'domains' => [
                        'title' => $lang['strdomains'],
                        'url' => 'domains.php',
                        'urlvars' => ['subject' => 'schema'],
                        'help' => 'pg.domain',
                        'icon' => 'Domains',
                    ],
                    'types' => [
                        'title' => $lang['strtypes'],
                        'url' => 'types.php',
                        'urlvars' => ['subject' => 'schema'],
                        'hide' => $hideAdvanced,
                        'help' => 'pg.type',
                        'icon' => 'Types',
                    ],
                    'aggregates' => [
                        'title' => $lang['straggregates'],
                        'url' => 'aggregates.php',
                        'urlvars' => ['subject' => 'schema'],
                        'hide' => $hideAdvanced,
                        'help' => 'pg.aggregate',
                        'icon' => 'Aggregates',
                    ],
                    'operators' => [
                        'title' => $lang['stroperators'],
                        'url' => 'operators.php',
                        'urlvars' => ['subject' => 'schema'],
                        'hide' => $hideAdvanced,
                        'help' => 'pg.operator',
                        'icon' => 'Operators',
                    ],
                    'opclasses' => [
                        'title' => $lang['stropclasses'],
                        'url' => 'opclasses.php',
                        'urlvars' => ['subject' => 'schema'],
                        'hide' => $hideAdvanced,
                        'help' => 'pg.opclass',
                        'icon' => 'OperatorClasses',
                    ],
                    /*
                    'conversions' => [
                        'title' => $lang['strconversions'],
                        'url' => 'conversions.php',
                        'urlvars' => ['subject' => 'schema'],
                        'hide' => $hideAdvanced,
                        'help' => 'pg.conversion',
                        'icon' => 'Conversions',
                    ],
                    */
                    'privileges' => [
                        'title' => $lang['strprivileges'],
                        'url' => 'privileges.php',
                        'urlvars' => ['subject' => 'schema'],
                        'help' => 'pg.privilege',
                        'tree' => false,
                        'icon' => 'Privileges',
                    ],
                    'fulltext' => [
                        'title' => $lang['strfulltext'],
                        'url' => 'fulltext.php',
                        'urlvars' => ['subject' => 'schema'],
                        'help' => 'pg.fts',
                        'tree' => true,
                        'icon' => 'Fts',
                    ],
                    'export' => [
                        'title' => $lang['strexport'],
                        'url' => 'schemas.php',
                        'urlvars' => ['subject' => 'schema', 'action' => 'export'],
                        'hide' => (!$this->misc()->isDumpEnabled()),
                        'tree' => false,
                        'icon' => 'Export',
                    ],
                    'import' => [
                        'title' => $lang['strimport'],
                        'url' => 'schemas.php',
                        'urlvars' => ['subject' => 'schema', 'action' => 'import'],
                        'hide' => false,
                        'tree' => false,
                        'icon' => 'Import',
                    ],
                ];
                if ($this->misc()->isCatalogSchema()) {
                    unset($tabs['sequences'], $tabs['import']);
                }
                break;

            case 'table':
                $tabs = [
                    'browse' => [
                        'title' => $lang['strbrowse'],
                        'icon' => 'Table',
                        'url' => 'display.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'return' => 'table',
                    ],
                    'columns' => [
                        'title' => $lang['strcolumns'],
                        'url' => 'tblproperties.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'icon' => 'Columns',
                        'branch' => true,
                    ],
                    'select' => [
                        'title' => $lang['strselect'],
                        'icon' => 'Search',
                        'url' => 'tables.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table'), 'action' => 'confselectrows',],
                        'help' => 'pg.sql.select',
                    ],
                    'insert' => [
                        'title' => $lang['strinsert'],
                        'url' => 'display.php',
                        'urlvars' => [
                            'action' => 'confinsertrow',
                            'subject' => 'table',
                            'table' => field('table'),
                        ],
                        'help' => 'pg.sql.insert',
                        'icon' => 'Add',
                        'tree' => false,
                    ],
                    'indexes' => [
                        'title' => $lang['strindexes'],
                        'url' => 'indexes.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'help' => 'pg.index',
                        'icon' => 'Indexes',
                        'branch' => true,
                    ],
                    'constraints' => [
                        'title' => $lang['strconstraints'],
                        'url' => 'constraints.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'help' => 'pg.constraint',
                        'icon' => 'Constraints',
                        'branch' => true,
                    ],
                    'triggers' => [
                        'title' => $lang['strtriggers'],
                        'url' => 'triggers.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'help' => 'pg.trigger',
                        'icon' => 'Triggers',
                        'branch' => true,
                    ],
                    'partitions' => [
                        'title' => $lang['strpartitions'],
                        'url' => 'partitions.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'help' => 'pg.partition',
                        'icon' => 'Partitions',
                        'branch' => true,
                        'hide' => $pg->major_version < 10, // Only show for PG10+
                    ],
                    'rules' => [
                        'title' => $lang['strrules'],
                        'url' => 'rules.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'help' => 'pg.rule',
                        'icon' => 'Rules',
                        'branch' => true,
                    ],
                    'admin' => [
                        'title' => $lang['stradmin'],
                        'url' => 'tables.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table'), 'action' => 'admin'],
                        'icon' => 'Admin',
                        'tree' => false,
                    ],
                    'info' => [
                        'title' => $lang['strinfo'],
                        'url' => 'info.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'icon' => 'Statistics',
                        'tree' => false,
                    ],
                    'privileges' => [
                        'title' => $lang['strprivileges'],
                        'url' => 'privileges.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table')],
                        'help' => 'pg.privilege',
                        'icon' => 'Privileges',
                        'tree' => false,
                    ],
                    'export' => [
                        'title' => $lang['strexport'],
                        'url' => 'tblproperties.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table'), 'action' => 'export'],
                        'icon' => 'Export',
                        'hide' => false,
                        'tree' => false,
                    ],
                    'import' => [
                        'title' => $lang['strimport'],
                        'url' => 'tblproperties.php',
                        'urlvars' => ['subject' => 'table', 'table' => field('table'), 'action' => 'import'],
                        'icon' => 'Import',
                        'hide' => false,
                        'tree' => false,
                    ],
                ];
                if ($this->misc()->isCatalogSchema()) {
                    unset(
                        $tabs['insert'],
                        $tabs['constraints'],
                        $tabs['triggers'],
                        $tabs['rules'],
                        $tabs['admin'],
                        $tabs['import']
                    );
                } elseif (!empty($_REQUEST['table'])) {
                    $partitionActions = new PartitionActions(
                        $this->postgres()
                    );
                    $isPartitioned = $partitionActions->isPartitionedTable(
                        $_REQUEST['table']
                    );
                    if (!$isPartitioned) {
                        // Remove 'Partitions' tab for non-partitioned tables
                        unset($tabs['partitions']);
                    }
                }
                break;

            case 'view':
                $tabs = [
                    'browse' => [
                        'title' => $lang['strbrowse'],
                        'icon' => 'Table',
                        'url' => 'display.php',
                        'urlvars' => [
                            'action' => 'confselectrows',
                            'return' => 'schema',
                            'subject' => 'view',
                            'view' => field('view')
                        ],
                    ],
                    'columns' => [
                        'title' => $lang['strcolumns'],
                        'url' => 'viewproperties.php',
                        'urlvars' => ['subject' => 'view', 'view' => field('view')],
                        'icon' => 'Columns',
                        'branch' => true,
                    ],
                    'select' => [
                        'title' => $lang['strselect'],
                        'icon' => 'Search',
                        'url' => 'views.php',
                        'urlvars' => ['action' => 'confselectrows', 'view' => field('view'),],
                        'help' => 'pg.sql.select',
                    ],
                    'indexes' => [
                        'title' => $lang['strindexes'],
                        'url' => 'indexes.php',
                        'urlvars' => ['subject' => 'view', 'view' => field('view')],
                        'help' => 'pg.index',
                        'icon' => 'Indexes',
                        'branch' => true,
                    ],
                    /*
                    'constraints' => [
                        'title' => $lang['strconstraints'],
                        'url' => 'constraints.php',
                        'urlvars' => ['subject' => 'view', 'view' => field('view')],
                        'help' => 'pg.constraint',
                        'icon' => 'Constraints',
                        'branch' => true,
                    ],
                    */
                    'definition' => [
                        'title' => $lang['strdefinition'],
                        'url' => 'viewproperties.php',
                        'urlvars' => ['subject' => 'view', 'view' => field('view'), 'action' => 'definition'],
                        'icon' => 'Definition'
                    ],
                    'rules' => [
                        'title' => $lang['strrules'],
                        'url' => 'rules.php',
                        'urlvars' => ['subject' => 'view', 'view' => field('view')],
                        'help' => 'pg.rule',
                        'icon' => 'Rules',
                        'branch' => true,
                    ],
                    'privileges' => [
                        'title' => $lang['strprivileges'],
                        'url' => 'privileges.php',
                        'urlvars' => ['subject' => 'view', 'view' => field('view')],
                        'help' => 'pg.privilege',
                        'icon' => 'Privileges',
                    ],
                    'export' => [
                        'title' => $lang['strexport'],
                        'url' => 'viewproperties.php',
                        'urlvars' => ['subject' => 'view', 'view' => field('view'), 'action' => 'export'],
                        'icon' => 'Export',
                        'hide' => false,
                    ],
                ];
                if ($this->misc()->isCatalogSchema()) {
                    unset(
                        $tabs['rules']
                    );
                }
                $viewActions = new ViewActions($pg);
                $isMaterialized = $viewActions->isMaterializedView(
                    $_REQUEST['view'] ?? ''
                );
                if (!$isMaterialized) {
                    unset($tabs['indexes']);
                }
                break;

            case 'function':
                $tabs = [
                    'definition' => [
                        'title' => $lang['strdefinition'],
                        'url' => 'functions.php',
                        'urlvars' => [
                            'subject' => 'function',
                            'function' => field('function'),
                            'function_oid' => field('function_oid'),
                            'action' => 'properties',
                        ],
                        'icon' => 'Definition',
                    ],
                    'privileges' => [
                        'title' => $lang['strprivileges'],
                        'url' => 'privileges.php',
                        'urlvars' => [
                            'subject' => 'function',
                            'function' => field('function'),
                            'function_oid' => field('function_oid'),
                        ],
                        'icon' => 'Privileges',
                    ],
                ];
                break;

            case 'aggregate':
                $tabs = [
                    'definition' => [
                        'title' => $lang['strdefinition'],
                        'url' => 'aggregates.php',
                        'urlvars' => [
                            'subject' => 'aggregate',
                            'aggrname' => field('aggrname'),
                            'aggrtype' => field('aggrtype'),
                            'action' => 'properties',
                        ],
                        'icon' => 'Definition',
                    ],
                ];
                break;

            case 'role':
                $tabs = [
                    'definition' => [
                        'title' => $lang['strdefinition'],
                        'url' => 'roles.php',
                        'urlvars' => [
                            'subject' => 'role',
                            'rolename' => field('rolename'),
                            'action' => 'properties',
                        ],
                        'icon' => 'Definition',
                    ],
                ];
                break;

            case 'popup':
                $tabs = [
                    'sql' => [
                        'title' => $lang['strsqleditor'],
                        'url' => 'sqledit.php',
                        'urlvars' => ['subject' => 'schema', 'action' => 'sql'],
                        'help' => 'pg.sql',
                        'icon' => 'SqlEditor',
                    ],
                    'find' => [
                        'title' => $lang['strfind'],
                        'url' => 'sqledit.php',
                        'urlvars' => ['subject' => 'schema', 'action' => 'find'],
                        'icon' => 'Search',
                    ],
                ];
                break;

            case 'column':
                $tabs = [
                    'properties' => [
                        'title' => $lang['strcolprop'],
                        'url' => 'colproperties.php',
                        'urlvars' => [
                            'subject' => 'column',
                            'table' => field('table'),
                            'column' => field('column')
                        ],
                        'icon' => 'Column'
                    ],
                    'privileges' => [
                        'title' => $lang['strprivileges'],
                        'url' => 'privileges.php',
                        'urlvars' => [
                            'subject' => 'column',
                            'table' => field('table'),
                            'column' => field('column')
                        ],
                        'help' => 'pg.privilege',
                        'icon' => 'Privileges',
                    ]
                ];
                break;

            case 'fulltext':
                $tabs = [
                    'ftsconfigs' => [
                        'title' => $lang['strftstabconfigs'],
                        'url' => 'fulltext.php',
                        'urlvars' => ['subject' => 'schema'],
                        'hide' => !$pg->hasFTS(),
                        'help' => 'pg.ftscfg',
                        'tree' => true,
                        'icon' => 'FtsCfg',
                    ],
                    'ftsdicts' => [
                        'title' => $lang['strftstabdicts'],
                        'url' => 'fulltext.php',
                        'urlvars' => ['subject' => 'schema', 'action' => 'viewdicts'],
                        'hide' => !$pg->hasFTS(),
                        'help' => 'pg.ftsdict',
                        'tree' => true,
                        'icon' => 'FtsDict',
                    ],
                    'ftsparsers' => [
                        'title' => $lang['strftstabparsers'],
                        'url' => 'fulltext.php',
                        'urlvars' => ['subject' => 'schema', 'action' => 'viewparsers'],
                        'hide' => !$pg->hasFTS(),
                        'help' => 'pg.ftsparser',
                        'tree' => true,
                        'icon' => 'FtsParser',
                    ],
                ];
                break;
        }

        if ($pluginManager) {
            $pluginFunctionsParameters = [
                'tabs' => &$tabs,
                'section' => $section
            ];
            $pluginManager->do_hook('tabs', $pluginFunctionsParameters);
        }

        return $tabs;
    }

    public function getLastTabURL($section)
    {
        $tabs = $this->getNavTabs($section);
        $tab = $tabs[$_SESSION['webdbLastTab'][$section] ?? ''] ?? reset($tabs);
        // Use first tab to avoid ambiguous behavior
        //$tab = reset($tabs);

        return isset($tab['url']) ? $tab : null;
    }
}
