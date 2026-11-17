<?php

namespace Core;

class Session
{
    public function __construct()
    {
        self::start();
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
            throw new \RuntimeException('A secure session could not be started.');
        }
        if (ini_set('session.use_strict_mode', '1') === false ||
            ini_set('session.use_only_cookies', '1') === false ||
            ini_set('session.use_trans_sid', '0') === false) {
            throw new \RuntimeException('Secure session configuration is unavailable.');
        }
        $https = $_SERVER['HTTPS'] ?? '';
        $secure = is_string($https) && $https !== '' && $https !== '0' && strtolower($https) !== 'off';
        if (!session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]) || !session_start()) {
            throw new \RuntimeException('A secure session could not be started.');
        }
    }

    public static function Check($session): bool
    {
        self::start();
        return is_string($session) && $session !== '' && array_key_exists($session, $_SESSION);
    }

    public static function List(): array
    {
        self::start();
        return $_SESSION;
    }

    public static function Add($name, $value): bool
    {
        if (!is_string($name) || $name === '' || self::Check($name)) {
            return false;
        }
        $_SESSION[$name] = $value;
        return true;
    }

    public static function Remove($name): bool
    {
        if (!self::Check($name)) {
            return false;
        }
        unset($_SESSION[$name]);
        return true;
    }

    public static function Update($name, $value): bool
    {
        if (!self::Check($name)) {
            return false;
        }
        $_SESSION[$name] = $value;
        return true;
    }

    public static function regenerate(): void
    {
        self::start();
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('The session could not be regenerated.');
        }
        unset($_SESSION['csrf_token'], $_SESSION['_iris_integrity']);
    }

    public static function Destroy(): void
    {
        self::start();
        $_SESSION = [];
        $parameters = session_get_cookie_params();
        if (ini_get('session.use_cookies') && !setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'],
            'domain' => $parameters['domain'],
            'secure' => $parameters['secure'],
            'httponly' => $parameters['httponly'],
            'samesite' => $parameters['samesite'] ?? 'Lax'
        ])) {
            throw new \RuntimeException('The session cookie could not be removed.');
        }
        if (!session_destroy()) {
            throw new \RuntimeException('The session could not be destroyed.');
        }
        session_id('');
    }
}
