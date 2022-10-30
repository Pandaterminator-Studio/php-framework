<?php

namespace Core;

class Cookie
{

    public function Add($name, $value): bool
    {
        return setcookie($name, $value, time() + (86400 * 30), "/");
    }

    public function Modify($name, $value): bool
    {
        return setcookie($name, $value, time() + (86400 * 30), "/");
    }

    public function Delete($name): bool
    {
        return  setcookie($name, "", time() - 3600);
    }

    public function CheckIfAnyCookie(): bool
    {
        return count($_COOKIE) > 0;
    }

    public function ListCookies(): string
    {
        $cookies = "";
        foreach ($_COOKIE as $key => $val) $cookies .= $key . ' is ' . $val . "<br>\n";
        return $cookies;
    }
}