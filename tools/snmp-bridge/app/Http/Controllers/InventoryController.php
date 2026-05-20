<?php

declare(strict_types=1);

namespace SnmpBridge\Http\Controllers;

use SnmpBridge\Repository\AgentRepository;
use SnmpBridge\Repository\SensorInventoryRepository;

final class InventoryController extends Controller
{
    public function __construct(
        private readonly SensorInventoryRepository $sensors,
        private readonly AgentRepository $agents,
    ) {
    }

    public function index(): string
    {
        $filters = [
            'vendor' => trim((string) ($_GET['vendor'] ?? '')),
            'ip_address' => trim((string) ($_GET['ip_address'] ?? '')),
        ];

        return $this->render('inventory/index', [
            'title' => 'Inventory Review',
            'sensors' => $this->sensors->all($filters),
            'vendors' => $this->sensors->vendors(),
            'agents' => $this->agents->all(),
            'filters' => $filters,
        ]);
    }
}
