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
        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivLength);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decryptData($encryptedData, $key): string
    {
        $data = base64_decode($encryptedData);
        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
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
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCSRFToken($token): bool
    {
        return hash_equals($_SESSION['csrf_token'], $token);
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