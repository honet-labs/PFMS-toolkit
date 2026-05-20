<?php

declare(strict_types=1);

namespace SnmpBridge\Database;

use PDO;

final class InternalDatabase
{
    public function __construct(private readonly ConnectionManager $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->internal();
    }
}
