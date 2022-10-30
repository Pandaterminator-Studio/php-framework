<?php

namespace Core;

class Session
{

    function __construct()
    {
        session_start();
    }

    public function Check($session): bool
    {
        return isset($_SESSION[$session]) && $session != "";
    }

    public function Add($name, $value): bool
    {
        if (!$this->Check($name)) {
            $_SESSION[$name] = $value;
            return true;
        } else {
            return false;
        }
    }

    public function Remove($name): bool
    {
        if (!$this->Check($name)) {
            unset($_SESSION[$name]);
            return true;
        } else {
            return false;
        }
    }

    public function Update($name, $value): bool
    {
        if (!$this->Check($name)) {
            $_SESSION[$name] = $value;
            return true;
        } else {
            return false;
        }
    }

    public function Destroy(): void
    {
        session_destroy();
        header("location: index.php");
    }

}