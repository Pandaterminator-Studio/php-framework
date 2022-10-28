<?php

namespace App\Models;

use Core\Model;
use Core\Security;
use PDO;

class Post extends Model
{

    public static function getAll(): bool|array
    {
        try {
            $db = static::getDB();
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $db->query('SELECT * FROM members ORDER BY id');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e){
            throw new Exception($e->getMessage());
        }
    }
}