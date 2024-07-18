<?php

namespace Core;

class cCurl
{
    private static array $defaultOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ];

    public static function callAPI($method, $url, $data = null, $headers = [], $options = []): bool|string
    {
        $curl = curl_init();
        $method = strtoupper($method);

        $curlOptions = self::$defaultOptions + $options;

        switch ($method) {
            case "POST":
                $curlOptions[CURLOPT_POST] = 1;
                if ($data) {
                    $curlOptions[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data) : $data;
                }
                break;
            case "PUT":
                $curlOptions[CURLOPT_CUSTOMREQUEST] = "PUT";
                if ($data) {
                    $curlOptions[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data) : $data;
                }
                break;
            case "DELETE":
                $curlOptions[CURLOPT_CUSTOMREQUEST] = "DELETE";
                if ($data) {
                    $curlOptions[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data) : $data;
                }
                break;
            case "PATCH":
                $curlOptions[CURLOPT_CUSTOMREQUEST] = "PATCH";
                if ($data) {
                    $curlOptions[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data) : $data;
                }
                break;
            default: // GET
                if ($data) {
                    $url = sprintf("%s?%s", $url, http_build_query($data));
                }
        }

        $curlOptions[CURLOPT_URL] = $url;

        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        $curlOptions[CURLOPT_HTTPHEADER] = self::formatHeaders($headers);

        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            throw new \Exception("cURL Error: " . $err);
        }

        return $response;
    }

    private static function formatHeaders($headers): array
    {
        return array_map(function ($key, $value) {
            return "$key: $value";
        }, array_keys($headers), $headers);
    }

    public static function downloadFile($url, $savePath): bool|string
    {
        $fp = fopen($savePath, 'w+');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $result = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return $result;
    }

    public static function multiRequest($requests): array
    {
        $multiHandle = curl_multi_init();
        $channels = [];

        foreach ($requests as $key => $request) {
            $channels[$key] = curl_init();
            curl_setopt_array($channels[$key], $request);
            curl_multi_add_handle($multiHandle, $channels[$key]);
        }

        $active = 0;
        do {
            $mrc = curl_multi_exec($multiHandle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($multiHandle) == -1) {
                usleep(100);
            }
            do {
                $mrc = curl_multi_exec($multiHandle, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }

        $results = [];
        foreach ($channels as $key => $channel) {
            $results[$key] = curl_multi_getcontent($channel);
            curl_multi_remove_handle($multiHandle, $channel);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    public static function uploadFile($url, $filePath, $postFields = [], $headers = []): bool|string
    {
        $file = new \CURLFile($filePath);
        $postFields['file'] = $file;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::formatHeaders($headers));

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \Exception("cURL Error: " . $err);
        }

        return $response;
    }
}