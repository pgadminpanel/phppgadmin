<?php
/**
 * Intro screen
 *
 * Shows the login form for unauthenticated users, and the
 * introduction page (language/theme selection + links) for
 * authenticated users.
 *
 * @package PhpPgAdmin
 */
use PhpPgAdmin\Core\AppContainer;

$_ENV["SKIP_DB_CONNECTION"] = '1';
require_once './libraries/bootstrap.php';

[$appLangFiles, $availableLanguages] = require __DIR__ . '/lang/translations.php';
$appThemes = require __DIR__ . '/themes/themes.php';

$appName = AppContainer::getAppName();
$appVersion = AppContainer::getAppVersion();
$lang = AppContainer::getLang();
$misc = AppContainer::getMisc();
$conf = AppContainer::getConf();

$misc->printHeader();
$misc->printBody();
$misc->printTrail('root');
$misc->printTabs('root', 'intro');

$isLoggedIn = isset($_SESSION['webdbLogin']) && !empty($_SESSION['webdbLogin']);
?>
<h1><?php echo "$appName $appVersion (PHP " . phpversion() . ')' ?></h1>
<h2><?php echo $lang['strintro'] ?></h2>
<form method="get" action="intro.php">
    <table>
        <tr class="data1">
            <th class="data"><?php echo $lang['strlanguage'] ?></th>
            <td>
                <select name="language" onchange="this.form.submit()">
<?php
$language = $_SESSION['webdbLanguage'] ?? 'english';
foreach ($appLangFiles as $k => $v) {
    $selected = ($k == $language) ? ' selected="selected"' : '';
    echo "                    <option value=\"{$k}\"{$selected}>{$v}</option>\n";
}
?>
                </select>
            </td>
        </tr>
        <tr class="data2">
            <th class="data"><?php echo $lang['strtheme'] ?></th>
            <td>
                <select name="theme" onchange="this.form.submit()">
<?php
foreach ($appThemes as $k => $langKey) {
    $label = $lang[$langKey] ?? $k;
    $selected = ($k == $conf['theme']) ? ' selected="selected"' : '';
    echo "                    <option value=\"{$k}\"{$selected}>{$label}</option>\n";
}
?>
                </select>
            </td>
        </tr>
    </table>
    <noscript>
        <p><input type="submit" value="<?php echo $lang['stralter'] ?>" /></p>
    </noscript>
</form>

<?php if (!$isLoggedIn): ?>
    <?php
    $msg = $msg ?? '';
    if (isset($_GET['server'])) {
        $server = $_GET['server'];
    } else {
        $firstIdx = array_key_first($conf['servers']);
        $first = $conf['servers'][$firstIdx];
        $server = $first['host'] . ':' . $first['port'] . ':' . $first['sslmode'];
    }
    $_REQUEST['server'] = $server;
    include __DIR__ . '/login-form.php';
    ?>
<?php endif; ?>

<table class="intro-table">
    <tr>
        <td><a class="btn" href="https://github.com/pgadminpanel/phppgadmin" target="_blank" rel="noopener noreferrer"><?= $lang['strppahome'] ?></a></td>
        <td><a class="btn" href="<?= $lang['strpgsqlhome_url'] ?>" target="_blank" rel="noopener noreferrer"><?= $lang['strpgsqlhome'] ?></a></td>
    </tr>
    <tr>
        <td><a class="btn" href="runtime.php"><?= $lang['strsessionconfig'] ?></a></td>
        <td><a class="btn" href="dev-test.php"><?= $lang['strdevelopmentdocs'] ?></a></td>
    </tr>
    <tr>
        <td><a class="btn" href="https://github.com/pgadminpanel/phppgadmin/issues" target="_blank" rel="noopener noreferrer"><?= $lang['strreportbug'] ?></a></td>
        <td><a class="btn" href="<?= $lang['strviewfaq_url'] ?>" target="_blank" rel="noopener noreferrer"><?= $lang['strviewfaq'] ?></a></td>
    </tr>
</table>

<?php
if (isset($_GET['language']))
    AppContainer::setShouldReloadPage(true);

$misc->printFooter();