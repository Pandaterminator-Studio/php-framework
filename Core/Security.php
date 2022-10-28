<?php

namespace Core;

class Security
{
    public function getList(): array
    {
        return hash_algos();
    }

    public function hashFile($file, $algo, $data): string|bool
    {
        file_put_contents($file, $data);
        return hash_file($algo, $file);
    }

    public function genRandomKey(): string|bool
    {
        try {
            $inputKey = random_bytes(32);
            $salt = random_bytes(16);
            return hash_hkdf('sha256', $inputKey, 32, 'aes-256-encryption', $salt);

        } catch (\Exception $e) {
            throw new Exception("Could not generate a random hashed key.");
        }
    }

    public function getHash($algo, $data, $binary = false): string
    {
        return hash($algo, $data, $binary);
    }

    public function getMultiHash($data): string
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