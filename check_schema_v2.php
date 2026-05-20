<?php
require_once __DIR__ . '/includes/db-connection.php';
$stmt = $pdo->query("DESC tagente_modulo");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
$out = [];
foreach($columns as $c) { $out[] = $c['Field']; }
file_put_contents('schema_result.txt', implode("\n", $out));
echo "DONE";
?>
