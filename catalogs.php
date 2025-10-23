<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\SchemaActions;

/**
 * Manage system catalog schemas (pg_catalog and information_schema)
 * 
 * System catalogs are displayed separately from user schemas to provide
 * a clearer organization matching pgAdmin III/4 conventions.
 */

// Include application functions
include_once('./libraries/bootstrap.php');


/**
 * Show default list of catalog schemas
 */
function doDefault($msg = '')
{
    $pg = AppContainer::getPostgres();
    $misc = AppContainer::getMisc();
    $lang = AppContainer::getLang();
    $schemaActions = new SchemaActions($pg);

    $misc->printTrail('database');
    $misc->printTabs('database', 'catalogs');
    $misc->printMsg($msg);

    // Get catalog schemas (pg_catalog and information_schema)
    $catalogs = $schemaActions->getCatalogSchemas();

    $columns = [
        'catalog' => [
            'title' => $lang['strcatalog'],
            'field' => field('nspname'),
            'url' => "redirect.php?subject=schema&amp;{$misc->href}&amp;",
            'vars' => ['schema' => 'nspname'],
            'icon' => $misc->icon('Catalog'),
            'class' => 'no-wrap',
        ],
        'owner' => [
            'title' => $lang['strowner'],
            'field' => field('nspowner'),
        ],
        'comment' => [
            'title' => $lang['strcomment'],
            'field' => field('nspcomment'),
        ],
    ];

    $footer = [
        'catalog' => [
            'agg' => 'count',
            'format' => fn($v) => "$v {$lang['strcatalogs']}",
        ],
        'owner' => [
            'text' => $lang['strtotal'],
        ],
    ];

    $actions = [
        'privileges' => [
            'icon' => $misc->icon('Privileges'),
            'content' => $lang['strprivileges'],
            'attr' => [
                'href' => [
                    'url' => 'privileges.php',
                    'urlvars' => [
                        'subject' => 'schema',
                        'schema' => field('nspname')
                    ]
                ]
            ]
        ],
    ];

    $misc->printTable(
        $catalogs,
        $columns,
        $actions,
        'catalogs-catalogs',
        $lang['strnocatalogs'],
        null,
        $footer
    );
}

/**
 * Generate XML for the browser tree.
 */
function doTree()
{
    $misc = AppContainer::getMisc();
    $pg = AppContainer::getPostgres();
    $lang = AppContainer::getLang();
    $schemaActions = new SchemaActions($pg);

    $catalogs = $schemaActions->getCatalogSchemas();

    $reqvars = $misc->getRequestVars('schema');

    $attrs = [
        'text' => field('nspname'),
        'icon' => 'Catalog',
        'toolTip' => field('nspcomment'),
        'action' => url(
            'redirect.php',
            $reqvars,
            [
                'subject' => 'schema',
                'schema' => field('nspname')
            ]
        ),
        'branch' => url(
            'catalogs.php',
            $reqvars,
            [
                'action' => 'subtree',
                'schema' => field('nspname')
            ]
        ),
    ];

    $misc->printTree($catalogs, $attrs, 'catalogs');

    exit;
}

/**
 * Generate subtree for catalog schema
 * Reuses schema navigation since catalogs contain the same object types
 */
function doSubTree()
{
    $misc = AppContainer::getMisc();

    $tabs = $misc->getNavTabs('schema');

    $items = $misc->adjustTabsForTree($tabs);

    $reqvars = $misc->getRequestVars('schema');

    $attrs = [
        'text' => field('title'),
        'icon' => field('icon'),
        'action' => url(
            field('url'),
            $reqvars,
            field('urlvars', [])
        ),
        'branch' => url(
            field('url'),
            $reqvars,
            field('urlvars'),
            ['action' => 'tree']
        )
    ];

    $misc->printTree($items, $attrs, 'catalog');
    exit;
}

// Main program

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$action = $_REQUEST['action'] ?? '';

if ($action == 'tree')
    doTree();
if ($action == 'subtree')
    doSubTree();

$misc->printHeader($lang['strcatalogs']);
$misc->printBody();

switch ($action) {
    default:
        doDefault();
        break;
}

$misc->printFooter();
