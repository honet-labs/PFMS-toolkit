<?php
require_once __DIR__ . '/includes/db-connection.php';
$stmt = $pdo->query("DESC tagente_modulo");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($columns, JSON_PRETTY_PRINT);
?>
