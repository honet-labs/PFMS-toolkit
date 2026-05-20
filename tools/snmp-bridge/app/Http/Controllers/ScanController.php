<?php

declare(strict_types=1);

namespace SnmpBridge\Http\Controllers;

use Throwable;
use SnmpBridge\Core\Snmp\SnmpScanner;

final class ScanController extends Controller
{
    public function __construct(private readonly SnmpScanner $scanner)
    {
    }

    public function index(): string
    {
        return $this->render('scan/index', [
            'title' => 'Scan Device',
            'defaults' => [
                'version' => '2c',
                'community' => 'public',
                'port' => 161,
            ],
        ]);
    }

    public function store(): string
    {
        $ipAddress = trim((string) ($_POST['ip_address'] ?? ''));

        if ($ipAddress === '') {
            return $this->render('scan/index', [
                'title' => 'Scan Device',
                'error' => 'Device IP or hostname is required.',
                'defaults' => $_POST,
            ]);
        }

        try {
            $result = $this->scanner->scan([
                'ip_address' => $ipAddress,
                'community' => $_POST['community'] ?? null,
                'version' => $_POST['version'] ?? null,
                'port' => $_POST['port'] ?? null,
            ]);
        } catch (Throwable $throwable) {
            return $this->render('scan/index', [
                'title' => 'Scan Device',
                'error' => $throwable->getMessage(),
                'defaults' => $_POST,
            ]);
        }

        return $this->render('scan/result', [
            'title' => 'Scan Result',
            'result' => $result,
        ]);
    }
}
