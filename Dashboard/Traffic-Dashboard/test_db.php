<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../../includes/db-connection.php';

echo "=== TEST DB FOR MODULE 13953 ===\n";

// 1. Get module details
$st = $pdo->prepare("SELECT * FROM tagente_modulo WHERE id_agente_modulo = 13953");
$st->execute();
print_r($st->fetch(PDO::FETCH_ASSOC));

// 2. Query active DB tables
$tables = ['tagente_datos', 'tagente_datos_inc', 'tagente_datos_string'];
foreach ($tables as $tbl) {
    try {
        $st = $pdo->prepare("SELECT utimestamp, datos FROM `$tbl` WHERE id_agente_modulo = 13953 ORDER BY utimestamp DESC LIMIT 5");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo "Active DB Table `$tbl`:\n";
        print_r($rows);
    } catch (Exception $e) {
        echo "Error active DB `$tbl`: " . $e->getMessage() . "\n";
    }
}

// 3. Query history DB tables
if ($history_pdo !== null) {
    foreach ($tables as $tbl) {
        try {
            $st = $history_pdo->prepare("SELECT utimestamp, datos FROM `$tbl` WHERE id_agente_modulo = 13953 ORDER BY utimestamp DESC LIMIT 5");
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            echo "History DB Table `$tbl`:\n";
            print_r($rows);
        } catch (Exception $e) {
            echo "Error history DB `$tbl`: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "History DB is NULL\n";
}

// 4. Run get_module_history_data
$start = time() - 86400;
$end = time();
echo "Running get_module_history_data...\n";
$res = get_module_history_data($pdo, $history_pdo, 13953, $start, $end, 20, 'ASC');
print_r($res);
