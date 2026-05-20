<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use PDO;

final class DeviceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $device
     */
    public function upsert(array $device): int
    {
        $sql = <<<'SQL'
            INSERT INTO devices (
                ip_address,
                hostname,
                vendor,
                sys_object_id,
                sys_descr,
                snmp_version,
                snmp_port,
                snmp_community,
                last_scanned_at,
                created_at,
                updated_at
            ) VALUES (
                :ip_address,
                :hostname,
                :vendor,
                :sys_object_id,
                :sys_descr,
                :snmp_version,
                :snmp_port,
                :snmp_community,
                NOW(),
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                hostname = VALUES(hostname),
                vendor = VALUES(vendor),
                sys_object_id = VALUES(sys_object_id),
                sys_descr = VALUES(sys_descr),
                snmp_version = VALUES(snmp_version),
                snmp_port = VALUES(snmp_port),
                snmp_community = VALUES(snmp_community),
                last_scanned_at = NOW(),
                updated_at = NOW()
            SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'ip_address' => $device['ip_address'],
            'hostname' => $device['hostname'],
            'vendor' => $device['vendor'],
            'sys_object_id' => $device['sys_object_id'],
            'sys_descr' => $device['sys_descr'],
            'snmp_version' => $device['snmp_version'],
            'snmp_port' => $device['snmp_port'],
            'snmp_community' => $device['snmp_community'],
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($id > 0) {
            return $id;
        }

        $lookup = $this->pdo->prepare('SELECT id FROM devices WHERE ip_address = :ip_address');
        $lookup->execute(['ip_address' => $device['ip_address']]);

        return (int) $lookup->fetchColumn();
    }
}
