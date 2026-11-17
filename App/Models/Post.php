<?php

namespace App\Models;

use Core\Model;
use PDO;

class Post extends Model
{

    public static function getAll(): bool|array
    {
        $db = static::getDB();
        $stmt = $db->query('SELECT * FROM members ORDER BY id');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
