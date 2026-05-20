<?php

declare(strict_types=1);

namespace SnmpBridge\Database;

use InvalidArgumentException;
use PDO;

final class PDOFactory
{
    /**
     * @param array{driver:string,host:string,port:int,database:string,username:string,password:string,charset:string} $config
     */
    public function create(array $config): PDO
    {
        if (($config['driver'] ?? 'mysql') !== 'mysql') {
            throw new InvalidArgumentException('Only MySQL/MariaDB PDO connections are supported.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset'],
        );

        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
