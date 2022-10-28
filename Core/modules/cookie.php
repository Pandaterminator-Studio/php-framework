<?php

namespace Core\modules;
class cookie
{

    public function Add($name, $value)
    {
        setcookie($name, $value, time() + (86400 * 30), "/");
    }

    public function Modify($name, $value)
    {
        setcookie($name, $value, time() + (86400 * 30), "/");
    }

    public function Delete($name)
    {
        setcookie($name, "", time() - 3600);
    }

    public function CheckIfAnyCookie()
    {
        if (count($_COOKIE) > 0) {
            return true;
        } else {
            return false;
        }
    }

    public function ListCookies()
    {
        $cookies = "";
        foreach ($_COOKIE as $key => $val) {
            $cookies .= $key . ' is ' . $val . "<br>\n";
        }
        return $cookies;
    }
}