<?php
/**
 * Runtime configuration page
 *
 * @package PhpPgAdmin
 */
use PhpPgAdmin\Core\AppContainer;
$_ENV["SKIP_DB_CONNECTION"] = '1';
require_once './libraries/bootstrap.php';

$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();

$osFamily = PHP_OS_FAMILY;
$isWindows = ($osFamily === 'Windows');
$isBsd     = ($osFamily === 'Darwin' || $osFamily === 'BSD');
$isLinux   = (!$isWindows && !$isBsd);
$domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$sessionPath = ini_get('session.save_path');
$displayPath = $sessionPath ?: $lang['strnotset'];
$sessionPath = $sessionPath ?: sys_get_temp_dir();
$gcProbability = ini_get('session.gc_probability');
$gcDivisor = ini_get('session.gc_divisor');
$gcMaxLifetime = ini_get('session.gc_maxlifetime');
$loadedIni = php_ini_loaded_file();

$misc->printHeader(
    $lang['strruntimeconfig'],
    <<<'CSS'
<style>
    .rt-info pre { position: relative; background: #ebf0b6; max-height: 100px;  overflow: auto; border: 1px solid #d0d7de; border-radius: 6px; padding: 12px 16px;  width: fit-content; max-width: 100%; overflow-x: auto; font-size: 13px; line-height: 1.45; }
    .rt-info pre code { background: none; border: none; padding: 0; font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; color: #24292f; }
    .rt-info code { background: rgb(216, 189, 44); border-radius: 4px; padding: 0.2em 0.4em; margin: 0.2em 0; font-size: 85%; font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; display: inline-block; }
    .rt-info h2 { border-bottom: 1px solid #d0d7de; padding-bottom: 0.3em; margin-top: 1.5em; }
    .rt-info .notice { background: #ebf0b6; width: fit-content; max-width: 100%; border-left: 4px solid #d4a72c; padding: 12px 16px; margin: 1em 0; border-radius: 4px; }
    .rt-info .notice p { margin: 0; color: #000; }
    .rt-info .btn { display: inline-block; padding: 8px 16px; background: #d25c2d; color: #fff; text-decoration: none; border-radius: 4px; }
    .rt-info .btn:hover { background: #d22d2d; }
    .rt-info table.data td, .rt-info table.data th { padding: 4px 8px; }
    .rt-info .pre-wrap { position: relative; width: fit-content; max-width: 100%; margin: 1em 0; }
    .rt-info .pre-wrap .copy-btn { position: absolute; top: 8px; right: 8px; z-index: 10; padding: 4px 12px; font-size: 13px; background: rgba(255, 255, 255, 0.95); border: 1px solid #d0d7de; border-radius: 4px; cursor: pointer; opacity: 0; transition: opacity 0.2s, background 0.2s; }
    .rt-info .pre-wrap:hover .copy-btn { opacity: 1; }
    .rt-info .pre-wrap .copy-btn:hover { background: #f3f4f6; }
    .rt-info .pre-wrap .copy-btn.copied { background: #d4edda; border-color: #28a745; color: #155724; }
    .warn { display: inline-block; color: #b84a20; background: #fff4ee; border-left: 4px solid #d25c2d; padding: 8px 12px; }
</style>
CSS
);

$misc->printBody();
$misc->printTrail('root');
$misc->printTabs('root', 'intro');
?>
<div class="rt-info">
<h1><?= sprintf($lang['strruntimeconfigtitle'], '<code>Session</code>') ?></h1>

<h2><?= $lang['strsessionconfig'] ?></h2>
<p><?= $lang['strsessionintro'] ?></p>
<ul>
    <li><span style="color: #1a518c; cursor: pointer;" onclick="document.getElementById('session-config').scrollIntoView({behavior: 'smooth'});"><?= $lang['strsessionpart1'] ?></span>：<?= $lang['strsessionpart1desc'] ?></li>
    <li><span style="color: #1a518c; cursor: pointer;" onclick="document.getElementById('static-cache').scrollIntoView({behavior: 'smooth'});"><?= $lang['strsessionpart2'] ?></span>：<?= $lang['strsessionpart2desc'] ?></li>
    <li><span style="color: #1a518c; cursor: pointer;" onclick="document.getElementById('encrypt-auth').scrollIntoView({behavior: 'smooth'});"><?= $lang['strsessionpart3'] ?></span>：<?= sprintf($lang['strsessionpart3desc'], '<code>bin/encrypt-password.php</code>', '<code>auth_type = \'config\'</code>') ?></li>
</ul>

<div class="notice">
<p><strong><?= $lang['strnotice'] ?>：</strong><?= sprintf($lang['strnoticemodifyini'], '<code>php.ini</code>') ?></p>
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
            <td><?= sprintf($lang['strsessionpath'], '<code>session.save_path</code>') ?></td>
            <td><code><?= htmlspecialchars($displayPath) ?></code></td>
        </tr>
        <tr class="data2">
            <td><?= sprintf($lang['strgcprobability'], '<code>session.gc_probability</code>') ?></td>
            <td><code><?= htmlspecialchars($gcProbability) ?></code></td>
        </tr>
        <tr class="data1">
            <td><?= sprintf($lang['strgcdivisor'], '<code>session.gc_divisor</code>') ?></td>
            <td><code><?= htmlspecialchars($gcDivisor) ?></code></td>
        </tr>
        <tr class="data2">
            <td><?= sprintf($lang['strgcmaxlifetime'], '<code>session.gc_maxlifetime</code>') ?></td>
            <td><code><?= htmlspecialchars($gcMaxLifetime) ?> <?= $lang['strseconds'] ?></code></td>
        </tr>
        <tr class="data1">
            <td><?= sprintf($lang['strcurrentini1'], '<code>php.ini</code>') ?></td>
            <td><code><?= htmlspecialchars($loadedIni ?: $lang['strnotfound']) ?></code></td>
        </tr>
    </tbody>
</table>

<h2 id="session-config"><?= $lang['strsessionconfig1'] ?></h2>
<h3><?= sprintf($lang['strviewsessionpath'], '<code>session.save_path</code>') ?></h3>
<pre><code>php -i | grep session.save_path</code></pre>
<p><?= $lang['strsessionpathdesc'] ?></p>
<ul>
    <li><code>session.save_path =&gt; /var/lib/php/sessions =&gt; /var/lib/php/sessions</code></li>
    <li><code>session.save_path =&gt; C:/laragon/tmp =&gt; C:/laragon/tmp</code></li>
    <li><code>session.save_path =&gt; /tmp =&gt; /tmp</code></li>
    <li><code>session.save_path =&gt; no value =&gt; no value</code></li>
</ul>
<p><?= sprintf($lang['strnovaluedesc'], '<code>no value</code>', '<code>php.ini</code>', '<code>session.save_path</code>') ?></p>
<h3><?= sprintf($lang['strviewinifile'], '<code>php.ini</code>') ?></h3>
<pre><code>php --ini</code></pre>

<p><?= $lang['strinikeyinfo'] ?></p>
<pre><code>Configuration File (php.ini) Path: "/usr/local/etc"
Loaded Configuration File:         (none)</code></pre>

<ul>
    <li><?= sprintf($lang['strinipathdesc'], '<code>Configuration File (php.ini) Path</code>', '<code>php.ini</code>') ?></li>
    <li><?= sprintf($lang['striniloadeddesc'], '<code>Loaded Configuration File</code>', '<code>php.ini</code>', '<code>(none)</code>') ?></li>
</ul>

<p><?= sprintf($lang['strininone'], '<code>(none)</code>') ?></p>

<?php if ($isWindows): ?>
<p><strong>Windows（PowerShell）：</strong></p>
<pre><code>Get-ChildItem -Path C:\ -Filter "php.ini*" -Recurse -ErrorAction SilentlyContinue</code></pre>
<p><?= sprintf($lang['strwininidir'], '<code>php.ini</code>', '<code>C:\laragon\bin\php\php-8.5.10-nts-Win32-vs17-x64\</code>') ?></p>
<?php else: ?>
<p><strong>Linux / macOS / FreeBSD：</strong></p>
<pre><code>find /usr/local/etc -name "php.ini*" 2>/dev/null</code></pre>
<p><?= sprintf($lang['strininidir'], '<code>/usr/local/etc</code>', '<code>Configuration File (php.ini) Path</code>') ?></p>
<ul>
    <li>FreeBSD：<code>/usr/local/etc</code></li>
    <li>Debian / Ubuntu：<code>/etc/php/</code></li>
    <li>RHEL / CentOS：<code>/etc/</code></li>
</ul>
<?php endif; ?>

<p><?= $lang['striniexample'] ?></p>
<pre><code>/usr/local/etc/php.ini-production
/usr/local/etc/php.ini-development</code></pre>

<p><?= $lang['striniwhichfile'] ?></p>
<ul>
    <li><?= sprintf($lang['striniproduction'], '<code>php.ini-production</code>') ?></li>
    <li><?= sprintf($lang['strinidevelopment'], '<code>php.ini-development</code>') ?></li>
</ul>

<p><?= sprintf($lang['strinirecommend'], '<code>php.ini-production</code>') ?></p>

<?php if ($isWindows): ?>
<p><strong>Windows（PowerShell）：</strong></p>
<pre><code>Copy-Item "C:\laragon\bin\php\php-8.5.10-nts-Win32-vs17-x64\php.ini-production" "C:\laragon\bin\php\php-8.5.10-nts-Win32-vs17-x64\php.ini"</code></pre>
<?php else: ?>
<pre><code>cp /usr/local/etc/php.ini-production /usr/local/etc/php.ini</code></pre>
<?php endif; ?>

<p><?= $lang['striniconfirm'] ?></p>
<pre><code>php --ini</code></pre>
<p><?= $lang['strinishouldshow'] ?></p>
<pre><code>Loaded Configuration File: /usr/local/etc/php.ini</code></pre>
<h3><?= sprintf($lang['strconfigsessionpath'], '<code>session.save_path</code>') ?></h3>
<p><?= sprintf($lang['strfindinikey'], '<code>php.ini</code>', '<code>session.save_path</code>') ?></p>

<?php if ($isWindows): ?>
<p><strong>Windows（PowerShell）：</strong></p>
<pre><code>Select-String -Path "<?= htmlspecialchars($loadedIni) ?>" -Pattern "session\.save_path"</code></pre>
<?php else: ?>
<pre><code>grep -nE '^[[:space:]]*;?[[:space:]]*session\.save_path[[:space:]]*=' <?= htmlspecialchars($loadedIni) ?></code></pre>
<?php endif; ?>

<p><?= $lang['stroutputexample'] ?></p>
<pre><code>1304:;session.save_path = "/tmp"</code></pre>
<p><?= $lang['strcreatedir'] ?></p>

<?php if ($isWindows): ?>
<p><strong>Windows（PowerShell）：</strong></p>
<pre><code>New-Item -ItemType Directory -Force -Path "C:\php\sessions"</code></pre>
<?php else: ?>
<pre><code>mkdir -p /var/lib/php/sessions</code></pre>
<?php endif; ?>

<p><?= sprintf($lang['struncomment'], '<code>1304</code>') ?></p>
<pre><code>session.save_path = "/var/lib/php/sessions"</code></pre>
<p><?= sprintf($lang['strpathforref'], '<code>session.save_path</code>') ?></p>
<p><?= $lang['strreplacecmd'] ?></p>

<?php if ($isWindows): ?>
<p><strong>Windows（PowerShell）：</strong></p>
<pre><code>$ini = "<?= htmlspecialchars($loadedIni) ?>"
(Get-Content $ini) -replace '^;*session\.save_path\s*=.*', 'session.save_path = "C:/php/sessions"' | Set-Content $ini</code></pre>
<?php else: ?>
<p><strong>Linux（GNU sed）：</strong></p>
<pre><code>sed -i 's|^\s*;*\s*session\.save_path\s*=.*|session.save_path = "<?= htmlspecialchars($displayPath) ?>"|' <?= htmlspecialchars($loadedIni) ?></code></pre>

<p><strong>macOS / FreeBSD（BSD sed）：</strong></p>
<pre><code>sed -i '' -E 's|^[[:space:]]*;*[[:space:]]*session\.save_path[[:space:]]*=.*|session.save_path = "<?= htmlspecialchars($displayPath) ?>"|' <?= htmlspecialchars($loadedIni) ?></code></pre>
<?php endif; ?>

<p><?= sprintf($lang['strrestartref'], '<span style="color: #1a518c; cursor: pointer;" onclick="document.getElementById(\'restart-php\').scrollIntoView({behavior: \'smooth\'});">' . $lang['strrestartphpsection'] . '</span>') ?></p>
<h3><?= sprintf($lang['strviewsessiondir'], '<code>session</code>') ?></h3>

<?php if ($isWindows): ?>
<pre><code>Get-ChildItem "<?= htmlspecialchars($displayPath) ?>"</code></pre>
<?php else: ?>
<pre><code>ls -la <?= htmlspecialchars($displayPath) ?></code></pre>
<?php endif; ?>

<p><?= sprintf($lang['strsessfileprefix'], '<code>sess_</code>', '<code>session_id</code>') ?></p>
<p><?= $lang['stroutputexample'] ?></p>
<pre><code>sess_a59998888e2a5c36c2ca55127b54ad1a</code></pre>

<h3><?= sprintf($lang['strviewsessfile'], '<code>session</code>') ?></h3>
<p><?= sprintf($lang['strreplacesessid'], '<code>mysession_id</code>', '<code>session_id</code>') ?></p>
<p><?= $lang['strsessidexample'] ?></p>
<pre><code>$id = "a59998888e2a5c36c2ca55127b54ad1a";</code></pre>
<pre><code>php -d session.save_path="<?= htmlspecialchars($displayPath) ?>" -r '
$id = "mysession_id";
$f = "<?= htmlspecialchars($displayPath) ?>/sess_$id";
echo file_get_contents($f);
'</code></pre>
<p><strong><?= $lang['strattention'] ?>：</strong><?= $lang['strsessfilenotice'] ?></p>

<h2><?= sprintf($lang['strsessiongc'], 'Session', 'GC') ?></h2>
<p><?= sprintf($lang['strgcd'], '<code>session</code>', '<code>session.gc_probability</code>', '<code>session.gc_divisor</code>') ?></p>
<pre><code>php -i | grep -E "session.gc_probability|session.gc_divisor|session.gc_maxlifetime"</code></pre>

<p><?= $lang['strgcdefault'] ?></p>
<pre><code>session.gc_divisor => 1000 => 1000
session.gc_maxlifetime => 1440 => 1440
session.gc_probability => 1 => 1</code></pre>
<p><?= sprintf($lang['strgcddesc'], 'Debian / Ubuntu', '<code>gc_probability = 0</code>', '<code>gc_divisor = 1000</code>') ?></p>

<p><strong><?= sprintf($lang['strgcifzero'], '<code>0</code>') ?></strong></p>
<pre><code>session.gc_probability = 0</code></pre>
<p><?= sprintf($lang['strgczerodesc'], '<code>systemd-tmpfiles</code>', '<code>cron</code>') ?></p>

<div class="notice">
<p><strong><?= $lang['strnotice'] ?>：</strong><?= sprintf($lang['strgcdebiandesc'], 'Debian / Ubuntu', '<code>session.gc_probability = 0</code>', '<code>php-common</code>', '<code>phpsessionclean.timer</code>') ?></p>
</div>

<h3><?= sprintf($lang['strsessionlifetime'], 'Session') ?></h3>
<p><?= sprintf($lang['strgclifetimedesc'], '<code>session.gc_maxlifetime</code>') ?></p>
<pre><code>session.gc_maxlifetime = <?= htmlspecialchars($gcMaxLifetime) ?></code></pre>
<p><?= sprintf($lang['strgclifetimeexample'], htmlspecialchars($gcMaxLifetime), '1440', '24') ?></p>

<p><?= $lang['strgclifetimeadjust'] ?></p>
<pre><code>session.gc_maxlifetime = 3600</code></pre>

<h3><?= sprintf($lang['stradodbcache'], 'ADOdb') ?></h3>
<p><?= sprintf($lang['stradodbcachedesc'], '<code>$ADODB_CACHE_DIR</code>', '<code>/tmp</code>') ?></p>

<p><?= sprintf($lang['stradodbcachevar'], '<code>php.ini</code>', '<code>conf/config.inc.php</code>') ?></p>
<pre><code>$ADODB_CACHE_DIR = '/var/cache/phppgadmin';</code></pre>
<p><?= $lang['stradodbcachedefault'] ?></p>
<hr>
<h2 id="static-cache"><?= $lang['strstaticcache'] ?></h2>
<p><?= $lang['strstaticcachedesc'] ?></p>

<h3><?= $lang['strhowtojudge'] ?></h3>
<p><?= $lang['strjudgerun'] ?></p>
<pre><code>apache2ctl -M 2>/dev/null | grep -E "php|proxy_fcgi"</code></pre>

<p><strong><?= $lang['strjudgeresult'] ?></strong></p>
<ul>
    <li><?= sprintf($lang['strjudgemodphp'], '<code>php_module</code>', '<code>php5_module</code>', '<code>php8_module</code>', '<strong>' . $lang['strjudgewaya'] . '</strong>') ?></li>
    <li><?= sprintf($lang['strjudgefpm'], '<code>proxy_fcgi_module</code>', '<strong>' . $lang['strjudgewayb'] . '</strong>') ?></li>
</ul>

<p><?= $lang['strjudgefpmservice'] ?></p>
<pre><code>systemctl status php*-fpm</code></pre>
<ul>
    <li><?= sprintf($lang['strjudgefpmrunning'], '<code>php-fpm</code>', '<strong>' . $lang['strjudgewayb'] . '</strong>') ?></li>
    <li><?= sprintf($lang['strjudgefpmnotrunning'], '<strong>' . $lang['strjudgewaya'] . '</strong>') ?></li>
</ul>

<p><?= $lang['strjudgesocket'] ?></p>
<pre><code>ls /run/php/\*.sock 2>/dev/null</code></pre>
<ul>
    <li><?= sprintf($lang['strjudgesocketrunning'], '<code>*.sock</code>', '<strong>' . $lang['strjudgewayb'] . '</strong>') ?></li>
    <li><?= sprintf($lang['strjudgesocketnotrunning'], '<strong>' . $lang['strjudgewaya'] . '</strong>') ?></li>
</ul>

<h3><?= $lang['strcreateconfig'] ?></h3>
<p><?= $lang['strcreateconfigdesc'] ?></p>
<p><?= sprintf($lang['strcreateconfigfile'], '<code>/etc/apache2/conf.d/</code>', '<code>phppgadmin.inc</code>', '<strong>' . $lang['strchooseone'] . '</strong>') ?></p>

<h3><?= $lang['strmodphp'] ?></h3>
<pre><code>Alias /phppgadmin /usr/share/phppgadmin

&lt;Directory /usr/share/phppgadmin&gt;

DirectoryIndex index.php
AllowOverride None
Require all granted

&lt;IfModule mod_php.c&gt;
  &lt;FilesMatch \.php$&gt;
    SetHandler application/x-httpd-php
  &lt;/FilesMatch&gt;
  php_flag magic_quotes_gpc Off
  php_flag track_vars On
  php_value include_path .
&lt;/IfModule&gt;

&lt;IfModule !mod_php.c&gt;
  &lt;IfModule mod_actions.c&gt;
    &lt;IfModule mod_cgi.c&gt;
      AddType application/x-httpd-php .php
      Action application/x-httpd-php /cgi-bin/php
    &lt;/IfModule&gt;
    &lt;IfModule mod_cgid.c&gt;
      AddType application/x-httpd-php .php
      Action application/x-httpd-php /cgi-bin/php
    &lt;/IfModule&gt;
  &lt;/IfModule&gt;
&lt;/IfModule&gt;

# Static asset caching
&lt;IfModule mod_headers.c&gt;
  &lt;FilesMatch "\.(css|js|svg|png|jpg|jpeg|gif|ico|woff2?|ttf|eot)$"&gt;
    Header set Cache-Control "public, max-age=2592000"
  &lt;/FilesMatch&gt;
&lt;/IfModule&gt;
&lt;IfModule mod_expires.c&gt;
  ExpiresActive On
  ExpiresByType text/css                "access plus 30 days"
  ExpiresByType application/javascript  "access plus 30 days"
  ExpiresByType image/svg+xml           "access plus 30 days"
  ExpiresByType image/png               "access plus 30 days"
  ExpiresByType image/jpeg              "access plus 30 days"
  ExpiresByType image/gif               "access plus 30 days"
  ExpiresByType image/x-icon            "access plus 30 days"
  ExpiresByType font/woff2              "access plus 30 days"
  ExpiresByType font/woff               "access plus 30 days"
  ExpiresByType application/font-woff   "access plus 30 days"
&lt;/IfModule&gt;

&lt;/Directory&gt;</code></pre>

<h3><?= $lang['strphpfpm'] ?></h3>
<pre><code>Alias /phppgadmin /usr/share/phppgadmin

&lt;Directory /usr/share/phppgadmin&gt;

DirectoryIndex index.php
AllowOverride None
Require all granted

&lt;IfModule mpm_event_module&gt;
  &lt;FilesMatch \.php$&gt;
    SetHandler "proxy:unix:/run/php/www.sock|fcgi://localhost"
  &lt;/FilesMatch&gt;
&lt;/IfModule&gt;

&lt;IfModule !mpm_event_module&gt;
  &lt;IfModule mod_actions.c&gt;
    &lt;IfModule mod_cgi.c&gt;
      AddType application/x-httpd-php .php
      Action application/x-httpd-php /cgi-bin/php
    &lt;/IfModule&gt;
    &lt;IfModule mod_cgid.c&gt;
      AddType application/x-httpd-php .php
      Action application/x-httpd-php /cgi-bin/php
    &lt;/IfModule&gt;
  &lt;/IfModule&gt;
&lt;/IfModule&gt;

# Static asset caching
&lt;IfModule mod_headers.c&gt;
  &lt;FilesMatch "\.(css|js|svg|png|jpg|jpeg|gif|ico|woff2?|ttf|eot)$"&gt;
    Header set Cache-Control "public, max-age=2592000"
  &lt;/FilesMatch&gt;
&lt;/IfModule&gt;
&lt;IfModule mod_expires.c&gt;
  ExpiresActive On
  ExpiresByType text/css                "access plus 30 days"
  ExpiresByType application/javascript  "access plus 30 days"
  ExpiresByType image/svg+xml           "access plus 30 days"
  ExpiresByType image/png               "access plus 30 days"
  ExpiresByType image/jpeg              "access plus 30 days"
  ExpiresByType image/gif               "access plus 30 days"
  ExpiresByType image/x-icon            "access plus 30 days"
  ExpiresByType font/woff2              "access plus 30 days"
  ExpiresByType font/woff               "access plus 30 days"
  ExpiresByType application/font-woff   "access plus 30 days"
&lt;/IfModule&gt;

&lt;/Directory&gt;</code></pre>

<p><strong><?= $lang['strchoosedesc'] ?></strong></p>

<h3><?= $lang['strincludeconfig'] ?></h3>
<p><?= sprintf($lang['strincludeconfigdesc'], '<code>/etc/apache2/conf.d/</code>', '<code>.conf</code>', '<code>&lt;VirtualHost&gt;</code>', '<code>conf.d</code>', '<code>phppgadmin.inc</code>') ?></p>
<pre><code>IncludeOptional /etc/apache2/conf.d/phppgadmin.inc</code></pre>
<ul>
    <li><?= sprintf($lang['strincludehttps'], '<code>443</code>', '<code>8443</code>') ?></li>
    <li><?= sprintf($lang['strincludehttp'], '<code>80</code>', '<code>8080</code>') ?></li>
</ul>
<p><?= sprintf($lang['strincludereload'], '<code>phppgadmin.inc</code>') ?></p>

<h2 id="restart-php"><?= $lang['strrestartapache'] ?></h2>
<p><?= sprintf($lang['strrestartdesc'], '<code>php.ini</code>', '<code>iisreset</code>') ?></p>
<p><?= sprintf($lang['strrestartchoose'], '<strong>PHP-FPM</strong>', '<strong>Apache + mod_php</strong>') ?></p>

<h3>PHP-FPM:</h3>
<ul>
    <li><strong>Debian / Ubuntu：</strong>
        <pre><code>systemctl restart php<?= htmlspecialchars(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?>-fpm</code></pre>
    </li>
    <li><strong>RHEL / CentOS / Fedora：</strong>
        <pre><code>systemctl restart php-fpm</code></pre>
    </li>
    <li><strong>macOS（Homebrew）：</strong>
        <pre><code>brew services restart php</code></pre>
    </li>
    <li><strong>FreeBSD：</strong>
        <pre><code>ls /usr/local/etc/rc.d/ | grep php
        service php_fpm restart</code></pre>
    </li>
</ul>

<h3>Apache + mod_php:</h3>
<ul>
    <li><strong>Debian / Ubuntu：</strong>
        <pre><code>systemctl restart apache2</code></pre>
    </li>
    <li><strong>RHEL / CentOS / Fedora：</strong>
        <pre><code>systemctl restart httpd</code></pre>
    </li>
    <li><strong>macOS（Homebrew）：</strong>
        <pre><code>brew services restart httpd</code></pre>
    </li>
    <li><strong>FreeBSD：</strong>
        <pre><code>service apache24 restart</code></pre>
    </li>
</ul>

<h2><?= $lang['strverify1'] ?></h2>
<p><?= $lang['strverifydesc1'] ?></p>
<p><?= $lang['strverifycmd1'] ?></p>
<pre><code>php -c "<?= htmlspecialchars($loadedIni ?: $lang['strnotfound']) ?>" -r 'echo ini_get("session.save_path"), "\n";'</code></pre>

<h2><?= $lang['strfaq'] ?></h2>

<h3><?= $lang['strsesspileup'] ?></h3>
<p><?= sprintf($lang['strsesspileupdesc'], '<code>session.gc_probability = 0</code>') ?></p>
<ul>
    <li><?= sprintf($lang['strsesspileupopt1'], '<code>session.gc_probability = 1</code>') ?></li>
    <li><?= sprintf($lang['strsesspileupopt2'], '<code>systemd-tmpfiles</code>') ?></li>
    <li><?= sprintf($lang['strsesspileupopt3'], '<code>cron</code>') ?></li>
</ul>

<h3><?= $lang['strconfirmcache'] ?></h3>
<p><?= sprintf($lang['strconfirmcachedesc'], '<code>curl -I</code>') ?></p>
<pre><code>curl -I <?= htmlspecialchars($scheme) ?>://<?= htmlspecialchars($domain) ?>/phppgadmin/images/themes/default/Schemas.png</code></pre>
<p><?= sprintf($lang['strconfirmcacheresult'], '<code>cache-control: public, max-age=2592000</code>', '<code>phppgadmin.inc</code>', '<code>mod_headers</code>', '<code>mod_expires</code>') ?></p>
<hr>
<h3 id="encrypt-auth"><?= $lang['strsessionpart3'] ?></h3>
<p class="warn"><?= $lang['strencryptauthpublicwarn'] ?></p>
<p><?= sprintf($lang['strencryptauthdesc'], '<code>bin/encrypt-password.php</code>') ?></p>
<p><?= sprintf($lang['strencryptauthkeyhint'], '<code>$conf[\'encryption_key\']</code>', '<code>conf/config.inc.php</code>') ?></p>
<p><?= $lang['strencryptauthuncomment'] ?></p>
<p>Linux:</p>
<pre><code>sed -i "s|^// \(\$conf\['encryption_key'\]\)|\1|" /etc/phppgadmin/config.inc.php</code></pre>
<p>FreeBSD:</p>
<pre><code>sed -i '' "s|^// \(\$conf\['encryption_key'\]\)|\1|" /usr/local/etc/phppgadmin/config.inc.php</code></pre>

<p><?= sprintf($lang['strencryptauthlang'], '<code>--lang</code>') ?></p>
<pre><code>php /usr/share/phppgadmin/bin/encrypt-password.php -h --lang=de</code></pre>
<p><?= sprintf($lang['strencryptauthlink'], '<code>bin/encrypt-password.php</code>', '<code>/usr/local/bin</code>') ?></p>
<pre><code>ln -s /usr/share/phppgadmin/bin/encrypt-password.php /usr/local/bin/encrypt-password
chmod +x /usr/local/bin/encrypt-password</code></pre>
<p><?= $lang['strencryptauthusage'] ?></p>
<pre><code>encrypt-password -h --lang=de</code></pre>
<p><?= $lang['strencryptauthadminwarn'] ?></p>
<br>
<p><a href="index.php" class="btn"><?= $lang['strbacktohome'] ?></a></p>
</div>

<script>
(function () {
    document.querySelectorAll('.rt-info pre').forEach(function (pre) {
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