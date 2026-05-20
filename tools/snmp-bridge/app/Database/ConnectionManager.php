<?php

declare(strict_types=1);

namespace SnmpBridge\Database;

use InvalidArgumentException;
use PDO;

final class ConnectionManager
{
    /** @var array<string, PDO> */
    private array $connections = [];

    /**
     * @param array<string, array<string, mixed>> $config
     */
    public function __construct(
        private readonly PDOFactory $factory,
        private readonly array $config,
    ) {
    }

    public function internal(): PDO
    {
        return $this->connection('internal');
    }

    public function pandora(): PDO
    {
        return $this->connection('pandora');
    }

    public function connection(string $name): PDO
    {
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        if (!isset($this->config[$name])) {
            throw new InvalidArgumentException(sprintf('Database connection "%s" is not configured.', $name));
        }

        $this->connections[$name] = $this->factory->create($this->config[$name]);

        return $this->connections[$name];
    }
}
