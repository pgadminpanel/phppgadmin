<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\SchemaActions;

/**
 * Alternative SQL editing window
 *
 * $Id: sqledit.php,v 1.40 2008/01/10 19:37:07 xzilla Exp $
 */

// Include application functions
include_once('./libraries/bootstrap.php');

/**
 * Private function to display server and list of databases
 */
function _printConnection()
{
	$action = $_REQUEST['action'] ?? '';
	$misc = AppContainer::getMisc();

	// The javascript action on the select box reloads the
	// popup whenever the server or database is changed.
	// This ensures that the correct page encoding is used.
	$onchange = "onchange=\"history.replaceState(null, '', 'sqledit.php?action=" .
		urlencode($action) . "&amp;server=' + encodeURI(server.options[server.selectedIndex].value) + '&amp;database=' + encodeURI(database.options[database.selectedIndex].value) + ";

	// The exact URL to reload to is different between SQL and Find mode, however.
	if ($action == 'find') {
		$onchange .= "'&amp;term=' + encodeURI(term.value) + '&amp;filter=' + encodeURI(filter.value) + '&amp;')\"";
	} else {
		$onchange .= "'&amp;query=' + encodeURI(query.value) + '&amp;search_path=' + encodeURI(search_path.value) + (paginate.checked ? '&amp;paginate=on' : '')  + '&amp;')\"";
	}

	$misc->printConnection($onchange);
}

/**
 * Searches for a named database object
 */
function doFind()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$conf = AppContainer::getConf();

	if (!isset($_REQUEST['term']))
		$_REQUEST['term'] = '';
	if (!isset($_REQUEST['filter']))
		$_REQUEST['filter'] = '';

	$misc->printHeader($lang['strfind']);

	// Bring to the front always
	echo "<body id=\"content\" class=\"popup\" onload=\"window.focus();\">\n";

	$misc->printTabs($misc->getNavTabs('popup'), 'find');

	?>
	<form action="database.php" method="get" name="findform" target="detail">
		<?= _printConnection() ?>
		<?php
		// Build filter options array
		$filterOptions = [
			'' => 'strallobjects',
			'SCHEMA' => 'strschemas',
			'TABLE' => 'strtables',
			'VIEW' => 'strviews',
			'SEQUENCE' => 'strsequences',
			'COLUMN' => 'strcolumns',
			'RULE' => 'strrules',
			'INDEX' => 'strindexes',
			'TRIGGER' => 'strtriggers',
			'CONSTRAINT' => 'strconstraints',
			'FUNCTION' => 'strfunctions',
			'DOMAIN' => 'strdomains',
		];

		if ($conf['show_advanced']) {
			$filterOptions['AGGREGATE'] = 'straggregates';
			$filterOptions['TYPE'] = 'strtypes';
			$filterOptions['OPERATOR'] = 'stroperators';
			$filterOptions['OPCLASS'] = 'stropclasses';
			$filterOptions['CONVERSION'] = 'strconversions';
			$filterOptions['LANGUAGE'] = 'strlanguages';
		}
		?>
		<!-- Output list of filters.  This is complex due to all the 'has' and 'conf' feature possibilities -->
		<p>
			<select name="filter">
				<?php foreach ($filterOptions as $value => $langKey): ?>
					<option value="<?= $value; ?>" <?php if ($_REQUEST['filter'] == $value)
						  echo ' selected="selected"'; ?>>
						<?= $lang[$langKey]; ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<input name="term" value="<?= html_esc($_REQUEST['term']); ?>" size="32" maxlength="<?= $pg->_maxNameLen; ?>" />
		</p>
		<p>
			<input type="submit" value="<?= $lang['strfind']; ?>" />
			<input type="hidden" name="action" value="find" />
		</p>
	</form>
	<?php

	// Default focus
	$misc->setFocus('findform.term');
}

/**
 * Allow execution of arbitrary SQL statements on a database
 */
