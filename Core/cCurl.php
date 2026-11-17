<?php

namespace Core;

class cCurl
{
    private static array $defaultOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ];

    public static function callAPI($method, $url, $data = null, $headers = [], $options = []): bool|string
    {
        if (!is_string($method) || !in_array(strtoupper($method), ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            throw new \InvalidArgumentException('Unsupported HTTP request method.');
        }
        self::validateUrl($url);
        if (!is_array($options)) {
            throw new \InvalidArgumentException('cURL options must be an array.');
        }
        foreach ([CURLOPT_CUSTOMREQUEST, CURLOPT_NOBODY, CURLOPT_POST, CURLOPT_HTTPGET, CURLOPT_UPLOAD,
            CURLOPT_POSTFIELDS, CURLOPT_READFUNCTION, CURLOPT_INFILE, CURLOPT_INFILESIZE, CURLOPT_REQUEST_TARGET] as $option) {
            if (array_key_exists($option, $options)) {
                throw new \InvalidArgumentException('Use the method, URL and data arguments to configure the HTTP request.');
            }
        }
        $method = strtoupper($method);
        $formattedHeaders = self::formatHeaders($headers);
        $contentType = 'application/json';
        $hasContentType = false;
        foreach ($formattedHeaders as $header) {
            if (strncasecmp($header, 'Content-Type:', 13) === 0) {
                $hasContentType = true;
                $contentType = strtolower(trim(explode(';', substr($header, 13), 2)[0]));
            }
        }
        if (!$hasContentType) {
            $formattedHeaders[] = 'Content-Type: application/json';
        }
        $curlOptions = self::requestOptions($options);
        if ($method === 'GET' || $method === 'HEAD') {
            if ($data !== null) {
                if (!is_array($data) && !is_object($data)) {
                    throw new \InvalidArgumentException('Query parameters must be an array or object.');
                }
                $query = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
                if ($query !== '') {
                    $parts = explode('#', $url, 2);
                    $separator = str_contains($parts[0], '?') ? (str_ends_with($parts[0], '?') || str_ends_with($parts[0], '&') ? '' : '&') : '?';
                    $url = $parts[0] . $separator . $query . (isset($parts[1]) ? '#' . $parts[1] : '');
                }
            }
            $curlOptions[$method === 'HEAD' ? CURLOPT_NOBODY : CURLOPT_HTTPGET] = true;
        } else {
            if ($method === 'POST') {
                $curlOptions[CURLOPT_POST] = true;
            } else {
                $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
            }
            if ($data !== null) {
                if (!is_string($data) && !is_array($data) && !is_object($data) && !is_scalar($data)) {
                    throw new \InvalidArgumentException('Unsupported request body.');
                }
                $body = is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR);
                if (is_string($data) && $data !== '' && ($contentType === 'application/json' || $contentType === 'text/json' || str_ends_with($contentType, '+json'))) {
                    json_decode($data, false, 512, JSON_THROW_ON_ERROR);
                }
                $curlOptions[CURLOPT_POSTFIELDS] = $body;
            }
        }
        $curlOptions[CURLOPT_URL] = $url;
        $curlOptions[CURLOPT_HTTPHEADER] = $formattedHeaders;
        return self::execute($curlOptions);
    }

    private static function formatHeaders($headers): array
    {
        if (!is_array($headers)) {
            throw new \InvalidArgumentException('HTTP headers must be an array.');
        }
        $formatted = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D', $name)
                || !is_scalar($value) || preg_match('/[\r\n\x00]/', (string) $value)) {
                throw new \InvalidArgumentException('Invalid HTTP header.');
            }
            $formatted[] = $name . ': ' . $value;
        }
        return $formatted;
    }

    private static function validateUrl($url): void
    {
        if (!is_string($url) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \InvalidArgumentException('An HTTP or HTTPS URL is required.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === ''
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('An HTTP or HTTPS URL is required.');
        }
    }

    private static function requestOptions(array $options): array
    {
        $merged = array_replace(self::$defaultOptions, $options);
        foreach ([CURLOPT_CONNECTTIMEOUT, CURLOPT_TIMEOUT, CURLOPT_CONNECTTIMEOUT_MS, CURLOPT_TIMEOUT_MS] as $option) {
            if (array_key_exists($option, $merged) && (!is_int($merged[$option]) || $merged[$option] < 1)) {
                throw new \InvalidArgumentException('HTTP timeouts must be positive integer values.');
            }
        }
        if (!is_int($merged[CURLOPT_MAXREDIRS]) || $merged[CURLOPT_MAXREDIRS] < 0) {
            throw new \InvalidArgumentException('The HTTP redirect limit must be a nonnegative integer.');
        }
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            unset($merged[CURLOPT_PROTOCOLS], $merged[CURLOPT_REDIR_PROTOCOLS]);
            $merged[constant('CURLOPT_PROTOCOLS_STR')] = 'http,https';
            $merged[constant('CURLOPT_REDIR_PROTOCOLS_STR')] = 'http,https';
        } else {
            $merged[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $merged[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        return $merged;
    }

    private static function execute(array $options): bool|string
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new \RuntimeException('The HTTP client could not be initialized.');
        }
        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new \RuntimeException('HTTP request options could not be configured.');
            }
            $response = curl_exec($handle);
            if ($response === false) {
                throw new \RuntimeException('HTTP request failed.', curl_errno($handle));
            }
            return $response;
        } finally {
            curl_close($handle);
        }
    }

    public static function downloadFile($url, $savePath): bool|string
    {
        self::validateUrl($url);
        if (!is_string($savePath) || $savePath === '' || str_contains($savePath, "\0") || str_contains($savePath, '://')) {
            throw new \InvalidArgumentException('A local destination path is required.');
        }
        $directory = dirname($savePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('The download destination is unavailable.');
        }
        $temporary = tempnam($directory, '.iris-download-');
        if ($temporary === false) {
            throw new \RuntimeException('A temporary download file could not be created.');
        }
        $stream = null;
        $handle = null;
        try {
            $stream = fopen($temporary, 'wb');
            $handle = curl_init();
            if ($stream === false || $handle === false) {
                throw new \RuntimeException('The download could not be initialized.');
            }
            $options = self::requestOptions([
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_URL => $url,
                CURLOPT_FILE => $stream,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_FAILONERROR => true,
            ]);
            if (!curl_setopt_array($handle, $options)) {
                throw new \RuntimeException('Download options could not be configured.');
            }
            $result = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($result === false || $status < 200 || $status >= 300) {
                return false;
            }
            if (!fflush($stream)) {
                throw new \RuntimeException('The download could not be saved.');
            }
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $savePath)) {
                throw new \RuntimeException('The download could not be saved.');
            }
            return true;
        } finally {
            if ($handle instanceof \CurlHandle) {
                curl_close($handle);
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function multiRequest($requests): array
    {
        if (!is_array($requests)) {
            throw new \InvalidArgumentException('HTTP requests must be an array.');
        }
        $multiHandle = curl_multi_init();
        $channels = [];
        $attached = [];
        $completed = [];
        try {
            foreach ($requests as $key => $request) {
                if (!is_array($request)) {
                    throw new \InvalidArgumentException('Each HTTP request must contain cURL options.');
                }
                self::validateUrl($request[CURLOPT_URL] ?? null);
                $channel = curl_init();
                if ($channel === false) {
                    throw new \RuntimeException('The HTTP client could not be initialized.');
                }
                $channels[$key] = $channel;
                if (!curl_setopt_array($channel, self::requestOptions($request)) || curl_multi_add_handle($multiHandle, $channel) !== CURLM_OK) {
                    throw new \RuntimeException('The parallel HTTP request could not be configured.');
                }
                $attached[$key] = true;
            }
            do {
                do {
                    $multiResult = curl_multi_exec($multiHandle, $active);
                } while ($multiResult === CURLM_CALL_MULTI_PERFORM);
                if ($multiResult !== CURLM_OK) {
                    throw new \RuntimeException('Parallel HTTP requests failed.', $multiResult);
                }
                while (($information = curl_multi_info_read($multiHandle)) !== false) {
                    if ($information['msg'] === CURLMSG_DONE) {
                        $completed[spl_object_id($information['handle'])] = $information['result'];
                    }
                }
                if ($active > 0 && curl_multi_select($multiHandle, 1.0) === -1) {
                    usleep(1000);
                }
            } while ($active > 0);
            $results = [];
            foreach ($channels as $key => $channel) {
                $result = $completed[spl_object_id($channel)] ?? CURLE_FAILED_INIT;
                $results[$key] = $result === CURLE_OK ? curl_multi_getcontent($channel) : false;
            }
            return $results;
        } finally {
            foreach ($channels as $key => $channel) {
                if (isset($attached[$key])) {
                    curl_multi_remove_handle($multiHandle, $channel);
                }
                curl_close($channel);
            }
            curl_multi_close($multiHandle);
        }
    }

    public static function uploadFile($url, $filePath, $postFields = [], $headers = []): bool|string
    {
        self::validateUrl($url);
        if (!is_string($filePath) || str_contains($filePath, "\0") || str_contains($filePath, '://') || !is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException('A readable local upload file is required.');
        }
        if (!is_array($postFields)) {
            throw new \InvalidArgumentException('Upload fields must be an array.');
        }
        $postFields['file'] = new \CURLFile($filePath);
        return self::execute(self::requestOptions([
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => self::formatHeaders($headers),
        ]));
    }
}
