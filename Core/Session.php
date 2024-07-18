<?php

namespace Core;

class Session
{

    function __construct()
    {
        session_start();
    }

    public static function Check($session): bool
    {
        if(isset($_SESSION[$session]) && ($session != "")){
            return true;
        } else {
            return false;
        }
    }

    public static function List(): array {
        var_dump($_SESSION);
    }

    public static function Add($name, $value): bool
    {
        if (!self::Check($name)) {
            $_SESSION[$name] = $value;
            return true;
        } else {
            return false;
        }
    }

    public static function Remove($name): bool
    {
        if (!self::Check($name)) {
            unset($_SESSION[$name]);
            return true;
        } else {
            return false;
        }
    }

    public static function Update($name, $value): bool
    {
        if (!self::Check($name)) {
            $_SESSION[$name] = $value;
            return true;
        } else {
            return false;
        }
    }

    public static function Destroy(): void
    {
        session_destroy();
        header("location: index.php");
    }

}