function doDefault()
{
	$pg = AppContainer::getPostgres();
	$misc = AppContainer::getMisc();
	$lang = AppContainer::getLang();
	$conf = AppContainer::getConf();
	$schemaActions = new SchemaActions($pg);

	if (!isset($_SESSION['sqlquery']))
		$_SESSION['sqlquery'] = '';

	$paginate = $_REQUEST['paginate'] ?? '';

	$scripts = <<<EOD
<script type="text/javascript">
	// Adjust form method based on whether the query is read-only
	let adjustPopupSqlFormMethod = function(form) {
		const isValidReadQuery =
			!form.script.value
			&& extractSqlQueries(form.query.value).every(stmt => isReadOnlyQuery(stmt))
			&& form.query.value.length < {$conf['max_get_query_length']};
		if (isValidReadQuery) {
			form.method = 'get';
		} else {
			form.method = 'post';
		}
	};
</script>
EOD;

	$misc->printHeader($lang['strsqleditor'], $scripts);

	// Bring to the front always
	echo "<body id=\"content\" class=\"popup\" onload=\"window.focus();\">\n";

	$misc->printTabs($misc->getNavTabs('popup'), 'sql');

	?>
	<form action="sql.php" onsubmit="adjustPopupSqlFormMethod(this)" method="post" enctype="multipart/form-data"
		target="detail">
		<?php
		_printConnection();
		if (!isset($_REQUEST['search_path']))
			$_REQUEST['search_path'] = implode(',', $schemaActions->getSearchPath());
		?>

		<div class="flex-row my-2">
			<div class="flex-5">
				<label class="flex-7" for="search_path">
					<?php
					$misc->printHelp($lang['strsearchpath'], 'pg.schema.search_path');
					?>:
				</label>
			</div>
			<div class="flex-5">
				<input data-use-in-url="1" type="text" name="search_path" id="search_path" size="50"
					value="<?= html_esc($_REQUEST['search_path']); ?>">
			</div>
		</div>

		<textarea class="sql-editor frame resizable" rows="10" cols="50" data-mode="plpgsql"
			name="query"><?= html_esc($_SESSION['sqlquery']); ?></textarea>

		<?php
		if (ini_get('file_uploads')) {
			// Don't show upload option if max size of uploads is zero
			$max_size = $misc->inisizeToBytes(ini_get('upload_max_filesize'));
			if (is_double($max_size) && $max_size > 0) {
				?>
				<p class="flex-row">
					<input type="hidden" name="MAX_FILE_SIZE" value="<?= $max_size; ?>">
					<label class="flex-5" for="script"><?= $lang['struploadscript']; ?></label>
					<input class="flex-7" id="script" name="script" type="file">
				</p>
				<?php
			}
		}

		?>
		<p class="flex-row">
			<span class="flex-5"><?= $lang['strpaginate']; ?></span>
			<span class="flex-7">
				<input data-use-in-url="t" type="radio" id="paginate-auto" name="paginate" value="" <?php if (empty($paginate))
					echo ' checked="checked"'; ?>> <label
					for="paginate-auto"><?= $lang['strauto']; ?></label>
				&nbsp;
				<input data-use-in-url="t" type="radio" id="paginate-true" name="paginate" value="t" <?php if ($paginate == 't')
					echo ' checked="checked"'; ?>> <label
					for="paginate-true"><?= $lang['stryes']; ?></label>
				&nbsp;
				<input data-use-in-url="t" type="radio" id="paginate-false" name="paginate" value="f" <?php if ($paginate == 'f')
					echo ' checked="checked"'; ?>> <label
					for="paginate-false"><?= $lang['strno']; ?></label>
			</span>
		</p>

		<p>
			<input type="submit" name="execute" accesskey="r" value="<?= $lang['strexecute']; ?>" />
			<input type="reset" accesskey="q" value="<?= $lang['strreset']; ?>" />
		</p>
	</form>
	<?php

	// Default focus
	$misc->setFocus('forms[0].query');
}

$action = $_REQUEST['action'] ?? '';

$misc = AppContainer::getMisc();

switch ($action) {
	case 'find':
		doFind();
		break;
	case 'sql':
	default:
		doDefault();
		break;
}

// Set the name of the window
$misc->setWindowName('sqledit');

$misc->printFooter();
