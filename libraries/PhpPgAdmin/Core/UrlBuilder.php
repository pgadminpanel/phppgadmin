<?php

namespace PhpPgAdmin\Core;

class UrlBuilder extends AppContext
{
    public function getHREF($excludeFrom = null): string
    {
        $href = '';
        if (isset($_REQUEST['server']) && $excludeFrom != 'server') {
            $href .= 'server=' . urlencode($_REQUEST['server']);
            if (isset($_REQUEST['database']) && $excludeFrom != 'database') {
                $href .= '&database=' . urlencode($_REQUEST['database']);
                if (isset($_REQUEST['schema']) && $excludeFrom != 'schema') {
                    $href .= '&schema=' . urlencode($_REQUEST['schema']);
                }
            }
        }
        return htmlentities($href);
    }

    public function buildHiddenFormInputs(): string
    {
        $out = '';
        if (isset($_REQUEST['server'])) {
            $out .= "<input type=\"hidden\" name=\"server\" value=\"" . htmlspecialchars($_REQUEST['server']) . "\" />\n";
            if (isset($_REQUEST['database'])) {
                $out .= "<input type=\"hidden\" name=\"database\" value=\"" . htmlspecialchars($_REQUEST['database']) . "\" />\n";
                if (isset($_REQUEST['schema'])) {
                    $out .= "<input type=\"hidden\" name=\"schema\" value=\"" . htmlspecialchars($_REQUEST['schema']) . "\" />\n";
                }
            }
        }
        return $out;
    }

    public function getSubjectParams($subject)
    {
        $plugin_manager = $this->pluginManager();
        $vars = [];

        switch ($subject) {
            case 'root':
                $vars = ['params' => ['subject' => 'root']];
                break;
            case 'server':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'server'
                    ]
                ];
                break;
            case 'role':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'role',
                        'action' => 'properties',
                        'rolename' => $_REQUEST['rolename']
                    ]
                ];
                break;
            case 'database':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'database',
                        'database' => $_REQUEST['database'],
                    ]
                ];
                break;
            case 'schema':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'schema',
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema']
                    ]
                ];
                break;
            case 'table':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'table',
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table'] ?? '',
                    ]
                ];
                break;
            case 'selectrows':
                $vars = [
                    'url' => 'tables.php',
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'table',
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'table' => $_REQUEST['table'],
                        'action' => 'confselectrows'
                    ]
                ];
                break;
            case 'view':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'view',
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'view' => $_REQUEST['view']
                    ]
                ];
                break;
            case 'fulltext':
            case 'ftscfg':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'fulltext',
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'action' => 'viewconfig',
                        'ftscfg' => $_REQUEST['ftscfg']
                    ]
                ];
                break;
            case 'function':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'function',
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'function' => $_REQUEST['function'],
                        'function_oid' => $_REQUEST['function_oid']
                    ]
                ];
                break;
            case 'aggregate':
                $vars = [
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'aggregate',
                        'action' => 'properties',
                        'database' => $_REQUEST['database'],
                        'schema' => $_REQUEST['schema'],
                        'aggrname' => $_REQUEST['aggrname'],
                        'aggrtype' => $_REQUEST['aggrtype']
                    ]
                ];
                break;
            case 'column':
                if (isset($_REQUEST['table']))
                    $vars = [
                        'params' => [
                            'server' => $_REQUEST['server'],
                            'subject' => 'column',
                            'database' => $_REQUEST['database'],
                            'schema' => $_REQUEST['schema'],
                            'table' => $_REQUEST['table'],
                            'column' => $_REQUEST['column']
                        ]
                    ];
                else
                    $vars = [
                        'params' => [
                            'server' => $_REQUEST['server'],
                            'subject' => 'column',
                            'database' => $_REQUEST['database'],
                            'schema' => $_REQUEST['schema'],
                            'view' => $_REQUEST['view'],
                            'column' => $_REQUEST['column']
                        ]
                    ];
                break;
            case 'plugin':
                $vars = [
                    'url' => 'plugin.php',
                    'params' => [
                        'server' => $_REQUEST['server'],
                        'subject' => 'plugin',
                        'plugin' => $_REQUEST['plugin'],
                    ]
                ];

                if ($plugin_manager && !is_null($plugin_manager->getPlugin($_REQUEST['plugin']))) {
                    $vars['params'] = array_merge($vars['params'], $plugin_manager->getPlugin($_REQUEST['plugin'])->get_subject_params());
                }
                break;
            default:
                return false;
        }

        if (!isset($vars['url']))
            $vars['url'] = 'redirect.php';

        return $vars;
    }

    public function getHREFSubject($subject)
    {
        $vars = $this->getSubjectParams($subject);
        return "{$vars['url']}?" . http_build_query($vars['params'], '', '&amp;');
    }

    /**
     * Returns URL given an action associative array.
     * NOTE: this function does not html-escape, only url-escape
     * @param array $action An associative array of the follow properties:
     *         'url'  => The first part of the URL (before the ?)
     *         'urlvars' => Associative array of (URL variable => field name)
     *                  these are appended to the URL
     * @param array $fields Field data from which 'urlfield' and 'vars' are obtained.
     */
    function getActionUrl($action, $fields)
    {
        $url = value($action['url'], $fields);

        if ($url === false)
            return '';

        if (!empty($action['urlvars'])) {
            $urlvars = value($action['urlvars'], $fields);
        } else {
            $urlvars = [];
        }

        /* set server, database and schema parameter if not presents */
        if (isset($urlvars['subject']))
            $subject = value($urlvars['subject'], $fields);
        else
            $subject = '';

        if (isset($_REQUEST['server']) and !isset($urlvars['server']) and $subject != 'root') {
            $urlvars['server'] = $_REQUEST['server'];
            if (isset($_REQUEST['database']) and !isset($urlvars['database']) and $subject != 'server') {
                $urlvars['database'] = $_REQUEST['database'];
                if (isset($_REQUEST['schema']) and !isset($urlvars['schema']) and $subject != 'database') {
                    $urlvars['schema'] = $_REQUEST['schema'];
                }
            }
        }

        $sep = '?';
        foreach ($urlvars as $var => $varfield) {
            if (is_array($varfield)) {
                // 'orderby' => [ 'id' => 'asc' ]
                $param = http_build_query([$var => $varfield]);
            } else {
                $param = value_url($var, $fields) . '=' . value_url($varfield, $fields);
            }
            if (!empty($param)) {
                $url .= $sep;
                $url .= $param;
                $sep = '&';
            }
        }

        return $url;
    }

    /**
     * @param string $subject
     * @return array
     */
    function getRequestVars($subject = '')
    {
        $v = [];
        if (!empty($subject))
            $v['subject'] = $subject;
        if (isset($_REQUEST['server']) && $subject != 'root') {
            $v['server'] = $_REQUEST['server'];
            if (isset($_REQUEST['database']) && $subject != 'server') {
                $v['database'] = $_REQUEST['database'];
                if (isset($_REQUEST['schema']) && $subject != 'database') {
                    $v['schema'] = $_REQUEST['schema'];
                }
            }
        }
        return $v;
    }

    /**
     * @param array $vars
     * @param array $fields
     */
    function printUrlVars($vars, $fields)
    {
        foreach ($vars as $var => $varfield) {
            echo "{$var}=", urlencode($fields[$varfield] ?? ''), "&amp;";
        }
    }

    private function buildQueryString($vars, $fields): string
    {
        $pairs = [];
        foreach ($vars as $k => $v) {
            $val = value($v, $fields);
            $pairs[] = http_build_query([$k => $val]);
        }
        return implode('&amp;', $pairs);
    }
}
