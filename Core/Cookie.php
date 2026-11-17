<?php

namespace Core;

class Cookie
{
    private int $defaultExpiry;
    private string $path;
    private string $domain;
    private bool $secure;
    private bool $httponly;
    private array $expiries = [];

    public function __construct(int $defaultExpiry = 86400 * 30, string $path = '/', string $domain = '', ?bool $secure = null, bool $httponly = true)
    {
        $this->setDefaultExpiry($defaultExpiry);
        $this->path = $path;
        $this->domain = $domain;
        $https = $_SERVER['HTTPS'] ?? '';
        $this->secure = $secure ?? (is_string($https) && $https !== '' && $https !== '0' && strtolower($https) !== 'off');
        $this->httponly = $httponly;
    }

    public function add(string $name, string $value, ?int $expiry = null): bool
    {
        $expiry = $expiry ?? ($this->defaultExpiry === 0 ? 0 : time() + $this->defaultExpiry);
        if ($expiry < 0) {
            throw new \InvalidArgumentException('Cookie expiry must be a Unix timestamp or zero for a session cookie.');
        }
        $success = setcookie($name, $value, $this->options($expiry));
        if ($success) {
            $this->expiries[$name] = $expiry === 0 ? null : $expiry;
        }
        return $success;
    }

    private function options(int $expiry): array
    {
        return [
            'expires' => $expiry,
            'path' => $this->path,
            'domain' => $this->domain,
            'secure' => $this->secure,
            'httponly' => $this->httponly,
            'samesite' => 'Strict'
        ];
    }

    public function modify(string $name, string $value, ?int $expiry = null): bool
    {
        return $this->add($name, $value, $expiry);
    }

    public function delete(string $name): bool
    {
        $success = setcookie($name, '', $this->options(time() - 3600));
        if ($success) {
            unset($this->expiries[$name]);
        }
        return $success;
    }

    public function get($name, $default = null)
    {
        $value = $_COOKIE[$name] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function exists($name): bool
    {
        return isset($_COOKIE[$name]) && is_string($_COOKIE[$name]);
    }

    public function checkIfAnyCookie(): bool
    {
        return $this->listCookies() !== [];
    }

    public function listCookies(): array
    {
        return array_filter($_COOKIE, 'is_string');
    }

    public function listCookiesAsString(): string
    {
        $cookies = "";
        foreach ($this->listCookies() as $key => $value) {
            $cookies .= htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' is ' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "<br>\n";
        }
        return $cookies;
    }

    public function clearAll(): void
    {
        foreach (array_keys($this->listCookies()) as $name) {
            $this->delete((string) $name);
        }
    }

    public function setSecure(bool $secure): void
    {
        $this->secure = $secure;
    }

    public function setHttpOnly(bool $httponly): void
    {
        $this->httponly = $httponly;
    }

    public function setDefaultExpiry(int $expiry): void
    {
        if ($expiry < 0) {
            throw new \InvalidArgumentException('The default cookie lifetime cannot be negative.');
        }
        $this->defaultExpiry = $expiry;
    }

    public function getExpiryDate(string $name): ?int
    {
        return $this->expiries[$name] ?? null;
    }
}
