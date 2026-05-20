CREATE DATABASE IF NOT EXISTS `snmp_bridge`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `snmp_bridge`;

CREATE TABLE IF NOT EXISTS `devices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(100) NOT NULL,
    `hostname` VARCHAR(255) NOT NULL DEFAULT '',
    `vendor` VARCHAR(80) NOT NULL DEFAULT 'Generic',
    `sys_object_id` VARCHAR(255) NOT NULL DEFAULT '',
    `sys_descr` TEXT NULL,
    `snmp_version` VARCHAR(20) NOT NULL DEFAULT '2c',
    `snmp_port` INT UNSIGNED NOT NULL DEFAULT 161,
    `snmp_community` VARCHAR(255) NOT NULL DEFAULT '',
    `last_scanned_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `devices_ip_unique` (`ip_address`),
    KEY `devices_vendor_idx` (`vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sensor_inventory` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` BIGINT UNSIGNED NOT NULL,
    `vendor` VARCHAR(80) NOT NULL,
    `ip_address` VARCHAR(100) NOT NULL,
    `sensor_class` VARCHAR(50) NOT NULL,
    `sensor_name` VARCHAR(255) NOT NULL,
    `sensor_type` VARCHAR(80) NULL,
    `interface_index` INT NULL,
    `interface_name` VARCHAR(255) NULL,
    `entity_index` INT NULL,
    `oid` VARCHAR(512) NOT NULL,
    `raw_value` VARCHAR(255) NULL,
    `normalized_value` DOUBLE NULL,
    `unit` VARCHAR(40) NULL,
    `scale` VARCHAR(40) NULL,
    `precision` INT NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'unknown',
    `metadata_json` JSON NULL,
    `provisioned` TINYINT(1) NOT NULL DEFAULT 0,
    `pandora_agent_id` INT UNSIGNED NULL,
    `pandora_module_id` INT UNSIGNED NULL,
    `provisioned_at` DATETIME NULL,
    `discovered_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sensor_inventory_unique` (`device_id`, `oid`(384), `sensor_name`(191)),
    KEY `sensor_inventory_vendor_ip_idx` (`vendor`, `ip_address`),
    KEY `sensor_inventory_class_idx` (`sensor_class`),
    KEY `sensor_inventory_provisioned_idx` (`provisioned`),
    CONSTRAINT `sensor_inventory_device_fk`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
