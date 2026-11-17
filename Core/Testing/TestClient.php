<?php

namespace Core\Testing;

use Core\Application;

final class TestClient
{
    public function __construct(private readonly Application $application)
    {
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, $headers);
    }

    public function json(string $method, string $uri, array $fields = [], array $headers = []): TestResponse
    {
        $names = array_change_key_case($headers);
        foreach (['accept', 'content-type'] as $name) {
            if (!array_key_exists($name, $names)) {
                $headers[$name] = 'application/json';
            }
        }
        $body = json_encode((object) $fields, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        return $this->request($method, $uri, $headers, $body);
    }

    public function request(string $method, string $uri, array $headers = [], string $rawBody = '', array $cookies = [], array $server = []): TestResponse
    {
        return new TestResponse($this->application->handleHttp(
            $method, $uri, $headers, $rawBody, $cookies, $server + ['REMOTE_ADDR' => '127.0.0.1'],
        ));
    }
}
