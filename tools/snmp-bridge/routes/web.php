<?php

declare(strict_types=1);

use SnmpBridge\Http\Controllers\InventoryController;
use SnmpBridge\Http\Controllers\PandoraController;
use SnmpBridge\Http\Controllers\ScanController;

return [
    'GET /' => [InventoryController::class, 'index'],
    'GET /inventory' => [InventoryController::class, 'index'],
    'GET /scan' => [ScanController::class, 'index'],
    'POST /scan' => [ScanController::class, 'store'],
    'POST /pandora/provision' => [PandoraController::class, 'provision'],
];
