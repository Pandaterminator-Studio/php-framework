<?php

namespace Core;

class Cookie
{
    private $defaultExpiry;
    private $path;
    private $domain;
    private $secure;
    private $httponly;

    public function __construct($defaultExpiry = 86400 * 30, $path = "/", $domain = "", $secure = false, $httponly = true)
    {
        $this->defaultExpiry = $defaultExpiry;
        $this->path = $path;
        $this->domain = $domain;
        $this->secure = $secure;
        $this->httponly = $httponly;
    }

    public function add($name, $value, $expiry = null): bool
    {
        $expiry = $expiry ?? time() + $this->defaultExpiry;
        return setcookie($name, $value, [
            'expires' => $expiry,
            'path' => $this->path,
            'domain' => $this->domain,
            'secure' => $this->secure,
            'httponly' => $this->httponly,
            'samesite' => 'Strict'
        ]);
    }

    public function modify($name, $value, $expiry = null): bool
    {
        return $this->add($name, $value, $expiry);
    }

    public function delete($name): bool
    {
        return setcookie($name, '', time() - 3600, $this->path, $this->domain, $this->secure, $this->httponly);
    }

    public function get($name, $default = null)
    {
        return $_COOKIE[$name] ?? $default;
    }

    public function exists($name): bool
    {
        return isset($_COOKIE[$name]);
    }

    public function checkIfAnyCookie(): bool
    {
        return !empty($_COOKIE);
    }

    public function listCookies(): array
    {
        return $_COOKIE;
    }

    public function listCookiesAsString(): string
    {
        $cookies = "";
        foreach ($_COOKIE as $key => $val) {
            $cookies .= htmlspecialchars($key) . ' is ' . htmlspecialchars($val) . "<br>\n";
        }
        return $cookies;
    }

    public function clearAll(): void
    {
        foreach ($_COOKIE as $key => $value) {
            $this->delete($key);
        }
    }

    public function setSecure($secure): void
    {
        $this->secure = $secure;
    }

    public function setHttpOnly($httponly): void
    {
        $this->httponly = $httponly;
    }

    public function setDefaultExpiry($expiry): void
    {
        $this->defaultExpiry = $expiry;
    }

    public function getExpiryDate($name): ?int
    {
        if (isset($_COOKIE[$name])) {
            return $_COOKIE[$name . '_expiry'] ?? null;
        }
        return null;
    }
}