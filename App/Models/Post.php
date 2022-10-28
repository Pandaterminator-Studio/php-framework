<?php

namespace App\Models;

use PDO;

class Post extends \Core\Model
{

    public static function getAll(){

        try {
            $db = static::getDB();
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $db->query('SELECT * FROM members ORDER BY id');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e){
            echo $e->getMessage();
        }
    }
}