-- MySQL dump 10.13  Distrib 8.0.45-36, for Linux (x86_64)
--
-- Host: localhost    Database: snmp_bridge
-- ------------------------------------------------------
-- Server version	8.0.45-36

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!50717 SELECT COUNT(*) INTO @rocksdb_has_p_s_session_variables FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'performance_schema' AND TABLE_NAME = 'session_variables' */;
/*!50717 SET @rocksdb_get_is_supported = IF (@rocksdb_has_p_s_session_variables, 'SELECT COUNT(*) INTO @rocksdb_is_supported FROM performance_schema.session_variables WHERE VARIABLE_NAME=\'rocksdb_bulk_load\'', 'SELECT 0') */;
/*!50717 PREPARE s FROM @rocksdb_get_is_supported */;
/*!50717 EXECUTE s */;
/*!50717 DEALLOCATE PREPARE s */;
/*!50717 SET @rocksdb_enable_bulk_load = IF (@rocksdb_is_supported, 'SET SESSION rocksdb_bulk_load = 1', 'SET @rocksdb_dummy_bulk_load = 0') */;
/*!50717 PREPARE s FROM @rocksdb_enable_bulk_load */;
/*!50717 EXECUTE s */;
/*!50717 DEALLOCATE PREPARE s */;

--
-- Table structure for table `devices`
--

DROP TABLE IF EXISTS `devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hostname` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `vendor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Generic',
  `sys_object_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sys_descr` text COLLATE utf8mb4_unicode_ci,
  `snmp_version` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '2c',
  `snmp_port` int unsigned NOT NULL DEFAULT '161',
  `snmp_community` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_scanned_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `devices_ip_unique` (`ip_address`),
  KEY `devices_vendor_idx` (`vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `devices`
--

LOCK TABLES `devices` WRITE;
/*!40000 ALTER TABLE `devices` DISABLE KEYS */;
/*!40000 ALTER TABLE `devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sensor_inventory`
--

DROP TABLE IF EXISTS `sensor_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sensor_inventory` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `device_id` bigint unsigned NOT NULL,
  `vendor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sensor_class` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sensor_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sensor_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interface_index` int DEFAULT NULL,
  `interface_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_index` int DEFAULT NULL,
  `oid` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `normalized_value` double DEFAULT NULL,
  `unit` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scale` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `precision` int DEFAULT NULL,
  `status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `metadata_json` json DEFAULT NULL,
  `provisioned` tinyint(1) NOT NULL DEFAULT '0',
  `pandora_agent_id` int unsigned DEFAULT NULL,
  `pandora_module_id` int unsigned DEFAULT NULL,
  `provisioned_at` datetime DEFAULT NULL,
  `discovered_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sensor_inventory_unique` (`device_id`,`oid`(384),`sensor_name`(191)),
  KEY `sensor_inventory_vendor_ip_idx` (`vendor`,`ip_address`),
  KEY `sensor_inventory_class_idx` (`sensor_class`),
  KEY `sensor_inventory_provisioned_idx` (`provisioned`),
  CONSTRAINT `sensor_inventory_device_fk` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sensor_inventory`
--

LOCK TABLES `sensor_inventory` WRITE;
/*!40000 ALTER TABLE `sensor_inventory` DISABLE KEYS */;
/*!40000 ALTER TABLE `sensor_inventory` ENABLE KEYS */;
UNLOCK TABLES;
/*!50112 SET @disable_bulk_load = IF (@is_rocksdb_supported, 'SET SESSION rocksdb_bulk_load = @old_rocksdb_bulk_load', 'SET @dummy_rocksdb_bulk_load = 0') */;
/*!50112 PREPARE s FROM @disable_bulk_load */;
/*!50112 EXECUTE s */;
/*!50112 DEALLOCATE PREPARE s */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-08  4:18:56
