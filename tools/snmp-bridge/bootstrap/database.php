<?php

declare(strict_types=1);

use SnmpBridge\Database\ConnectionManager;
use SnmpBridge\Database\PDOFactory;

return new ConnectionManager(new PDOFactory(), require dirname(__DIR__) . '/config/database.php');
