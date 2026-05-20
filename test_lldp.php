<?php
require_once __DIR__ . '/../../includes/db-connection.php';
header('Content-Type: application/json');

$stmt1 = $pdo->query("SELECT DISTINCT nombre FROM tagente_modulo WHERE nombre LIKE '%lldp%' OR nombre LIKE '%cdp%' LIMIT 20");
$lldp_modules = $stmt1->fetchAll();

$stmt2 = $pdo->query("SELECT a.alias, m.nombre, e.datos 
                      FROM tagente a 
                      JOIN tagente_modulo m ON a.id_agente = m.id_agente 
                      JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                      WHERE m.nombre LIKE '%lldp%' OR m.nombre LIKE '%cdp%' LIMIT 20");
$lldp_data = $stmt2->fetchAll();

echo json_encode([
    'modules' => $lldp_modules,
    'data' => $lldp_data
], JSON_PRETTY_PRINT);
