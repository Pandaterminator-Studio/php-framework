<?php

namespace Core;

class Security
{
    public static function getList(): array
    {
        return hash_algos();
    }

    public static function getHash($algo, $data, $binary = false): string
    {
        return hash($algo, $data, $binary);
    }

    public static function getMultiHash($data): string
    {
        $result = "";
        foreach (hash_algos() as $v) {
            $r = hash($v, $data, false);
            //printf("%-12s %3d %s\n", $v, strlen($r), $r);
            $result .= $v . ' ' . strlen($r) . ' ' . $r . '<br />';
        }
        return $result;
    }
}