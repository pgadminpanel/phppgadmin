#!/usr/bin/env php
<?php
/**
 * phpPgAdmin Password Encryption Tool
 *
 * Generate encrypted passwords for use with config-based authentication.
 * Requires Sodium extension (PHP 7.2+) and an encryption key.
 *
 * Usage:
 *   php bin/encrypt-password.php --password "your_password"
 *   php bin/encrypt-password.php --generate-key
 *   php bin/encrypt-password.php --langs
 *   php bin/encrypt-password.php --lang de
 *   php bin/encrypt-password.php (interactive mode)
 *
 * The encryption key must be set in one of these ways:
 *   1. Environment variable: PHPPGADMIN_ENCRYPTION_KEY
 *   2. Config file: $conf['encryption_key'] in conf/config.inc.php
 */

// Change to project root directory
chdir(__DIR__ . '/..');

// Parse command line arguments
$options = getopt('h', ['password:', 'generate-key', 'help', 'lang:', 'langs']);

// Load configuration first (needed for default_lang and encryption key)
$conf = [];
if (file_exists('conf/config.inc.php')) {
    $conf = require 'conf/config.inc.php';
    if (!is_array($conf)) {
        $conf = [];
    }
}

// Load translations list
$translationsFile = __DIR__ . '/../lang/translations.php';
if (!file_exists($translationsFile)) {
    fwrite(STDERR, "Error: translations.php not found.\n");
    exit(1);
}
$translations = require $translationsFile;
[$appLangFiles, $availableLanguages] = $translations;

// Resolve language
$langCode = null;

// 1. --lang option
if (!empty($options['lang'])) {
    $langCode = strtolower($options['lang']);
}
// 2. $conf['default_lang']
elseif (!empty($conf['default_lang']) && $conf['default_lang'] !== 'auto') {
    $langCode = strtolower($conf['default_lang']);
}

// 3. Fallback
if (empty($langCode)) {
    $langCode = 'en';
}

// Map ISO code -> language file
if (!isset($availableLanguages[$langCode])) {
    fwrite(STDERR, "Warning: language '{$langCode}' not found, falling back to en.\n");
    $langCode = 'en';
}

$langFileKey = $availableLanguages[$langCode];

// Load language file
$lang = [];
$langFile = __DIR__ . '/../lang/' . $langFileKey . '.php';
if (!file_exists($langFile)) {
    fwrite(STDERR, "Error: language file not found: {$langFile}\n");
    exit(1);
}
require $langFile;

// Helper for output
function t($lang, $key, ...$args) {
    $text = $lang[$key] ?? $key;
    if (!empty($args)) {
        return vsprintf($text, $args);
    }
    return $text;
}

// Show help
if (isset($options['help']) || isset($options['h'])) {
    echo t($lang, 'strclipwdtitle') . "\n";
    echo t($lang, 'strclipwdusage') . "\n";
    echo "  php bin/encrypt-password.php [options]\n\n";
    echo t($lang, 'strclipwdoptions') . "\n";
    echo "  --password <password>   " . t($lang, 'strclipwdpassword') . "\n";
    echo "  --generate-key          " . t($lang, 'strclipwdgenkey') . "\n";
    echo "  --lang <code>           " . t($lang, 'strclipwdlang') . "\n";
    echo "  --langs                 " . t($lang, 'strclipwdlistlangs') . "\n";
    echo "  -h, --help              " . t($lang, 'strclipwdhelp') . "\n";
    echo t($lang, 'strclipwdinteractive') . "\n\n";
    echo t($lang, 'strclipwdkeymust') . "\n";
    echo "  - " . t($lang, 'strclipwdenvvar', 'PHPPGADMIN_ENCRYPTION_KEY') . "\n";
    echo "  - " . t($lang, 'strclipwdconffile', 'conf/config.inc.php', "\$conf['encryption_key']") . "\n\n";
    echo t($lang, 'strclipwdexamples') . "\n";
    printf("  %-46s %s\n",
        'encrypt-password --password "123456" --lang=de',
        t($lang, 'strclipwdexample1')
    );
    printf("  %-46s %s\n",
        'encrypt-password --langs',
        t($lang, 'strclipwdexample2')
    );
    exit(0);
}

// List supported languages
if (isset($options['langs'])) {
    echo t($lang, 'strclipwdavailablelangs') . "\n\n";

    foreach ($availableLanguages as $code => $file) {
        $name = $appLangFiles[$file] ?? $file;
        printf("  %-10s %s\n", $code, $name);
    }

    echo "\n" . t($lang, 'strclipwdavailablelangsnote') . "\n";
    exit(0);
}

// Check PHP version
if (version_compare(PHP_VERSION, '7.2.0', '<')) {
    fwrite(STDERR, t($lang, 'strclipwdphperror') . "\n");
    fwrite(STDERR, t($lang, 'strclipwdcurrentversion', PHP_VERSION) . "\n");
    exit(1);
}

