<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;

/**
 * Navigation links rendering: action link lists
 * Extracts printNavLinks() from legacy Misc class
 */
class NavLinksRenderer extends AppContext
{
    /**
     * Display the navlinks
     *
     * @param array $navlinks - An array with the the attributes and values that
     * will be shown. See printLinksList for array format.
     * @param string $place - Place where the $navlinks are displayed.
     * Like 'display-browse', where 'display' is the file (display.php)
     * @param array $env - Associative array of defined variables in the scope
     * of the caller. Allows to give some environment details to plugins.
     * and 'browse' is the place inside that code (doBrowse).
     */
    public function printNavLinks($navlinks, $place, $env = []): void
    {
        $pluginManager = $this->pluginManager();

        // Navlinks hook's place
        if ($pluginManager) {
            $plugin_functions_parameters = [
                'navlinks' => &$navlinks,
                'place' => $place,
                'env' => $env
			];
            $pluginManager->do_hook('navlinks', $plugin_functions_parameters);
        }

        if (count($navlinks) > 0) {
            $this->misc()->printLinksList($navlinks, 'navlink');
        }
    }
}
