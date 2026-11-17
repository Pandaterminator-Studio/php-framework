<?php
namespace Core;

class Security
{
    public static function getList(): array
    {
        return hash_algos();
    }

    public static function getHash($algo, $data, $binary = false): string
    {
        return hash($algo, $data, $binary);
    }

    public static function getMultiHash($data): string
    {
        $result = "";
        foreach (hash_algos() as $v) {
            $r = hash($v, $data, false);
            $result .= $v . ' ' . strlen($r) . ' ' . $r . '<br />';
        }
        return $result;
    }

    public static function generateRandomToken($length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    public static function encryptData($data, $key): string
    {
        if (!is_string($data) || !is_string($key) || strlen($key) < 32) {
            throw new \InvalidArgumentException('Encryption requires string data and a key of at least 32 bytes.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt(
            $data,
            'aes-256-gcm',
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'iris:v1:aes-256-gcm',
            16
        );
        if (!is_string($encrypted) || strlen($tag) !== 16) {
            throw new \RuntimeException('Data encryption failed.');
        }
        return 'iris:v1:' . base64_encode($nonce . $tag . $encrypted);
    }

    public static function decryptData($encryptedData, $key): string
    {
        if (!is_string($key) || strlen($key) < 32) {
            throw new \InvalidArgumentException('Decryption requires a key of at least 32 bytes.');
        }
        if (!is_string($encryptedData) || !str_starts_with($encryptedData, 'iris:v1:')) {
            throw new \UnexpectedValueException('Unsupported or invalid encrypted data.');
        }
        $encoded = substr($encryptedData, 8);
        $data = base64_decode($encoded, true);
        if (!is_string($data) || strlen($data) < 28 || base64_encode($data) !== $encoded) {
            throw new \UnexpectedValueException('Unsupported or invalid encrypted data.');
        }
        $decrypted = openssl_decrypt(
            substr($data, 28),
            'aes-256-gcm',
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            substr($data, 0, 12),
            substr($data, 12, 16),
            'iris:v1:aes-256-gcm'
        );
        if (!is_string($decrypted)) {
            throw new \UnexpectedValueException('Encrypted data failed authentication.');
        }
        return $decrypted;
    }

    public static function hashPassword($password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function verifyPassword($password, $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function generateCSRFToken(): string
    {
        Session::start();
        $token = $_SESSION['csrf_token'] ?? null;
        if (!is_string($token) || strlen($token) !== 64 || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCSRFToken($token): bool
    {
        Session::start();
        $storedToken = $_SESSION['csrf_token'] ?? null;
        return is_string($token) && strlen($token) === 64 &&
            is_string($storedToken) && strlen($storedToken) === 64 &&
            preg_match('/\A[a-f0-9]{64}\z/', $token) === 1 &&
            hash_equals($storedToken, $token);
    }

    public static function sanitizeInput($input): string
    {
        return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
    }

    public static function generateSecureFileName($filename): string
    {
        $info = pathinfo($filename);
        $ext = empty($info['extension']) ? '' : '.' . $info['extension'];
        return bin2hex(random_bytes(16)) . $ext;
    }

    public static function isStrongPassword($password): bool
    {
        // At least 8 characters long
        // Contains at least one uppercase letter, one lowercase letter, one number, and one special character
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).{8,}$/', $password) === 1;
    }
}
