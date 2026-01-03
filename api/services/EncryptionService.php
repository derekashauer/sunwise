<?php
/**
 * Encryption Service for secure API key storage
 * Uses AES-256-GCM for authenticated encryption
 */

class EncryptionService
{
    private const METHOD = 'aes-256-gcm';
    private const IV_LENGTH = 12;   // 96-bit IV for GCM
    private const TAG_LENGTH = 16;  // 128-bit authentication tag

    /**
     * Encrypt a plaintext string
     *
     * @param string $plaintext The data to encrypt
     * @return string Base64-encoded encrypted data (IV + tag + ciphertext)
     * @throws Exception if encryption fails
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();

        // Generate random IV
        $iv = random_bytes(self::IV_LENGTH);

        // Encrypt with authentication tag
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',  // No additional authenticated data
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new Exception('Encryption failed');
        }

        // Combine IV + tag + ciphertext and base64 encode
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt an encrypted string
     *
     * @param string $encrypted Base64-encoded encrypted data
     * @return string The decrypted plaintext
     * @throws Exception if decryption fails
     */
    public static function decrypt(string $encrypted): string
    {
        $key = self::getKey();

        // Decode from base64
        $data = base64_decode($encrypted);
        if ($data === false) {
            throw new Exception('Invalid encrypted data format');
        }

        // Extract IV, tag, and ciphertext
        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($data, self::IV_LENGTH + self::TAG_LENGTH);

        // Decrypt with authentication
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new Exception('Decryption failed - data may be corrupted or key incorrect');
        }

        return $plaintext;
    }

    /**
     * Get the encryption key from environment
     *
     * @return string 32-byte binary key
     * @throws Exception if key is not configured or invalid
     */
    private static function getKey(): string
    {
        $keyHex = getenv('ENCRYPTION_KEY');

        if (!$keyHex) {
            // Try from defined constant
            $keyHex = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : null;
        }

        if (!$keyHex) {
            throw new Exception('ENCRYPTION_KEY not configured');
        }

        // Key should be 64 hex characters (32 bytes)
        if (strlen($keyHex) !== 64) {
            throw new Exception('ENCRYPTION_KEY must be 64 hex characters (32 bytes)');
        }

        return hex2bin($keyHex);
    }

    /**
     * Generate a new encryption key
     *
     * @return string 64-character hex string suitable for ENCRYPTION_KEY
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Mask an API key for display (show last 4 characters)
     *
     * @param string $apiKey The full API key
     * @return string Masked key like "sk-...abcd"
     */
    public static function maskKey(string $apiKey): string
    {
        if (strlen($apiKey) <= 8) {
            return str_repeat('*', strlen($apiKey));
        }

        $prefix = substr($apiKey, 0, 3);
        $suffix = substr($apiKey, -4);
        return $prefix . '...' . $suffix;
    }
}
