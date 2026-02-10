<?php

/**
 * Encryption helper functions for storing sensitive values
 */

// Encryption configuration
define('ENCRYPTION_METHOD', 'AES-256-CBC');
define('ENV_FILE', __DIR__ . '/../.env');

/**
 * Check if a setting key needs encryption
 * @param string $key The setting key
 * @return bool True if key should be encrypted
 */
function needs_encryption($key) {
    // List of keys that contain sensitive data
    $sensitive_keys = [
        'github_token',
        'linear_token',
        'openai_key',
        'anthropic_key'
    ];

    return in_array($key, $sensitive_keys);
}

/**
 * Check if a value appears to be encrypted
 * @param string $value The value to check
 * @return bool True if value looks encrypted
 */
function is_encrypted($value) {
    // Encrypted values are base64 encoded and start with IV (16 bytes)
    // Check if it's valid base64 and has reasonable length
    if (empty($value)) {
        return false;
    }

    // Check if it's valid base64
    if (base64_encode(base64_decode($value, true)) !== $value) {
        return false;
    }

    // Decoded value should be at least 16 bytes (IV) + some ciphertext
    $decoded = base64_decode($value, true);
    return strlen($decoded) > 16;
}

/**
 * Get or generate encryption key
 * @return string Encryption key
 */
function get_encryption_key() {
    // Check if .env file exists
    if (!file_exists(ENV_FILE)) {
        // Generate a new 64-character hex key
        $key = bin2hex(random_bytes(32)); // 32 bytes = 64 hex chars

        // Create .env file with the key
        $content = "ENCRYPTION_KEY=$key\n";
        file_put_contents(ENV_FILE, $content);

        // Set restrictive permissions (owner read/write only)
        chmod(ENV_FILE, 0600);

        return $key;
    }

    // Read existing .env file
    $lines = file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            if (trim($key) === 'ENCRYPTION_KEY') {
                return trim($value);
            }
        }
    }

    // If we get here, .env exists but doesn't have ENCRYPTION_KEY
    // This shouldn't happen, but handle it gracefully
    $key = bin2hex(random_bytes(32));
    file_put_contents(ENV_FILE, "\nENCRYPTION_KEY=$key\n", FILE_APPEND);
    return $key;
}

/**
 * Encrypt a value for storage
 * @param string $plaintext The value to encrypt
 * @return string Base64-encoded encrypted value with IV prepended
 */
function encrypt_value($plaintext) {
    if (empty($plaintext)) {
        return '';
    }

    try {
        // Get encryption key
        $key = hex2bin(get_encryption_key());

        // Generate random IV
        $iv_length = openssl_cipher_iv_length(ENCRYPTION_METHOD);
        $iv = random_bytes($iv_length);

        // Encrypt the value
        $ciphertext = openssl_encrypt(
            $plaintext,
            ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            error_log("Encryption failed for value");
            return '';
        }

        // Prepend IV to ciphertext and base64 encode
        return base64_encode($iv . $ciphertext);

    } catch (Exception $e) {
        error_log("Encryption error: " . $e->getMessage());
        return '';
    }
}

/**
 * Decrypt a value from storage
 * @param string $ciphertext The encrypted value
 * @return string Decrypted plaintext or empty string on failure
 */
function decrypt_value($ciphertext) {
    if (empty($ciphertext)) {
        return '';
    }

    try {
        // Get encryption key
        $key = hex2bin(get_encryption_key());

        // Decode from base64
        $data = base64_decode($ciphertext, true);
        if ($data === false) {
            return '';
        }

        // Extract IV and ciphertext
        $iv_length = openssl_cipher_iv_length(ENCRYPTION_METHOD);
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        if (strlen($iv) !== $iv_length) {
            return '';
        }

        // Decrypt
        $plaintext = openssl_decrypt(
            $encrypted,
            ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plaintext === false) {
            error_log("Decryption failed");
            return '';
        }

        return $plaintext;

    } catch (Exception $e) {
        error_log("Decryption error: " . $e->getMessage());
        return '';
    }
}