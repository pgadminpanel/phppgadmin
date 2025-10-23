<?php

namespace PhpPgAdmin\Security;

use Exception;

/**
 * Credential encryption/decryption using Sodium library
 * 
 * Encrypts credentials using authenticated encryption (XSalsa20-Poly1305)
 * for secure storage in configuration files and sessions.
 */
class CredentialEncryption
{
    /**
     * @var string|null The encryption key (32 bytes raw)
     */
    private static $key = null;

    /**
     * @var bool Whether key has been loaded
     */
    private static $keyLoaded = false;

    /**
     * Load encryption key from environment variable or config
     * Priority: Environment variable > Config file
     * 
     * @param array|null $conf Configuration array
     * @return string|null The encryption key (32 bytes raw) or null if not configured
     * @throws Exception if key is invalid
     */
    public static function loadKey($conf = null)
    {
        if (self::$keyLoaded) {
            return self::$key;
        }

        // Try environment variable first
        $keyHex = getenv('PHPPGADMIN_ENCRYPTION_KEY');
        
        // Fallback to config file
        if ($keyHex === false && $conf !== null && isset($conf['encryption_key'])) {
            $keyHex = $conf['encryption_key'];
        }

        if ($keyHex === false || $keyHex === '' || $keyHex === null) {
            self::$keyLoaded = true;
            self::$key = null;
            return null;
        }

        // Validate and decode hex key
        $keyHex = trim($keyHex);
        if (!ctype_xdigit($keyHex)) {
            throw new Exception('Encryption key must be a hexadecimal string');
        }

        $key = hex2bin($keyHex);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new Exception('Encryption key must be exactly ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes (64 hex characters)');
        }

        self::$key = $key;
        self::$keyLoaded = true;
        return self::$key;
    }

    /**
     * Get the current encryption key
     * 
     * @param array|null $conf Configuration array
     * @return string|null The encryption key (32 bytes raw) or null if not configured
     */
    public static function getKey($conf = null)
    {
        if (!self::$keyLoaded) {
            return self::loadKey($conf);
        }
        return self::$key;
    }

    /**
     * Check if encryption is available (key is configured)
     * 
     * @param array|null $conf Configuration array
     * @return bool True if encryption key is available
     */
    public static function isAvailable($conf = null)
    {
        $key = self::getKey($conf);
        return $key !== null;
    }

    /**
     * Get a hash of the current encryption key for version checking
     * 
     * @param array|null $conf Configuration array
     * @return string|null SHA-256 hash of the key, or null if no key
     */
    public static function getKeyHash($conf = null)
    {
        $key = self::getKey($conf);
        if ($key === null) {
            return null;
        }
        return hash('sha256', $key);
    }

    /**
     * Encrypt a plaintext string
     * 
     * @param string $plaintext The string to encrypt
     * @param array|null $conf Configuration array
     * @return string Base64-encoded encrypted data with nonce
     * @throws Exception if encryption fails or key not available
     */
    public static function encrypt($plaintext, $conf = null)
    {
        $key = self::getKey($conf);
        if ($key === null) {
            throw new Exception('Encryption key not configured. Set PHPPGADMIN_ENCRYPTION_KEY environment variable or $conf[\'encryption_key\']');
        }

        // Generate random nonce
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        // Encrypt
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        
        // Combine nonce + ciphertext and encode
        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt an encrypted string
     * 
     * @param string $encrypted Base64-encoded encrypted data with nonce
     * @param array|null $conf Configuration array
     * @return string The decrypted plaintext
     * @throws Exception if decryption fails or key not available
     */
    public static function decrypt($encrypted, $conf = null)
    {
        $key = self::getKey($conf);
        if ($key === null) {
            throw new Exception('Encryption key not configured. Set PHPPGADMIN_ENCRYPTION_KEY environment variable or $conf[\'encryption_key\']');
        }

        // Decode base64
        $decoded = base64_decode($encrypted, true);
        if ($decoded === false) {
            throw new Exception('Invalid encrypted data: not valid base64');
        }

        // Extract nonce and ciphertext
        if (strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new Exception('Invalid encrypted data: too short');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // Decrypt
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new Exception('Decryption failed: invalid key or corrupted data');
        }

        return $plaintext;
    }

    /**
     * Generate a new random encryption key
     * 
     * @return string Hexadecimal key suitable for configuration
     */
    public static function generateKey()
    {
        return bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * Check if a string appears to be encrypted (base64 with sufficient length)
     * Note: This is a heuristic check, not definitive
     * 
     * @param string $str The string to check
     * @return bool True if string looks like encrypted data
     */
    public static function looksEncrypted($str)
    {
        if (empty($str) || strlen($str) < 32) {
            return false;
        }
        
        // Check if it's valid base64 and sufficient length
        $decoded = base64_decode($str, true);
        return $decoded !== false && strlen($decoded) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    }
}
