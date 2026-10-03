<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;

/**
 * Topbar rendering: connection info and quick links
 * Extracts printTopbar() from legacy Misc class
 */
class TopbarRenderer extends AppContext
{
    /**
     * Display the top bar with server connection info and quick links
     */
    public function printTopbar(): void
    {
        $lang = $this->lang();
        $conf = $this->conf();
        $pluginManager = $this->pluginManager();

        $appName = $GLOBALS['appName'] ?? 'phpPgAdmin';
        $appVersion = $GLOBALS['appVersion'] ?? '';

        $server_info = $this->misc()->getServerInfo();
        $reqvars = $this->misc()->getRequestVars('table');

        echo "<div class=\"topbar\"><table style=\"width: 100%\"><tr><td>";

        if ($server_info && isset($server_info['platform']) && isset($server_info['username'])) {
            /* top left information when connected */
            echo sprintf(
                $lang['strtopbar'],
                '<span class="platform">' . htmlspecialchars($server_info['platform']) . '</span>',
                '<span class="host">' . htmlspecialchars((empty($server_info['host'])) ? 'localhost' : $server_info['host']) . '</span>',
                '<span class="port">' . htmlspecialchars($server_info['port']) . '</span>',
                '<span class="username">' . htmlspecialchars($server_info['username']) . '</span>',
                '<a href="timezone.php" target="_blank" class="help" title="' . htmlspecialchars($lang['strhelp']) . '">?</a> <span class="time">' . htmlspecialchars(date($lang['strtimefmt'])) . '</span>',
                '<a href="' . htmlspecialchars($lang['strviewfaq_url']) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($lang['strviewfaq']) . '</a>'
            );

            echo "</td>";

            /* top right information when connected */

            $toplinks = [
                'sql' => [
                    'attr' => [
                        'href' => [
                            'url' => 'sqledit.php',
                            'urlvars' => array_merge($reqvars, [
                                'action' => 'sql'
                            ])
                        ],
                        'target' => "sqledit",
                        'id' => 'toplink_sql',
                    ],
                    'content' => $lang['strsqleditor']
                ],
                'history' => [
                    'attr' => [
                        'href' => [
                            'url' => 'history.php',
                            'urlvars' => array_merge($reqvars, [
                                'action' => 'pophistory'
                            ])
                        ],
                        'id' => 'toplink_history',
                    ],
                    'content' => $lang['strhistory']
                ],
                'find' => [
                    'attr' => [
                        'href' => [
                            'url' => 'sqledit.php',
                            'urlvars' => array_merge($reqvars, [
                                'action' => 'find'
                            ])
                        ],
                        'target' => "sqledit",
                        'id' => 'toplink_find',
                    ],
                    'content' => $lang['strfind']
                ],
                'logout' => [
                    'attr' => [
                        'href' => [
                            'url' => 'servers.php',
                            'urlvars' => [
                                'action' => 'logout',
                                'logoutServer' => "{$server_info['host']}:{$server_info['port']}:{$server_info['sslmode']}"
                            ]
                        ],
                        'id' => 'toplink_logout',
                    ],
                    'content' => $lang['strlogout']
                ]
            ];

            $authType = $server_info['auth_type'] ?? '';
            if ($authType == 'http' || $authType == 'config') {
                unset($toplinks['logout']);
            }

            // Toplink hook's place
            if ($pluginManager) {
                $plugin_functions_parameters = [
                    'toplinks' => &$toplinks
                ];
                $pluginManager->do_hook('toplinks', $plugin_functions_parameters);
            }

            echo "<td style=\"text-align: right\">";
            $this->misc()->printLinksList($toplinks, 'toplink');
            echo "</td>";

            $sql_window_id = htmlentities('sqledit:' . $_REQUEST['server']);
            $history_window_id = htmlentities('history:' . $_REQUEST['server']);

            echo "<script type=\"text/javascript\">
                    $('#toplink_sql').on('click', function() {
                        window.open($(this).attr('href'),'{$sql_window_id}','toolbar=no,width=700,height=500,resizable=yes,scrollbars=yes').focus();
                        return false;
                    });

                    $('#toplink_history').on('click', function() {
                        window.open($(this).attr('href'),'{$history_window_id}','toolbar=no,width=700,height=500,resizable=yes,scrollbars=yes').focus();
                        return false;
                    });

                    $('#toplink_find').on('click', function() {
                        window.open($(this).attr('href'),'{$sql_window_id}','toolbar=no,width=700,height=500,resizable=yes,scrollbars=yes').focus();
                        return false;
                    });
                    ";

            if (isset($_SESSION['sharedUsername'])) {
                printf("
                    $('#toplink_logout').on('click', function() {
                        return confirm('%s');
                    });", str_replace("'", "\'", $lang['strconfdropcred']));
            }

            echo "
            </script>";
        } else {
            echo "<span class=\"appname\">{$appName}</span> <span class=\"version\">{$appVersion}</span></td>";
        }

        echo "</tr></table></div>\n";
    }
}
