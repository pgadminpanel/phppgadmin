<?php
/**
 * Timezone information page
 *
 * @package PhpPgAdmin
 */
use PhpPgAdmin\Core\AppContainer;
$_ENV["SKIP_DB_CONNECTION"] = '1';
require_once './libraries/bootstrap.php';

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$currentTz = ini_get('date.timezone');
if ($currentTz === '' || $currentTz === false) {
    $currentTz = date_default_timezone_get();
}
$loadedIni = php_ini_loaded_file();
$scannedIni = php_ini_scanned_files();
$now = date('Y-m-d H:i:s');
$phpVer = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$osFamily = PHP_OS_FAMILY;
$isWindows = ($osFamily === 'Windows');
$isBsd     = ($osFamily === 'Darwin' || $osFamily === 'BSD');
$isLinux   = (!$isWindows && !$isBsd);

$misc->printHeader(
    $lang['strtimezone'],
    <<<'CSS'
<style>
    .tz-info pre { position: relative; background: #ebf0b6; max-height: 100px;  overflow: auto; border: 1px solid #d0d7de; border-radius: 6px; padding: 12px 16px;  width: fit-content; max-width: 100%; overflow-x: auto; font-size: 13px; line-height: 1.45; }
    .tz-info pre code { background: none; border: none; padding: 0; font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; color: #24292f; }
    .tz-info code { background: rgb(216, 189, 44); border-radius: 4px; padding: 0.2em 0.4em; margin: 0.2em 0; font-size: 85%; font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; display: inline-block; }
    .tz-info h2 { border-bottom: 1px solid #d0d7de; padding-bottom: 0.3em; margin-top: 1.5em; }
    .tz-info .notice { background: #ebf0b6; width: fit-content; max-width: 100%; border-left: 4px solid #d4a72c; padding: 12px 16px; margin: 1em 0; border-radius: 4px; }
    .tz-info .notice p { margin: 0; color: #000; }
    .tz-info .btn { display: inline-block; padding: 8px 16px; background: #d25c2d; color: #fff; text-decoration: none; border-radius: 4px; }
    .tz-info .btn:hover { background: #d22d2d; }
    .tz-info table.data td, .tz-info table.data th { padding: 4px 8px; }
    .tz-info .pre-wrap { position: relative; width: fit-content; max-width: 100%; margin: 1em 0; }
    .tz-info .pre-wrap .copy-btn { position: absolute; top: 8px; right: 8px; z-index: 10; padding: 4px 12px; font-size: 13px; background: rgba(255, 255, 255, 0.95); border: 1px solid #d0d7de; border-radius: 4px; cursor: pointer; opacity: 0; transition: opacity 0.2s, background 0.2s; }
    .tz-info .pre-wrap:hover .copy-btn { opacity: 1; }
    .tz-info .pre-wrap .copy-btn:hover { background: #f3f4f6; }
    .tz-info .pre-wrap .copy-btn.copied { background: #d4edda; border-color: #28a745; color: #155724; }
</style>
CSS
);

$misc->printBody();
$misc->printTrail('root');
$misc->printTabs('root', 'intro');
?>
<div class="tz-info">
<h1><?= $lang['strtimezone'] ?></h1>
<h2><?= $lang['strtimezoneproblem'] ?></h2>
<p><?= sprintf($lang['strtimezoneproblemdesc'], 'phpPgAdmin', 'PHP', '<code>date()</code>', '<code>php.ini</code>', '<code>date.timezone</code>', 'UTC') ?></p>
<p><?= sprintf($lang['strtimezonefix'], '<strong>php.ini</strong>', '<code>date.timezone</code>', '<code>PHP-FPM</code>', '<code>Apache</code>') ?></p>

<div class="notice">
<p><strong><?= $lang['strnotice'] ?>：</strong><?= sprintf($lang['strtimezonenotice'], '<code>php.ini</code>', '<code>PHP</code>') ?></p>
</div>

<h2><?= $lang['strcurrentstatus'] ?></h2>
<table class="data">
    <thead>
        <tr>
            <th class="data" style="font-size: 16px;"><?= $lang['stritem'] ?></th>
            <th class="data" style="font-size: 16px;"><?= $lang['strvalue'] ?></th>
        </tr>
    </thead>
    <tbody>
        <tr class="data1">
            <td><?= sprintf($lang['striniinivalue'], '<code>php.ini</code>', '<code>date.timezone</code>') ?></td>
            <td><code><?= htmlspecialchars(ini_get('date.timezone') ?: $lang['strnotset']) ?></code></td>
        </tr>
        <tr class="data2">
            <td><?= $lang['strcurrenttzparam'] ?></td>
            <td><code><?= htmlspecialchars(date_default_timezone_get()) ?></code></td>
        </tr>
        <tr class="data2">
            <td><?= $lang['strcurrenttztime'] ?></td>
            <td><code><?= htmlspecialchars($now) ?></code></td>
        </tr>
        <tr class="data1">
            <td><?= $lang['strbrowsertz'] ?></td>
            <td><code id="browserTimezone"><?= $lang['strdetecting'] ?></code></td>
        </tr>
        <tr class="data1">
            <td><?= $lang['strcurrentini'] ?>&nbsp;<code>php.ini</code></td>
            <td><code><?= htmlspecialchars($loadedIni ?: $lang['strnotfound']) ?></code></td>
        </tr>
    </tbody>
</table>

<?php if ($isWindows): ?>
<h2><?= sprintf($lang['strmodifyini'], 'Windows', 'php.ini') ?></h2>
<p><?= sprintf($lang['strcurrentphpini'], 'PHP', '<code>php.ini</code>') ?></p>
<pre><code><?= htmlspecialchars($loadedIni ?: $lang['strnotfound']) ?></code></pre>
<p><?= $lang['strcheckiniexists'] ?></p>
<pre><code>Test-Path "<?= htmlspecialchars($loadedIni ?: '') ?>"</code></pre>
<p><?= sprintf($lang['strviewtzi'], '<code>php.ini</code>') ?></p>
<pre><code>Select-String -Path "<?= htmlspecialchars($loadedIni ?: '') ?>" -Pattern "date.timezone"</code></pre>
<p><?= $lang['strdefaultmaybe'] ?></p>
<pre><code>;date.timezone =</code></pre>
<p><?= $lang['strchangetotz'] ?></p>
<pre><code id="browserTimezoneName"><?= $lang['strdetecting'] ?></code></pre>
<p><?= $lang['strreplacecmd'] ?></p>
<pre><code id="browserTimezoneCmd"><?= $lang['strdetecting'] ?></code></pre>
<p><?= $lang['strverifychange'] ?></p>
<pre><code>Select-String -Path "<?= htmlspecialchars($loadedIni ?: '') ?>" -Pattern "date\.timezone"</code></pre>

<h2><?= $lang['strrestartphp'] ?></h2>
<p><?= $lang['strrestartenv'] ?></p>
<ul>
    <li><strong>Laragon</strong>：<?= $lang['strtrayrightclick'] ?> → Apache/Nginx → Restart；<?= $lang['stror'] ?> Laragon → Stop → Start</li>
    <li><strong>XAMPP</strong>：XAMPP Control Panel → Apache → Stop → Start</li>
    <li><strong>WampServer</strong>：<?= $lang['strtrayrightclick'] ?> → Restart All Services</li>
    <li><strong>IIS + FastCGI</strong>：<?= $lang['strcmdrun'] ?> <code>iisreset</code></li>
    <li><strong>Nginx + PHP-CGI</strong>：<?= sprintf($lang['strnginxrestart'], '<code>php-cgi.exe</code>', '<code>Nginx</code>', '<code>nginx -s reload</code>') ?></li>
</ul>

<?php elseif ($isBsd): ?>
<h2><?= sprintf($lang['strmodifyini'], 'BSD / macOS', 'php.ini') ?></h2>
<p><?= sprintf($lang['strviewtzi'], '<code>php.ini</code>') ?></p>
<pre><code>grep -E '^[[:space:]]*date\.timezone' <?= htmlspecialchars($loadedIni ?: $lang['strnotfound']) ?></code></pre>
<p><?= $lang['strdefaultmaybe'] ?></p>
<pre><code>date.timezone = UTC</code></pre>
<p><?= $lang['strchangetotz'] ?></p>
<pre><code id="browserTimezoneName"><?= $lang['strdetecting'] ?></code></pre>
<p><?= sprintf($lang['strreplacecmdbsd'], 'BSD sed', "<code>-i ''</code>", '<code>[[:space:]]</code>') ?></p>
<pre><code id="browserTimezoneCmd"><?= $lang['strdetecting'] ?></code></pre>
<p><?= sprintf($lang['strtzlist'], 'PHP') ?></p>
<pre><code>https://www.php.net/manual/en/timezones.php</code></pre>

<h2><?= $lang['strrestartphp'] ?></h2>

<p><strong>macOS（Homebrew PHP-FPM）：</strong></p>
<pre><code>brew services restart php</code></pre>

<p><strong>FreeBSD（PHP-FPM）：</strong></p>
<pre><code>service php-fpm restart</code></pre>

<p><strong>macOS（Homebrew Apache + mod_php）：</strong></p>
<pre><code>brew services restart httpd</code></pre>

<p><strong>FreeBSD（Apache + mod_php）：</strong></p>
<pre><code>service apache24 restart</code></pre>

<?php else: ?>
<h2><?= sprintf($lang['strmodifyini'], 'Linux', 'php.ini') ?></h2>
<p><?= sprintf($lang['strviewtzi'], '<code>php.ini</code>') ?></p>
<pre><code>grep -E '^\s*date\.timezone' <?= htmlspecialchars($loadedIni ?: $lang['strnotfound']) ?></code></pre>
<p><?= $lang['strdefaultmaybe'] ?></p>
<pre><code>date.timezone = UTC</code></pre>
<p><?= $lang['strcommented'] ?></p>
<pre><code>;date.timezone =</code></pre>
<p><?= $lang['strchangetotz'] ?></p>
<pre><code id="browserTimezoneName"><?= $lang['strdetecting'] ?></code></pre>
<p><?= $lang['strreplacecmd'] ?></p>
<pre><code id="browserTimezoneCmd"><?= $lang['strdetecting'] ?></code></pre>
<p><?= sprintf($lang['strtzlist'], 'PHP') ?></p>
<pre><code>https://www.php.net/manual/en/timezones.php</code></pre>

<h2><?= $lang['strrestartphp'] ?></h2>
<p><strong>PHP-FPM：</strong></p>
<ul>
    <li><strong>Debian / Ubuntu：</strong>
        <pre><code>systemctl restart php<?= htmlspecialchars($phpVer) ?>-fpm</code></pre>
    </li>
    <li><strong>RHEL / CentOS / Fedora：</strong>
        <pre><code>systemctl restart php-fpm</code></pre>
    </li>
    <li><strong>Arch Linux：</strong>
        <pre><code>systemctl restart php-fpm</code></pre>
    </li>
</ul>

<p><strong>Apache + mod_php：</strong></p>
<ul>
    <li><strong>Debian / Ubuntu：</strong>
        <pre><code>systemctl restart apache2</code></pre>
    </li>
    <li><strong>RHEL / CentOS / Fedora：</strong>
        <pre><code>systemctl restart httpd</code></pre>
    </li>
</ul>
<?php endif; ?>

<h2><?= $lang['strverify'] ?></h2>
<p><?= $lang['strverifydesc'] ?></p>
<p><?= sprintf($lang['strverifycmd'], '<code>php -c</code>', '<code>php.ini</code>') ?></p>
<pre><code>php -c <?= htmlspecialchars($loadedIni ?: $lang['strnotfound']) ?> -r 'echo date_default_timezone_get(), "\n", date("Y-m-d H:i:s"), "\n";'</code></pre>
<p><?= sprintf($lang['strverifycli'], '<code>-c</code>', '<code>php</code>', '<code>php.ini</code>') ?></p>

<h2><?= $lang['strfaq'] ?></h2>
<h3><?= $lang['strstillwrong'] ?></h3>
<ul>
    <li><?= sprintf($lang['strstillwrongdesc1'], '<code>php.ini</code>', '<code>php.ini</code>') ?></li>
    <li><?= sprintf($lang['strstillwrongdesc2'], '<code>PHP-FPM</code>', '<code>Apache</code>') ?></li>
    <li><?= sprintf($lang['strstillwrongdesc3'], '<code>date.timezone</code>') ?></li>
    <li><?= sprintf($lang['strstillwrongdesc4'], '<code>php --ini</code>') ?></li>
</ul>

<h3><?= $lang['strhowtochoosetz'] ?></h3>
<p><?= sprintf($lang['strhowtochoosetzd'], '<code>php.ini</code>', '<code>date.timezone</code>') ?></p>
<p><?= $lang['strviewsystz'] ?></p>

<?php if ($isWindows): ?>
<p><strong>Windows（PowerShell）：</strong></p>
<pre><code>Get-TimeZone</code></pre>
<p><?= $lang['stror'] ?> CMD：</p>
<pre><code>tzutil /g</code></pre>
<?php elseif ($isBsd): ?>
<p><strong>macOS / FreeBSD：</strong></p>
<pre><code>date +%Z</code></pre>
<p><?= $lang['strbsdlocaltime'] ?></p>
<pre><code>ls -l /etc/localtime</code></pre>
<?php else: ?>
<p><strong>Linux：</strong></p>
<pre><code>timedatectl</code></pre>
<?php endif; ?>

<h3><?= sprintf($lang['strnoini'], 'php.ini') ?></h3>
<p><?= sprintf($lang['strnoinidesc'], '<code>date_default_timezone_set(\'<span id="browserTimezoneSetTz">' . $lang['strdetecting'] . '</span>\');</code>', '<code>php.ini</code>') ?></p>
<p><?= sprintf($lang['strfulltextsearchdesc'], '<a class="btn" href="https://pgroonga.github.io" target="_blank" rel="noopener noreferrer">PGroonga</a>') ?></p>
<p><?= sprintf($lang['strtranslationerror'], '<a class="btn" href="' . $lang['strviewfaq_url'] . '" target="_blank" rel="noopener noreferrer">' . $lang['strviewfaq'] . '</a>') ?></p>
<hr>
<p><a href="index.php" class="btn"><?= $lang['strbacktohome'] ?></a></p>
</div>

<script>
(function () {
    var tz = '';
    try {
        tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (e) {}

    var elSet = document.getElementById('browserTimezoneSetTz');
    if (elSet) elSet.textContent = tz || '<?= $lang['strunavailable'] ?>';

    var el0 = document.getElementById('browserTimezone');
    if (el0) el0.textContent = tz || '<?= $lang['strunavailable'] ?>';

    var elName = document.getElementById('browserTimezoneName');
    if (elName) elName.textContent = tz || '<?= $lang['strunavailable'] ?>';

    var iniPath = <?= json_encode($loadedIni ?: '') ?>;
    var platform = <?= json_encode($isWindows ? 'windows' : ($isBsd ? 'bsd' : 'linux')) ?>;

    var elCmd = document.getElementById('browserTimezoneCmd');
    if (!elCmd) return;

    if (!tz || !iniPath) {
        elCmd.textContent = '<?= $lang['strunavailable'] ?>';
        return;
    }

    if (platform === 'windows') {
        elCmd.textContent =
            '$ini = "' + iniPath + '"\n' +
            '(Get-Content $ini) -replace \'^;date\\.timezone\\s*=.*\', \'date.timezone = ' + tz + '\' | Set-Content $ini';
    } else if (platform === 'bsd') {
        elCmd.textContent =
            "sed -i '' -E 's|^[[:space:]]*date\\.timezone[[:space:]]*=.*|date.timezone = " + tz + "|' " + iniPath;
    } else {
        elCmd.textContent =
            "sed -i 's|^\\s*date\\.timezone\\s*=.*|date.timezone = " + tz + "|' " + iniPath;
    }
    document.querySelectorAll('.tz-info pre').forEach(function (pre) {
        var wrap = document.createElement('div');
        wrap.className = 'pre-wrap';
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);
        var btn = document.createElement('button');
        btn.className = 'copy-btn';
        btn.type = 'button';
        btn.textContent = '<?= $lang['strcopy'] ?>';

        btn.addEventListener('click', function () {
            var code = pre.querySelector('code');
            var text = code ? code.innerText : pre.innerText;

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    showCopied(btn);
                }).catch(function () {
                    fallbackCopy(text, btn);
                });
            } else {
                fallbackCopy(text, btn);
            }
        });

        wrap.appendChild(btn);
    });

    function showCopied(btn) {
        var old = btn.textContent;
        btn.textContent = '<?= $lang['strcopied'] ?>';
        btn.classList.add('copied');
        setTimeout(function () {
            btn.textContent = old;
            btn.classList.remove('copied');
        }, 1500);
    }

    function fallbackCopy(text, btn) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showCopied(btn);
        } catch (e) {
            alert('<?= $lang['strcopyfailed'] ?>');
        }
        document.body.removeChild(ta);
    }
})();
</script>
<?php
$misc->printFooter();