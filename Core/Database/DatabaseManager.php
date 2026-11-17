<?php

namespace Core\Database;

use InvalidArgumentException;

final class DatabaseManager
{
    private array $connections = [];
    private array $configurations;
    private string $default;

    public function __construct(array $config)
    {
        $configurations = array_key_exists('connections', $config) ? $config['connections'] : ['default' => $config];
        if (!is_array($configurations) || $configurations === []) {
            throw new InvalidArgumentException('Configure at least one named database connection.');
        }
        $this->configurations = $configurations;
        $default = array_key_exists('default', $config) ? $config['default'] : array_key_first($this->configurations);
        if (!is_string($default)) {
            throw new InvalidArgumentException('The default database connection must be named.');
        }
        foreach ($this->configurations as $name => $configuration) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $name) || !is_array($configuration)) {
                throw new InvalidArgumentException('Invalid named database connection.');
            }
        }
        $this->setDefaultConnection($default);
    }

    public function connection(?string $name = null): Connection
    {
        $name ??= $this->default;
        if (!array_key_exists($name, $this->configurations)) {
            throw new InvalidArgumentException('Unknown database connection.');
        }
        return $this->connections[$name] ??= new Connection($this->configurations[$name]);
    }

    public function defaultConnection(): Connection
    {
        return $this->connection();
    }

    public function setDefaultConnection(string $name): void
    {
        if (!array_key_exists($name, $this->configurations)) {
            throw new InvalidArgumentException('Unknown default database connection.');
        }
        $this->default = $name;
    }
}
