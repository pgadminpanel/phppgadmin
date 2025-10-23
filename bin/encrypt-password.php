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
 *   php bin/encrypt-password.php (interactive mode)
 * 
 * The encryption key must be set in one of these ways:
 *   1. Environment variable: PHPPGADMIN_ENCRYPTION_KEY
 *   2. Config file: $conf['encryption_key'] in conf/config.inc.php
 */

// Change to project root directory
chdir(__DIR__ . '/..');

// Check PHP version
if (version_compare(PHP_VERSION, '7.2.0', '<')) {
    fwrite(STDERR, "Error: PHP 7.2 or higher is required for Sodium encryption.\n");
    fwrite(STDERR, "Current version: " . PHP_VERSION . "\n");
    exit(1);
}

// Check for Sodium extension
if (!extension_loaded('sodium')) {
    fwrite(STDERR, "Error: Sodium extension is not loaded.\n");
    fwrite(STDERR, "Install it with: apt-get install php-sodium (or equivalent for your system)\n");
    exit(1);
}

// Load Composer autoloader
if (!file_exists('vendor/autoload.php')) {
    fwrite(STDERR, "Error: Composer autoload not found. Run: composer install\n");
    exit(1);
}
require 'vendor/autoload.php';

use PhpPgAdmin\Security\CredentialEncryption;

// Parse command line arguments
$options = getopt('', ['password:', 'generate-key', 'help']);

// Show help
if (isset($options['help'])) {
    echo "phpPgAdmin Password Encryption Tool\n\n";
    echo "Usage:\n";
    echo "  php bin/encrypt-password.php [options]\n\n";
    echo "Options:\n";
    echo "  --password <password>   Password to encrypt\n";
    echo "  --generate-key          Generate a new encryption key\n";
    echo "  --help                  Show this help message\n\n";
    echo "Without options, runs in interactive mode.\n\n";
    echo "Encryption key must be set via:\n";
    echo "  - Environment variable: PHPPGADMIN_ENCRYPTION_KEY\n";
    echo "  - Config file: \$conf['encryption_key'] in conf/config.inc.php\n\n";
    echo "Example config.inc.php entry:\n";
    echo "  \$conf['servers'][0]['auth_type'] = 'config';\n";
    echo "  \$conf['servers'][0]['username'] = 'postgres';\n";
    echo "  \$conf['servers'][0]['password'] = 'ENCRYPTED:base64string';\n";
    exit(0);
}

// Generate new encryption key
if (isset($options['generate-key'])) {
    $key = CredentialEncryption::generateKey();
    echo "New encryption key (64 hex characters):\n";
    echo $key . "\n\n";
    echo "Store this key in one of these locations:\n";
    echo "  1. Environment variable:\n";
    echo "     export PHPPGADMIN_ENCRYPTION_KEY=\"{$key}\"\n\n";
    echo "  2. Config file (conf/config.inc.php):\n";
    echo "     \$conf['encryption_key'] = '{$key}';\n\n";
    echo "IMPORTANT: Keep this key secret! Anyone with this key can decrypt stored passwords.\n";
    exit(0);
}

// Load configuration to get encryption key
$conf = [];
if (file_exists('conf/config.inc.php')) {
    $conf = require 'conf/config.inc.php';
    if (!is_array($conf)) {
        $conf = [];
    }
}

// Check if encryption key is available
try {
    $key = CredentialEncryption::getKey($conf);
    if ($key === null) {
        fwrite(STDERR, "Error: Encryption key not configured.\n\n");
        fwrite(STDERR, "Set the key using one of these methods:\n");
        fwrite(STDERR, "  1. Environment variable:\n");
        fwrite(STDERR, "     export PHPPGADMIN_ENCRYPTION_KEY=\"<64-hex-characters>\"\n\n");
        fwrite(STDERR, "  2. Config file (conf/config.inc.php):\n");
        fwrite(STDERR, "     \$conf['encryption_key'] = '<64-hex-characters>';\n\n");
        fwrite(STDERR, "Generate a new key with: php bin/encrypt-password.php --generate-key\n");
        exit(1);
    }
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

// Get password to encrypt
$password = null;

// Check command line argument
if (isset($options['password'])) {
    $password = $options['password'];
}

if ($password === null) {
    echo "Enter password to encrypt: ";

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
    fwrite(STDERR, "Error: Password cannot be empty.\n");
    exit(1);
}

// Encrypt the password
try {
    $encrypted = CredentialEncryption::encrypt($password, $conf);

    echo "\nEncrypted password:\n";
    echo "ENCRYPTED:" . $encrypted . "\n\n";

    echo "Add this to your conf/config.inc.php:\n\n";
    echo "\$conf['servers'][0]['auth_type'] = 'config';\n";
    echo "\$conf['servers'][0]['username'] = 'your_username';\n";
    echo "\$conf['servers'][0]['password'] = 'ENCRYPTED:{$encrypted}';\n\n";

    // Verify encryption worked by decrypting
    $decrypted = CredentialEncryption::decrypt($encrypted, $conf);
    if ($decrypted === $password) {
        echo "✓ Verification successful - encryption/decryption works correctly.\n";
    } else {
        fwrite(STDERR, "⚠ Warning: Verification failed - decrypted password doesn't match.\n");
        exit(1);
    }

} catch (Exception $e) {
    fwrite(STDERR, "Error encrypting password: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
