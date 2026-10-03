<?php
/**
 * Test information page
 *
 * Describes how to prepare the environment and run the test suites.
 *
 * @package PhpPgAdmin
 */
use PhpPgAdmin\Core\AppContainer;

$_ENV["SKIP_DB_CONNECTION"] = '1';
require_once './libraries/bootstrap.php';
$misc = AppContainer::getMisc();
$lang = AppContainer::getLang();
$fmt = function (string $key, ...$args) use ($lang) {
    return vsprintf($lang[$key], $args);
};

$misc->printHeader(
    $lang['strtestinfo'],
    <<<'CSS'
<style>
    .test-info pre { position: relative; background: #ebf0b6; max-height: 100px;  overflow: auto; border: 1px solid #d0d7de; border-radius: 6px; padding: 12px 16px;  width: fit-content; max-width: 100%; overflow-x: auto; font-size: 13px; line-height: 1.45; }
    .test-info pre code { background: none; border: none; padding: 0; font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; color: #24292f; }
    .test-info code { background: rgb(216, 189, 44); border-radius: 4px; padding: 0.2em 0.4em; margin: 0.2em 0; font-size: 85%; font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; display: inline-block; }
    .test-info h2 { border-bottom: 1px solid #d0d7de; padding-bottom: 0.3em; margin-top: 1.5em; }
    .test-info .notice { background: #ebf0b6; width: fit-content; max-width: 100%; border-left: 4px solid #d4a72c; padding: 12px 16px; margin: 1em 0; border-radius: 4px; }
    .test-info .notice p { margin: 0; color: #000; }
    .test-info .btn { display: inline-block; padding: 8px 16px; background: #d25c2d; color: #fff; text-decoration: none; border-radius: 4px; }
    .test-info .btn:hover { background: #d22d2d; }
    .test-info table.data td, .test-info table.data th { padding: 4px 8px; }
    .test-info .pre-wrap { position: relative; width: fit-content; max-width: 100%; margin: 1em 0; }
    .test-info .pre-wrap .copy-btn { position: absolute; top: 8px; right: 8px; z-index: 10; padding: 4px 12px; font-size: 13px; background: rgba(255, 255, 255, 0.95); border: 1px solid #d0d7de; border-radius: 4px; cursor: pointer; opacity: 0; transition: opacity 0.2s, background 0.2s; }
    .test-info .pre-wrap:hover .copy-btn { opacity: 1; }
    .test-info .pre-wrap .copy-btn:hover { background: #f3f4f6; }
    .test-info .pre-wrap .copy-btn.copied { background: #d4edda; border-color: #28a745; color: #155724; }
</style>
CSS
);

$misc->printBody();
$misc->printTrail('root');
$misc->printTabs('root', 'intro');
?>
<div class="test-info">
<h1><?php echo $lang['strtestinfo']; ?></h1>
<h2><?php echo $lang['strtestoverview']; ?></h2>
<p><?php echo $fmt('strtestoverviewdesc', '<strong>' . $lang['strtestunit'] . '</strong>', '<strong>' . $lang['strtestintegration'] . '</strong>'); ?></p>
<h2><?php echo $lang['strtestprep']; ?></h2>
<div class="notice">
<p><strong><?php echo $lang['strtestnotice_label']; ?><?php echo $lang['strseparator']; ?></strong><?php echo $fmt('strtestnotice',
    '<strong>' . $lang['strtestpath'] . '</strong>',
    '<strong>' . $lang['strtestpkgcmd'] . '</strong>',
    '<code>apt-get</code>',
    '<strong>' . $lang['strtestphpver'] . '</strong>',
    '<code>8.5</code>',
    '<code>Debian/Ubuntu</code>'
); ?></p>
</div>

<h3><?php echo $lang['strtestcheckext']; ?></h3>
<p><?php echo $lang['strtestcheckextdesc']; ?></p>
<pre><code>php -m | grep -E 'pgsql|mbstring|xml|tokenizer'</code></pre>
<p><strong><?php echo $lang['strtestexpectedlabel']; ?></strong><?php echo $lang['strtestexpectedhint']; ?><?php echo $lang['strseparator']; ?></p>
<pre><code>libxml
mbstring
pdo_pgsql
pgsql
tokenizer
xml
xmlreader
xmlwriter</code></pre>

<p><?php echo $lang['strtestcheckxdebug']; ?></p>
<pre><code>php -m | grep xdebug
php --ri xdebug | grep -i mode</code></pre>
<p><strong><?php echo $lang['strtestexpectedlabel']; ?></strong>&nbsp;<?php echo $lang['strseparator']; ?></p>
<pre><code>             Enabled Features (through 'xdebug.mode' setting)             
xdebug.mode => coverage => coverage
xdebug.remote_mode => (setting renamed in Xdebug 3) => (setting renamed in Xdebug 3)
</code></pre>

<p><?php echo $fmt('strtestskipstep5', '<strong>' . $lang['strtestprepdb'] . '</strong>'); ?></p>

<h3><?php echo $lang['strtestinstallext']; ?></h3>
<p><?php echo $lang['strtestinstallextdesc']; ?></p>
<ul>
    <li><strong>Debian/Ubuntu</strong>：<code>apt-get install -y php-pgsql</code></li>
    <li><strong>RHEL/CentOS/Fedora</strong>：<code>dnf install -y php-pgsql</code></li>
    <li><strong>macOS (Homebrew)</strong>：<code>brew install php</code><?php echo $lang['strtestbrewdesc']; ?></li>
    <li><strong>FreeBSD</strong>：<code>pkg install -y php83-pgsql</code></li>
</ul>
<p><?php echo $fmt('strtestinstallexample', '<code>Debian/Ubuntu</code>', '<code>pgsql</code>', '<code>mbstring</code>'); ?></p>
<pre><code>apt-get install -y php-pgsql php-mbstring</code></pre>
<p><?php echo $lang['strtestinstallall']; ?></p>
<pre><code>apt-get install -y php-pgsql php-mbstring php-xml php-tokenizer</code></pre>

<h3><?php echo $lang['strtestinstallxdebug']; ?></h3>
<p><?php echo $lang['strtestinstallxdebugdesc']; ?></p>
<pre><code>php -m | grep xdebug</code></pre>
<p><?php echo $lang['strtestinstallxdebugif']; ?></p>
<pre><code>apt-get install -y php-xdebug</code></pre>
<p><?php echo $lang['strtestxdebugmode']; ?></p>
<pre><code>echo "xdebug.mode=coverage" &gt;&gt; /etc/php/8.5/mods-available/xdebug.ini</code></pre>
<p><?php echo $lang['strtestxdebugveradjust']; ?></p>
<pre><code>php --ini | grep xdebug</code></pre>
<p><?php echo $lang['strtestverify']; ?></p>
<pre><code>php --ri xdebug | grep -i mode
# <?php echo $lang['strtestshouldoutput']; ?>：xdebug.mode =&gt; coverage =&gt; coverage</code></pre>

<h3><?php echo $lang['strtestgetsource']; ?></h3>
<p><?php echo $lang['strtestgetsourcepick']; ?></p>

<p><strong><?php echo $lang['strtestmethoda']; ?></strong></p>
<p><strong>Linux / macOS / FreeBSD</strong><?php echo $lang['strseparator']; ?></p>
<p><?php echo $fmt('strtestcheckwgettar', '<code>wget</code>', '<code>tar</code>'); ?><?php echo $lang['strseparator']; ?></p>
<pre><code>which wget
which tar</code></pre>
<p><?php echo $fmt('strtestneedwgettar', '<code>wget</code>', '<code>tar</code>'); ?><?php echo $lang['strseparator']; ?></p>
<p><?php echo $lang['strtestifmissing']; ?>&nbsp;(Debian/Ubuntu)<?php echo $lang['strseparator']; ?></p>
<pre><code>apt-get install -y wget tar</code></pre>
<p><?php echo $lang['strtestthendownload']; ?><?php echo $lang['strseparator']; ?></p>
<pre><code>mkdir -p /usr/share/phppgadmin
cd /usr/share/phppgadmin
wget https://github.com/pgadminpanel/phppgadmin/archive/refs/heads/master.tar.gz
tar -xzf master.tar.gz -C phppgadmin --strip-components=1</code></pre>
<p><?php echo $fmt('strteststripdesc', '<code>--strip-components=1</code>', '<code>phppgadmin-master/</code>', '<code>phppgadmin/</code>'); ?></p>

<p><strong>Git Bash</strong><?php echo $lang['strseparator']; ?></p>
<p><?php echo $fmt('strtestcheckwgettar', '<code>curl</code>', '<code>tar</code>'); ?><?php echo $lang['strseparator']; ?></p>
<pre><code>which curl
which tar</code></pre>
<p><?php echo $lang['strtestthendownload']; ?><?php echo $lang['strseparator']; ?></p>
<pre><code>mkdir -p phppgadmin
curl -L -o master.tar.gz https://github.com/pgadminpanel/phppgadmin/archive/refs/heads/master.tar.gz
tar -xzf master.tar.gz -C phppgadmin --strip-components=1</code></pre>

<p><strong>CMD / PowerShell</strong><?php echo $lang['strseparator']; ?></p>
<p><?php echo $fmt('strtestcheckwgettar', '<code>curl</code>', '<code>tar</code>'); ?><?php echo $lang['strseparator']; ?></p>
<pre><code>where.exe curl
where.exe tar</code></pre>
<p><?php echo $fmt('strtestwinmiss', '<code>curl</code>', '<code>tar</code>'); ?>&nbsp;(git clone)<?php echo $lang['strseparator']; ?></p>
<p><?php echo $lang['strtestthendownload']; ?><?php echo $lang['strseparator']; ?></p>
<pre><code>mkdir -p C:\phppgadmin
cd C:\phppgadmin
curl -L -o master.tar.gz https://github.com/pgadminpanel/phppgadmin/archive/refs/heads/master.tar.gz
tar -xzf master.tar.gz -C phppgadmin --strip-components=1</code></pre>

<p><strong><?php echo $lang['strtestmethodb']; ?></strong></p>
<p><?php echo $lang['strtestcheckgit']; ?></p>
<pre><code>git --version</code></pre>
<p><?php echo $lang['strtestgitmissing']; ?>&nbsp;(Debian/Ubuntu)<?php echo $lang['strseparator']; ?></p>
<pre><code>apt-get install -y git</code></pre>
<p><?php echo $lang['strtestthenclone']; ?></p>
<pre><code>cd /usr/share/phppgadmin
git clone https://github.com/pgadminpanel/phppgadmin.git</code></pre>
<p><?php echo $fmt('strtestvendorincluded', '<code>vendor/</code>', '<strong>' . $lang['strtestnocomposer'] . '</strong>'); ?></p>
<h3><?= $lang['strtestconfigerror'] ?></h3>
<p><?= $lang['strtestconfigerrordesc'] ?></p>
<pre><code>Configuration error: Copy conf/config.inc.php-dist to conf/config.inc.php and edit appropriately.</code></pre>
<p><?= $lang['strtestconfigerrorfix'] ?></p>
<pre><code>cp /usr/share/phppgadmin/conf/config-dist.inc.php /usr/share/phppgadmin/conf/config.inc.php</code></pre>
<h3><?php echo $lang['strtestprepdb']; ?></h3>
<pre><code>sudo -u postgres psql -c "CREATE USER web_test WITH PASSWORD 'your-password';"
sudo -u postgres psql -c "CREATE DATABASE web_test OWNER web_test;"</code></pre>

<p><?php echo $lang['strfind']; ?>&nbsp;<code>pg_hba.conf</code>&nbsp;<?php echo $lang['strlocation']; ?><?php echo $lang['strseparator']; ?></p>

<p><strong>Linux / macOS / FreeBSD</strong><?php echo $lang['strseparator']; ?></p>
<pre><code>find / -name "pg_hba.conf" 2&gt;/dev/null</code></pre>

<p><strong>Windows (PowerShell)</strong><?php echo $lang['strseparator']; ?></p>
<pre><code>Get-ChildItem -Path C:\ -Filter pg_hba.conf -Recurse -ErrorAction SilentlyContinue</code></pre>

<p><strong>Windows (CMD)</strong><?php echo $lang['strseparator']; ?></p>
<pre><code>dir /s /b C:\pg_hba.conf</code></pre>

<p><?php echo $fmt('strtestcheckpg_hba', '<code>pg_hba.conf</code>'); ?></p>
<p><?php echo $lang['strtestcommonpaths']; ?></p>
<ul>
    <li><strong>Debian/Ubuntu</strong><?php echo $lang['strseparator']; ?><code>/etc/postgresql/18/main/pg_hba.conf</code></li>
    <li><strong>RHEL/CentOS</strong><?php echo $lang['strseparator']; ?><code>/var/lib/pgsql/data/pg_hba.conf</code></li>
    <li><strong>macOS (Homebrew)</strong><?php echo $lang['strseparator']; ?><code>$(brew --prefix)/var/postgresql@18/pg_hba.conf</code></li>
    <li><strong>FreeBSD</strong><?php echo $lang['strseparator']; ?><code>/var/db/postgres/data18/pg_hba.conf</code></li>
    <li><strong>Windows</strong><?php echo $lang['strseparator']; ?><code>C:\Program Files\PostgreSQL\18\data\pg_hba.conf</code></li>
</ul>

<p><?php echo $lang['strtestcheck']; ?></p>
<pre><code>grep -E '^host' /etc/postgresql/18/main/pg_hba.conf</code></pre>
<p><?php echo $lang['strtestexpectedlabel']; ?><?php echo $lang['strseparator']; ?></p>
<pre><code>host    all         all         127.0.0.1/32          md5
host    all         all         ::1/128               md5
host    all         all         0.0.0.0/0             md5
</code></pre>
<p><?php echo $lang['strtestpghbamethods']; ?></p>

<h2><?php echo $lang['strtestrun']; ?></h2>

<p><?php echo $lang['strtestrunassume']; ?></p>

<h3><?php echo $lang['strtestunittests']; ?></h3>
<pre><code>cd /usr/share/phppgadmin
php vendor/bin/phpunit</code></pre>
<p><?php echo $fmt('strtestunitdesc', '<code>DependencyGraph</code>', '<code>SequenceDumper</code>', '<code>SqlParser</code>'); ?></p>

<h3><?php echo $lang['strtestintegrationtests']; ?></h3>
<pre><code>cd /usr/share/phppgadmin
export PPAD_TEST_PASS='your-db-password'
php vendor/bin/phpunit -c phpunit-integration.xml --testdox</code></pre>
<p><?php echo $fmt('strtestintegrationdesc', '<code>DependencyAnalyzer</code>'); ?></p>
<ul>
    <li><code>PPAD_TEST_PASS</code><?php echo $lang['strtestenvrequired']; ?></li>
    <li><code>PPAD_TEST_HOST</code><?php echo $lang['strtestenvoptional']; ?> <code>localhost</code></li>
    <li><code>PPAD_TEST_PORT</code><?php echo $lang['strtestenvoptional']; ?> <code>5432</code></li>
    <li><code>PPAD_TEST_DB</code><?php echo $lang['strtestenvoptional']; ?> <code>web_test</code></li>
    <li><code>PPAD_TEST_USER</code><?php echo $lang['strtestenvoptional']; ?> <code>web_test</code></li>
    <li><code>PPAD_TEST_SCHEMA</code><?php echo $lang['strtestenvoptional']; ?> <code>deps_test</code></li>
</ul>

<h3><?php echo $lang['strtestcoverage']; ?></h3>
<pre><code>cd /usr/share/phppgadmin
php -d xdebug.mode=coverage vendor/bin/phpunit -c phpunit-integration.xml --coverage-text</code></pre>
<p><?php echo $fmt('strtestcoveragedesc', '<code>DependencyGraph</code>', '<code>phpunit-integration.xml</code>', '<code>&lt;coverage&gt;</code>'); ?></p>

<h2><?php echo $lang['strtestfiles']; ?></h2>
<table class="data">
    <thead>
        <tr>
            <th class="data"><?php echo $lang['strfile']; ?></th>
            <th class="data"><?php echo $lang['strpurpose']; ?></th>
        </tr>
    </thead>
    <tbody>
        <tr class="data1"><td><code>phpunit.xml</code></td><td><?php echo $lang['strtestcfgphpunit']; ?></td></tr>
        <tr class="data2"><td><code>phpunit-integration.xml</code></td><td><?php echo $lang['strtestcfgintegration']; ?></td></tr>
        <tr class="data1"><td><code>tests/Unit/</code></td><td><?php echo $lang['strtestcfgunitdir']; ?></td></tr>
        <tr class="data2"><td><code>tests/Integration/</code></td><td><?php echo $lang['strtestcfgintegrationdir']; ?></td></tr>
        <tr class="data1"><td><code>tests/fixtures/</code></td><td><?php echo $lang['strtestcfgfixtures']; ?></td></tr>
    </tbody>
</table>

<h2><?php echo $lang['strtestfaq']; ?></h2>

<h3><?php echo $fmt('strtesterrornopass', '<code>PPAD_TEST_PASS environment variable not set</code>'); ?></h3>
<p><?php echo $lang['strtesterrornopassdesc']; ?></p>
<pre><code>export PPAD_TEST_PASS='your-db-password'</code></pre>
<p><?php echo $lang['strtesterrororinline']; ?></p>
<pre><code>PPAD_TEST_PASS='your-db-password' php vendor/bin/phpunit -c phpunit-integration.xml</code></pre>

<h3><?php echo $fmt('strtesterrorcannotconnect', '<code>Cannot connect to PostgreSQL</code>'); ?></h3>
<p><?php echo $lang['strtestcheck']; ?></p>
<ul>
    <li><?php echo $fmt('strtestpgisready', '<code>pg_isready</code>'); ?></li>
    <li><?php echo $fmt('strtestpguser', '<code>sudo -u postgres psql -l</code>'); ?></li>
    <li><?php echo $fmt('strtestpgpass', '<code>php -r \'var_dump(pg_connect("host=localhost dbname=web_test user=web_test password=your-pass"));\'</code>'); ?></li>
    <li><?php echo $fmt('strtestpghba', '<code>pg_hba.conf</code>'); ?></li>
</ul>

<h3><?php echo $fmt('strtesterrornocoverage', '<code>No code coverage driver available</code>'); ?></h3>
<p><?php echo $lang['strtestxdebughint']; ?></p>

<h2><?php echo $lang['strtestrelateddocs']; ?></h2>
<ul>
	<li><a href="https://github.com/pgadminpanel/phppgadmin/blob/master/docs/UNIFIED_EXPORT_ARCHITECTURE.md" target="_blank" rel="noopener noreferrer"><?php echo $lang['strtestdocarch']; ?></a></li>
	<li><a href="https://github.com/pgadminpanel/phppgadmin/blob/master/docs/DEPENDENCY_AWARE_DUMPING.md" target="_blank" rel="noopener noreferrer"><?php echo $lang['strtestdocdep']; ?></a></li>
</ul>
<hr>
<p><a href="index.php" class="btn"><?php echo $lang['strbacktohome']; ?></a></p>
</div>
<script>
(function () {
    document.querySelectorAll('.test-info pre').forEach(function (pre) {
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