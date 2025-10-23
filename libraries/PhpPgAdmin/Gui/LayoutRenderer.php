<?php

namespace PhpPgAdmin\Gui;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Misc;

class LayoutRenderer extends AppContext
{
	private $ajaxRequest = false;
	private $frameContentRequest = false;
	private $hasFrameset = false;

	private $misc;

	public function __construct(Misc $misc)
	{
		$this->misc = $misc;
	}

	public function printHeader($title = '', $scripts = '')
	{
		$lang = $this->lang();
		$conf = $this->conf();
		$plugin_manager = $this->pluginManager();
		$appName = AppContainer::getAppName();

		// capture ajax/json requests
		if (!empty($_REQUEST['ajax'])) {
			$this->ajaxRequest = true;
			if ($_REQUEST['ajax'] == 'json') {
				header("Content-Type: application/json; charset=utf-8");
			} else {
				header("Content-Type: text/html; charset=utf-8");
			}
			return;
		}

		$safeTitle = htmlspecialchars($appName . (empty($title) ? '' : " - $title"));

		// skip html frame for inner content links
		if (($_REQUEST['target'] ?? '') == 'content') {
			$this->frameContentRequest = true;
			AppContainer::setSkipHtmlFrame(true);
			// update title through javascript
			echo "<script>\n";
			echo "document.title = \"$safeTitle\";\n";
			echo "</script>\n";
		}

		// just output scripts and return
		if (AppContainer::isSkipHtmlFrame()) {
			echo $scripts;
			return;
		}

		$langIso2 = substr($lang['applocale'], 0, 2);
		$cacheKey = 'v=' . urlencode(AppContainer::getAppVersion());
		$theme = $conf['theme'];
		$appLocale = $lang['applocale'];
		$appLangDir = htmlspecialchars($lang['applangdir']);
		$leftWidth = $conf['left_width'];
		$iconI = $this->misc->icon('I');
		$iconCalendar = $this->misc->icon('Calendar');

		header("Content-Type: text/html; charset=utf-8");
		echo "<!DOCTYPE html>\n";
		echo '<html lang="' . $appLocale . '" dir="' . $appLangDir . '">' . "\n";
		echo "<head>\n";
		echo '    <meta charset="utf-8" />' . "\n";
		echo '    <meta name="viewport" content="width=device-width, initial-scale=1.0" />' . "\n";
		echo '    <link rel="stylesheet" href="js/lib/flatpickr/flatpickr.css?' . $cacheKey . '" type="text/css">' . "\n";
		echo '    <link rel="stylesheet" href="themes/' . $theme . '/global.css?' . $cacheKey . '" type="text/css" id="csstheme">' . "\n";
		echo '    <link rel="icon" type="image/svg+xml" href="images/themes/' . $theme . '/pgadmin.svg?' . $cacheKey . '" />' . "\n";
		echo '    <script src="js/lib/jquery-3.7.1.min.js?' . $cacheKey . '" type="text/javascript"></script>' . "\n";
		echo '    <script src="js/core/xtree2.js?' . $cacheKey . '" type="text/javascript"></script>' . "\n";
		echo '    <script src="js/core/xloadtree2.js?' . $cacheKey . '" type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/popper.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/flatpickr/flatpickr.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";

		if ($langIso2 != 'en') {
			echo '    <script src="js/lib/flatpickr/l10n/' . $langIso2 . '.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		}

		echo '    <script src="js/lib/ace/src-min-noconflict/ace.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/ace/src-min-noconflict/ext-language_tools.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <!--' . "\n";
		echo '    <script src="js/lib/ace/src-min-noconflict/mode-pgsql.js" defer type="text/javascript"></script>' . "\n";
		echo '    -->' . "\n";
		echo '    <script src="js/lib/ace/src-min-noconflict/mode-json.js" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/ace/src-min-noconflict/mode-xml.js" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/core/ace-mode-pgsql.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/core/ace-mode-plpgsql-lite.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/lz-string/lz-string.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/highlight/highlight.min.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/highlight/languages/pgsql.min.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/highlight/languages/json.min.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/lib/highlight/languages/xml.min.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/core/frameset.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/core/misc.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/core/autocomplete-fk.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <script src="js/core/sql-completer.js?' . $cacheKey . '" defer type="text/javascript"></script>' . "\n";
		echo '    <style>' . "\n";
		echo '        .webfx-tree-children {' . "\n";
		echo '            background-image: url("' . $iconI . ' ");' . "\n";
		echo '        }' . "\n";
		echo '        .calendar-icon-bg {' . "\n";
		echo '            background-image: url("' . $iconCalendar . ' ");' . "\n";
		echo '        }' . "\n";
		echo '        #tree {' . "\n";
		echo '            width: ' . $leftWidth . 'px;' . "\n";
		echo '        }' . "\n";
		echo '    </style>' . "\n";
		echo '    <title>' . $safeTitle . '</title>' . "\n";
		echo '    ' . $scripts . "\n";

		$plugins_head = [];
		$_params = ['heads' => &$plugins_head];
		$plugin_manager->do_hook('head', $_params);
		foreach ($plugins_head as $tag) {
			echo '    ' . $tag . "\n";
		}

		echo "</head>\n";
	}

	public function printBody()
	{
		if (AppContainer::isSkipHtmlFrame() || $this->ajaxRequest) {
			return;
		}
		$this->hasFrameset = true;

		echo <<<EOT
<body>
<div id="tooltip" role="tooltip">
    <div id="tooltip-content"></div>
    <div class="arrow" data-popper-arrow></div>
</div>
<div id="loading-indicator">
    <svg class="spinner" viewBox="0 0 66 66" xmlns="http://www.w3.org/2000/svg">
        <circle class="path" fill="none" stroke-width="6" stroke-linecap="round" cx="33" cy="33" r="30"></circle>
    </svg>
</div>
<div id="frameset">
<div id="tree" dir="ltr">
EOT;

		$this->printBrowser();

		echo <<<EOT
</div>
<div id="resizer"></div>
<div id="content-container">
<!-- anything inside #content will be overwritten... -->
<div id="content">
EOT;
	}

	public function printFooter()
	{
		$lang = $this->lang();

		if ($this->ajaxRequest) {
			return;
		}

		if (AppContainer::shouldReloadPage()) {
			echo "<script>\n";
			echo "\twindow.location.href=\"index.php\";\n";
			echo "</script>\n";
		} elseif (AppContainer::shouldReloadTree()) {
			echo "<script>\n";
			echo "\twriteTree();\n";
			echo "</script>\n";
		}

		if ($this->hasFrameset || $this->frameContentRequest) {
			$year = date('Y');
			$appName = AppContainer::getAppName();

			echo "<hr>\n";
			echo "<div class=\"clearfix bottom-footer\">\n";
			echo "<p class=\"copyright\">Copyright &copy; 2001-{$year} {$appName}. Licensed under the GNU GPL v2 or later.</p>\n";
			echo "</div>\n";
		}

		echo "<a href=\"#\" class=\"bottom_link\">⇱</a>";

		if (AppContainer::isSkipHtmlFrame()) {
			return;
		}
		if ($this->hasFrameset) {
			echo "</div>\n"; // close #content div
			echo "</div>\n"; // close #content-container div
			echo "</div>\n"; // close #frameset div
		}
		echo "</body>\n";
		echo "</html>\n";
	}

	public function printBrowser()
	{
		global $appName;
		$lang = $this->lang();
		$appNameEsc = htmlspecialchars($appName);
		$strRefresh = htmlspecialchars($lang['strrefresh']);
		$iconRefresh = $this->misc->icon('Refresh');
		$iconServers = $this->misc->icon('Servers');
		$iconI = $this->misc->icon('I');
		$iconL = $this->misc->icon('L');
		$iconLminus = $this->misc->icon('Lminus');
		$iconLplus = $this->misc->icon('Lplus');
		$iconT = $this->misc->icon('T');
		$iconTminus = $this->misc->icon('Tminus');
		$iconTplus = $this->misc->icon('Tplus');
		$iconBlank = $this->misc->icon('blank');
		$iconLoading = $this->misc->icon('Loading');
		$iconObjectNotFound = $this->misc->icon('ObjectNotFound');
		$strLoading = htmlspecialchars($lang['strloading']);
		$strErrorLoading = htmlspecialchars($lang['strerrorloading']);
		$strClickToReload = htmlspecialchars($lang['strclicktoreload']);
		$strServers = htmlspecialchars($lang['strservers']);
		?>
<div class="logo">
    <a href="index.php">
        <?= $appNameEsc ?>
    </a>
</div>
<div class="refreshTree">
    <a href="#" onclick="writeTree()"><img class="icon" src="<?= $iconRefresh ?>"
            alt="<?= $strRefresh ?>" title="<?= $strRefresh ?>" /></a>
</div>
<div id="wfxt-container"></div>
<script>
    webFXTreeConfig.rootIcon = "<?= $iconServers ?>";
    webFXTreeConfig.openRootIcon = "<?= $iconServers ?>";
    webFXTreeConfig.folderIcon = "";
    webFXTreeConfig.openFolderIcon = "";
    webFXTreeConfig.fileIcon = "";
    webFXTreeConfig.iIcon = "<?= $iconI ?>";
    webFXTreeConfig.lIcon = "<?= $iconL ?>";
    webFXTreeConfig.lMinusIcon = "<?= $iconLminus ?>";
    webFXTreeConfig.lPlusIcon = "<?= $iconLplus ?>";
    webFXTreeConfig.tIcon = "<?= $iconT ?>";
    webFXTreeConfig.tMinusIcon = "<?= $iconTminus ?>";
    webFXTreeConfig.tPlusIcon = "<?= $iconTplus ?>";
    webFXTreeConfig.blankIcon = "<?= $iconBlank ?>";
    webFXTreeConfig.loadingIcon = "<?= $iconLoading ?>";
    webFXTreeConfig.loadingText = "<?= $strLoading ?>";
    webFXTreeConfig.errorIcon = "<?= $iconObjectNotFound ?>";
    webFXTreeConfig.errorLoadingText = "<?= $strErrorLoading ?>";
    webFXTreeConfig.reloadText = "<?= $strClickToReload ?>";

    // Set default target frame:
    //WebFXTreeAbstractNode.prototype.target = 'detail';

    // Disable double click:
    WebFXTreeAbstractNode.prototype._ondblclick = function () { }

    // Show tree XML on double click - for debugging purposes only
    /*
    // UNCOMMENT THIS FOR DEBUGGING (SHOWS THE SOURCE XML)
    WebFXTreeAbstractNode.prototype._ondblclick = function(e){
        var el = e.target || e.srcElement;

        if (this.src != null)
            window.open(this.src, this.target || "_self");
        return false;
    };
    */

    function writeTree() {
        // Note: ID counter no longer reset here - using semantic IDs for stable node identification
        const tree = new WebFXLoadTree(
            "<?= $lang['strservers']; ?>",
            "servers.php?action=tree",
            "servers.php"
        );
        tree.write("wfxt-container");
        tree.setExpanded(true);
    }

    writeTree();
</script>
		<?php
	}

	/**
	 * Display a link
	 * @param array $link An associative array of link parameters to print
	 *     link = array(
	 *       'attr' => array( // list of A tag attribute
	 *          'attrname' => attribute value
	 *          ...
	 *       ),
	 *       'content' => The link text
	 *       'fields' => (optional) the data from which content and attr's values are obtained
	 *     );
	 *   the special attribute 'href' might be a string or an array. If href is an array it
	 *   will be generated by getActionUrl. See getActionUrl comment for array format.
	 */
	function printLink($link)
	{
		if (!isset($link['fields']))
			$link['fields'] = $_REQUEST;

		$tag = "<a ";
		foreach ($link['attr'] as $attr => $value) {
			if ($attr == 'href' and is_array($value)) {
				$tag .= 'href="' . htmlentities($this->misc->getActionUrl($value, $link['fields'])) . '" ';
			} else {
				$tag .= htmlentities($attr) . '="' . value($value, $link['fields'], 'html') . '" ';
			}
		}
		$tag .= ">";
		if (!empty($link['icon'])) {
			$tag .= "<img class=\"icon\" src=\"{$link['icon']}\" alt=\"" . htmlspecialchars($link['content'] ?? '') . "\">";
		}
		if (!empty($link['content'])) {
			$tag .= value($link['content'], $link['fields'], 'html');
		}
		$tag .= "</a>\n";
		echo $tag;
		//var_dump($link['content']);
		//throw new Exception("");
	}

	/**
	 * Display a list of links
	 * @param $links An associative array of links to print. See printLink function for
	 *               the links array format.
	 * @param $class An optional class or list of classes seprated by a space
	 *   WARNING: This field is NOT escaped! No user should be able to inject something here, use with care.
	 */
	function printLinksList($links, $class = '')
	{
		echo "<ul class=\"{$class}\">\n";
		foreach ($links as $link) {
			echo "\t<li>";
			$this->printLink($link);
			echo "</li>\n";
		}
		echo "</ul>\n";
	}

	/**
	 * Print out the page heading and help link
	 * @param string $title Title, already escaped
	 * @param string $help (optional) The identifier for the help link
	 */
	function printTitle($title, $help = null)
	{
		echo "<h2>";
		$this->printHelp($title, $help);
		echo "</h2>\n";
	}

	/**
	 * Displays link to the context help.
	 * @param string $str - the string that the context help is related to (already escaped)
	 * @param string $help - help section identifier
	 */
	function printHelp($str, $help)
	{
		$lang = $this->lang();

		echo $str;
		if ($help) {
			echo "<a target=\"_blank\" class=\"help\" href=\"";
			echo htmlspecialchars("help.php?help=" . urlencode($help) . "&server=" . urlencode($_REQUEST['server']));
			echo "\" title=\"{$lang['strhelp']}\" target=\"phppgadminhelp\">{$lang['strhelpicon']}</a>";
		}
	}

	/**
	 * Do multi-page navigation.  Displays the prev, next and page options.
	 * @param int $page - the page currently viewed
	 * @param int $pages - the maximum number of pages
	 * @param array $gets -  the parameters to include in the link to the wanted page
	 * @param string $script - (optional) the script to link to (default current script)
	 */
	function printPageNavigation($page, $pages, $gets, $script = '')
	{
		$conf = $this->conf();
		$lang = $this->lang();
		static $limits = null;
		if (!isset($limits)) {
			$limits = [10, 50, 100, 250, 500, 1000, $conf['max_rows']];
			sort($limits, SORT_NUMERIC);
			$limits[] = 0; // 0 means "all rows"
		}
		$window = 3;

		if ($page < 1 || $page > $pages)
			return;
		if ($pages < 1)
			return;

		unset($gets['page']);
		$url = http_build_query($gets);

		echo "<div class=\"pagenav-container\">\n";

		echo "<form method=\"get\" action=\"$script?$url\" class=\"pagenav-form mr-3\">";
		echo "<span class=\"me-1\">{$lang['strjumppage']}</span>\n";
		echo "<input type=\"number\" class=\"page\" name=\"page\" min=\"1\" max=\"$pages\" value=\"$page\">\n";
		echo "<button type=\"submit\" class=\"psm\">↩</button>\n";
		echo "</form>\n";

		$class = ($page > 1) ? "pagenav psm" : "pagenav psm disabled";
		echo "<a class=\"$class\" href=\"$script?{$url}&page=" . max(1, $page - 1) . "\">⮜</a>\n";

		echo "<a class=\"pagenav" . ($page == 1 ? " current" : "") . "\" href=\"$script?{$url}&page=1\">1</a>\n";

		$min_page = $page - $window;
		$max_page = $page + $window;

		if ($min_page < 2) {
			$shift = 2 - $min_page;
			$min_page = 2;
			$max_page = min($pages - 1, $max_page + $shift);
		}

		if ($max_page > $pages - 1) {
			$shift = $max_page - ($pages - 1);
			$max_page = $pages - 1;
			$min_page = max(2, $min_page - $shift);
		}

		if ($min_page > 2) {
			echo "<span class=\"ellipsis\">…</span>\n";
		}

		for ($i = $min_page; $i <= $max_page; $i++) {
			$class = ($i == $page) ? "pagenav current" : "pagenav";
			echo "<a class=\"$class\" href=\"$script?{$url}&page={$i}\">$i</a>\n";
		}

		if ($max_page < $pages - 1) {
			echo "<span class=\"ellipsis\">…</span>\n";
		}

		if ($pages > 1) {
			echo "<a class=\"pagenav" . ($page == $pages ? " current" : "") . "\" href=\"$script?{$url}&page={$pages}\">$pages</a>\n";
		}

		$class = ($page < $pages) ? "pagenav psm" : "pagenav psm disabled";
		echo "<a class=\"$class\" href=\"$script?{$url}&page=" . ($page + 1) . "\">⮞</a>\n";

		$query_params = $gets;
		unset($query_params['max_rows']);
		$sub_url = http_build_query($query_params);
		echo "<form method=\"get\" action=\"$script?$sub_url\" class=\"pagenav-form ml-2 mr-1\">";
		echo "<span class=\"me-1\">{$lang['strselectmaxrows']}</span>\n";
		echo "<select name=\"max_rows\" class=\"max_rows\" onchange=\"this.form.querySelector('button[type=submit]').click()\">\n";
		foreach ($limits as $limit) {
			$selected = ($limit == $gets['max_rows']) ? ' selected' : '';
			$name = $limit <= 0 ? $lang['strall'] : $limit;
			echo "<option value=\"$limit\"{$selected}>$name</option>\n";
		}
		echo "</select>\n";
		echo "<button type=\"submit\" style=\"display:none\">&nbsp;</button>\n";
		echo "</form>\n";

		echo "</div>\n";
	}

	/**
	 * Render a value into HTML using formatting rules specified
	 * by a type name and parameters.
	 *
	 * @param string|null $str The string to change
	 *
	 * @param string $type Field type (optional), this may be an internal PostgreSQL type, or:
	 *         yesno    - same as bool, but renders as 'Yes' or 'No'.
	 *         pre      - render in a <pre> block.
	 *         nbsp     - replace all spaces with &nbsp;'s
	 *         verbatim - render exactly as supplied, no escaping what-so-ever.
	 *         callback - render using a callback function supplied in the 'function' param.
	 *
	 * @param array $params Type parameters (optional), known parameters:
	 *         null     - string to display if $str is null, or set to TRUE to use a default 'NULL' string,
	 *                    otherwise nothing is rendered.
	 *         clip     - if true, clip the value to a fixed length, and append an ellipsis...
	 *         cliplen  - the maximum length when clip is enabled (defaults to $conf['max_chars'])
	 *         ellipsis - the string to append to a clipped value (defaults to $lang['strellipsis'])
	 *         tag      - an HTML element name to surround the value.
	 *         class    - a class attribute to apply to any surrounding HTML element.
	 *         align    - an align attribute ('left','right','center' etc.)
	 *         true     - (type='bool') the representation of true.
	 *         false    - (type='bool') the representation of false.
	 *         function - (type='callback') a function name, accepts args ($str, $params) and returns a rendering.
	 *         lineno   - prefix each line with a line number.
	 *         map      - an associative array.
	 *
	 * @return string The HTML rendered value
	 */
	function formatVal($str, $type = null, $params = [])
	{
		$lang = $this->lang();
		$conf = $this->conf();

		// Shortcircuit for a NULL value
		if ($str === null) {
			if (isset($params['null'])) {
				if ($params['null'] === true) {
					return '<i class="null">NULL</i>';
				} else {
					return $params['null'];
				}
			}
			return '';
		}

		if (isset($params['map']) && isset($params['map'][$str]))
			$str = $params['map'][$str];

		// Clip the value if the 'clip' parameter is true.
		if (isset($params['clip']) && $params['clip'] === true) {
			$maxlen = isset($params['cliplen']) && is_integer($params['cliplen']) ? $params['cliplen'] : $conf['max_chars'];
			$ellipsis = $params['ellipsis'] ?? $lang['strellipsis'];
			if (mb_strlen($str, 'UTF-8') > $maxlen) {
				$str = mb_substr($str, 0, $maxlen - 1, 'UTF-8') . $ellipsis;
			}
		}

		$out = '';
		$class = "field";
		if (!empty($type))
			$class .= " {$type}";

		switch ($type) {
			case 'int2':
			case 'int4':
			case 'int8':
			case 'float4':
			case 'float8':
			case 'money':
			case 'numeric':
			case 'decimal':
			case 'oid':
			case 'xid':
			case 'cid':
			case 'tid':
				$align = 'right';
				$out = nl2br(htmlspecialchars($str));
				break;
			case 'yesno':
				if (!isset($params['true']))
					$params['true'] = $lang['stryes'];
				if (!isset($params['false']))
					$params['false'] = $lang['strno'];
			// No break - fall through to boolean case.
			case 'bool':
			case 'boolean':
				if (is_bool($str))
					$str = $str ? 't' : 'f';
				switch ($str) {
					case 't':
						$out = ($params['true'] ?? $lang['strtrue']);
						$align = 'center';
						break;
					case 'f':
						$out = ($params['false'] ?? $lang['strfalse']);
						$align = 'center';
						break;
					default:
						$out = htmlspecialchars($str);
				}
				break;
			case 'bytea':
				$tag = 'pre';
				$out = '\x' . strtoupper(bin2hex($str));
				break;
			case 'errormsg':
				$tag = 'pre';
				$class .= ' error';
				$out = htmlspecialchars($str);
				break;
			case 'pre':
				$tag = 'pre';
				$out = htmlspecialchars($str);
				break;
			case 'prenoescape':
				$tag = 'pre';
				$out = $str;
				break;
			case 'json':
			case 'jsonb':
				$tag = 'pre';
				$class .= ' sql-viewer';
				$attr = 'data-language="json"';
				$out = htmlspecialchars($str);
				break;
			case 'xml':
				$tag = 'pre';
				$class .= ' sql-viewer';
				$attr = 'data-language="xml"';
				$out = htmlspecialchars($str);
				break;
			case 'sql':
			case 'plpgsql':
				$tag = 'pre';
				$class .= ' sql-viewer';
				$attr = 'data-language="pgsql"';
				$out = htmlspecialchars($str);
				break;
			case 'nbsp':
				$out = nl2br(str_replace(' ', '&nbsp;', htmlspecialchars($str)));
				break;
			case 'verbatim':
				$out = $str;
				break;
			case 'callback':
				$out = $params['function']($str, $params);
				break;
			case 'prettyint':
				$align = 'right';
				$out = number_format(
					$str,
					0,
					$lang['strdecimalsep'],
					$lang['strthousandssep']
				);
				break;
			case 'prettysize':
				if ($str == -1) {
					$out = $lang['strnoaccess'];
					break;
				}
				$units = [
					$lang['strbytes'],
					$lang['strkb'],
					$lang['strmb'],
					$lang['strgb'],
					$lang['strtb'],
				];
				$limit = 10 * 1024;
				$mult = 1;
				$idx = 0;
				while ($idx < count($units) - 1 && $str >= $limit * $mult) {
					$mult *= 1024;
					$idx++;
				}
				$out = floor(((float) $str + $mult / 2) / $mult) . ' ' . $units[$idx];
				break;
			case 'html':
				$out = $str;
				break;
			case 'time':
			case 'timetz':
			case 'timestamp':
			case 'timestamptz':
			case 'date':
				$class .= ' highlight-datetime';
			default:
				$out = nl2br(htmlspecialchars($str));
		}

		if (isset($params['class'])) {
			$class .= ' ' . $params['class'];
		}
		if (isset($params['align']))
			$align = $params['align'];

		if (!isset($tag) || isset($params['tag'])) {
			$tag = $params['tag'] ?? (isset($align) ? 'div' : 'span');
		}

		if (isset($tag)) {
			$attr = isset($attr) ? " $attr" : '';
			$alignattr = isset($align) ? " style=\"text-align: {$align}\"" : '';
			$classattr = isset($class) ? " class=\"{$class}\"" : '';
			$out = "<{$tag}{$alignattr}{$classattr}{$attr}>{$out}</{$tag}>";
		}

		// Add line numbers if 'lineno' param is true
		// Still in use?
		/*
		if (isset($params['lineno']) && $params['lineno'] === true) {
			$lines = explode("\n", $str);
			$num = count($lines);
			if ($num > 0) {
				$temp = "<table>\n<tr><td class=\"{$class}\" style=\"vertical-align: top; padding-right: 10px;\"><pre class=\"{$class}\">";
				for ($i = 1; $i <= $num; $i++) {
					$temp .= $i . "\n";
				}
				$temp .= "</pre></td><td class=\"{$class}\" style=\"vertical-align: top;\">{$out}</td></tr></table>\n";
				$out = $temp;
			}
			unset($lines);
		}
		*/

		return $out;
	}
}