// Check for Sodium extension
if (!extension_loaded('sodium')) {
    fwrite(STDERR, t($lang, 'strclipwdsodiumerror') . "\n");
    fwrite(STDERR, t($lang, 'strclipwdsodiumhint', 'apt-get install php-sodium') . "\n");
    exit(1);
}

// Load Composer autoloader
if (!file_exists('vendor/autoload.php')) {
    fwrite(STDERR, t($lang, 'strclipwdautoloaderror', 'composer install') . "\n");
    exit(1);
}
require 'vendor/autoload.php';

use PhpPgAdmin\Security\CredentialEncryption;

// Generate new encryption key
if (isset($options['generate-key'])) {
    $key = CredentialEncryption::generateKey();
    echo t($lang, 'strclipwdnewkey') . "\n";
    echo $key . "\n\n";
    echo t($lang, 'strclipwdstorekey') . "\n";
    echo "  1. " . t($lang, 'strclipwdenvvar', 'PHPPGADMIN_ENCRYPTION_KEY') . "\n";
    echo "     export PHPPGADMIN_ENCRYPTION_KEY=\"{$key}\"\n\n";
    echo "  2. " . t($lang, 'strclipwdconffile', 'conf/config.inc.php', "\$conf['encryption_key']") . "\n";
    echo "     \$conf['encryption_key'] = '{$key}';\n\n";
    echo t($lang, 'strclipwdkeysecret') . "\n";
    exit(0);
}

// Check if encryption key is available
try {
    $key = CredentialEncryption::getKey($conf);
    if ($key === null) {
        fwrite(STDERR, t($lang, 'strclipwdkeymissing') . "\n\n");
        fwrite(STDERR, t($lang, 'strclipwdkeymust') . "\n");
        fwrite(STDERR, "  1. " . t($lang, 'strclipwdenvvar', 'PHPPGADMIN_ENCRYPTION_KEY') . "\n");
        fwrite(STDERR, "     export PHPPGADMIN_ENCRYPTION_KEY=\"<64-hex-characters>\"\n\n");
        fwrite(STDERR, "  2. " . t($lang, 'strclipwdconffile', 'conf/config.inc.php', "\$conf['encryption_key']") . "\n");
        fwrite(STDERR, "     \$conf['encryption_key'] = '<64-hex-characters>';\n\n");
        fwrite(STDERR, t($lang, 'strclipwdgenhint', 'php bin/encrypt-password.php --generate-key') . "\n");
        exit(1);
    }
} catch (Exception $e) {
    fwrite(STDERR, t($lang, 'strclipwdencerror') . $e->getMessage() . "\n");
    exit(1);
}

// Get password to encrypt
$password = null;

// Check command line argument
if (isset($options['password'])) {
    $password = $options['password'];
}

if ($password === null) {
    echo t($lang, 'strclipwdenterpwd');

    if (stripos(PHP_OS, 'WIN') === 0) {
        // Windows: PowerShell SecureString prompt
        $cmd = 'powershell -Command "$p = Read-Host -AsSecureString; ' .
            '$BSTR=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); ' .
            '[Runtime.InteropServices.Marshal]::PtrToStringAuto($BSTR)"';
        $password = trim(shell_exec($cmd));
        echo "\n";
    } else {
        // Unix-like: stty echo off
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    }
}

// Validate password
if (empty($password)) {
    fwrite(STDERR, t($lang, 'strclipwdpwdempty') . "\n");
    exit(1);
}

// Encrypt the password
try {
    $encrypted = CredentialEncryption::encrypt($password, $conf);

    echo "\n" . t($lang, 'strclipwdencrypted') . "\n";
    echo "ENCRYPTED:" . $encrypted . "\n\n";

    // Ask for username
    echo t($lang, 'strclipwdenteruser', 'your_username');
    $username = trim(fgets(STDIN));
    if (empty($username)) {
        $username = 'your_username';
    }

    echo "\n" . t($lang, 'strclipwdaddconfig', 'conf/config.inc.php') . "\n\n";
    echo "\$conf['servers'][0]['auth_type'] = 'config';\n";
    echo "\$conf['servers'][0]['username'] = '{$username}';\n";
    echo "\$conf['servers'][0]['password'] = 'ENCRYPTED:{$encrypted}';\n\n";

    // Verify encryption worked by decrypting
    $decrypted = CredentialEncryption::decrypt($encrypted, $conf);
    if ($decrypted === $password) {
        echo t($lang, 'strclipwdverifyok') . "\n";
    } else {
        fwrite(STDERR, t($lang, 'strclipwdverifyfail') . "\n");
        exit(1);
    }

} catch (Exception $e) {
    fwrite(STDERR, t($lang, 'strclipwdencerror') . $e->getMessage() . "\n");
    exit(1);
}

exit(0);