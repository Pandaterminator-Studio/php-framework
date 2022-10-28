<?php

namespace Core;
use PDO;
use App\Config;

class Model
{
    protected static function getDB(){
        static $db = null;
        if($db === null){
            $dsn = 'mysql:host='.Config::DB_HOST.';dbname='.Config::DB_NAME.';charset=utf8;port='. Config::DB_PORT;
            $db  = new PDO($dsn, Config::DB_USER, Config::DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $db;
        }
    }
}