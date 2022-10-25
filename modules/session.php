<?php

class session
{

    function __construct() {
        session_start();
    }

    public function Check($session){
        return isset($_SESSION[$session]) && $session != "";
    }

    public function Add($name, $value){
        if(!$this->Check($name)) {
            $_SESSION[$name] = $value;
            return true;
        } else {
            return false;
        }
    }

    public function Remove($name){
        if(!$this->Check($name)) {
            unset($_SESSION[$name]);
            return true;
        } else {
            return false;
        }
    }

    public function Update($name, $value){
        if(!$this->Check($name)) {
            $_SESSION[$name] = $value;
            return true;
        } else {
            return false;
        }
    }

    public function Destroy(){
        session_destroy();
        header("location: index.php");
    }

}