<?php
namespace NetworkMapping\Engine;

use PDO;

class DatabaseSetup {
    
    /**
     * Initializes any optional auxiliary tables for advanced topology mapping
     * (e.g. topology_mac_table for FDB, topology_arp_table for ARP).
     * 
     * NOTE: We strive to use the existing 'tagente', 'tagente_modulo', 
     * and 'tagente_estado' tables as much as possible to strictly follow 
     * Pandora FMS architecture. These are only supplementary if necessary.
     */
    public static function init(PDO $pdo): void {
        $queries = [
            "CREATE TABLE IF NOT EXISTS topology_mac_table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                port_name VARCHAR(100) NOT NULL,
                mac_address VARCHAR(50) NOT NULL,
                vlan_id INT DEFAULT 1,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_mac (mac_address),
                INDEX idx_agent_port (agent_id, port_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE IF NOT EXISTS topology_arp_table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                agent_id INT NOT NULL,
                ip_address VARCHAR(50) NOT NULL,
                mac_address VARCHAR(50) NOT NULL,
                interface VARCHAR(100),
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ip (ip_address),
                INDEX idx_mac (mac_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ];

        foreach ($queries as $sql) {
            $pdo->exec($sql);
        }
    }
}
