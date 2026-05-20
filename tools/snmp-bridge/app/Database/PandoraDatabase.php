<?php

declare(strict_types=1);

namespace SnmpBridge\Database;

use PDO;

final class PandoraDatabase
{
    public function __construct(private readonly ConnectionManager $connections)
    {
    }

    public function pdo(): PDO
    {
        return $this->connections->pandora();
    }
}
