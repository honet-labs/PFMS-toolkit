<?php
namespace NetworkMapping\Engine;

use PDO;

class DatabaseSetup {
    public static function initialize(PDO $pdo) {
        $queries = [
            "CREATE TABLE IF NOT EXISTS topology_edges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_agent_id INT NOT NULL,
                target_agent_id INT NOT NULL,
                source_port VARCHAR(255),
                target_port VARCHAR(255),
                relationship_type VARCHAR(50),
                protocol VARCHAR(50),
                discovered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_link (source_agent_id, target_agent_id, source_port)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            "CREATE TABLE IF NOT EXISTS topology_nodes_ext (
                agent_id INT PRIMARY KEY,
                device_type VARCHAR(100),
                vendor VARCHAR(100),
                layer_info VARCHAR(50),
                last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE IF NOT EXISTS topology_mac_table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                port_name VARCHAR(255),
                mac_address VARCHAR(50),
                vlan VARCHAR(20),
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            "CREATE TABLE IF NOT EXISTS topology_arp_table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                ip_address VARCHAR(50),
                mac_address VARCHAR(50),
                interface VARCHAR(255),
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ];

        foreach ($queries as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // Ignore if exists or permissions issue for now, will log in production
                error_log("Topology DB Init Error: " . $e->getMessage());
            }
        }
    }
}
