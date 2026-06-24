<?php
ob_clean();
header('Content-Type: text/plain');

require_once __DIR__ . '/includes/db-connection.php';

echo "=== DATABASE CONNECTION DIAGNOSTICS ===\n";
echo "Active DB Status: " . ($db_status ? "CONNECTED" : "FAILED") . "\n";
if ($db_error) {
    echo "Active DB Error: " . $db_error . "\n";
}

echo "History DB Status: " . ($history_db_status ? "CONNECTED" : "FAILED") . "\n";
if ($pdo_history === null) {
    echo "History DB PDO: NULL\n";
} else {
    echo "History DB PDO: ACTIVE\n";
}

// Inspect tconfig history settings
if ($db_status) {
    echo "\n=== TCONFIG SETTINGS ===\n";
    try {
        $stmt = $pdo->query("SELECT token, value FROM tconfig WHERE token LIKE 'history_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Mask password
            if ($row['token'] === 'history_pass') {
                echo "history_pass: [MASKED]\n";
            } else {
                echo $row['token'] . ": " . $row['value'] . "\n";
            }
        }
    } catch (Exception $e) {
        echo "Error querying tconfig: " . $e->getMessage() . "\n";
    }
}

// Sample a module ID from traffic
if ($db_status) {
    echo "\n=== TRAFFIC MODULES SAMPLE ===\n";
    try {
        // Find some interface modules
        $stmt = $pdo->prepare("SELECT id_agente_modulo, nombre, id_tipo_modulo FROM tagente_modulo WHERE nombre LIKE '%Traffic%' OR nombre LIKE '%CCTV%' LIMIT 5");
        $stmt->execute();
        $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mods as $mod) {
            $id = $mod['id_agente_modulo'];
            echo "Module ID {$id} ({$mod['nombre']}):\n";
            
            // Check active DB count
            $target_table = 'tagente_datos';
            $stType = $pdo->prepare("SELECT t.type FROM ttipo_modulo t JOIN tagente_modulo m ON t.id_tipo_modulo = m.id_tipo_modulo WHERE m.id_agente_modulo = ?");
            $stType->execute([$id]);
            $typeVal = $stType->fetchColumn();
            if ($typeVal !== false) {
                $typeVal = (int)$typeVal;
                if ($typeVal === 0) $target_table = 'tagente_datos';
                elseif ($typeVal === 1) $target_table = 'tagente_datos_string';
                elseif ($typeVal === 2) $target_table = 'tagente_datos_inc';
            }
            echo "  Active table: {$target_table}\n";
            
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM `$target_table` WHERE id_agente_modulo = ?");
            $stmtCount->execute([$id]);
            echo "  Active DB count: " . $stmtCount->fetchColumn() . "\n";
            
            if ($pdo_history !== null) {
                try {
                    $stmtHistCount = $pdo_history->prepare("SELECT COUNT(*) FROM `$target_table` WHERE id_agente_modulo = ?");
                    $stmtHistCount->execute([$id]);
                    echo "  History DB [{$target_table}] count: " . $stmtHistCount->fetchColumn() . "\n";
                } catch (Exception $ex) {
                    echo "  History DB [{$target_table}] error: " . $ex->getMessage() . "\n";
                    
                    // Try fallback
                    try {
                        $stmtHistCount = $pdo_history->prepare("SELECT COUNT(*) FROM tagente_datos WHERE id_agente_modulo = ?");
                        $stmtHistCount->execute([$id]);
                        echo "  History DB [tagente_datos] fallback count: " . $stmtHistCount->fetchColumn() . "\n";
                    } catch (Exception $ex2) {
                        echo "  History DB [tagente_datos] fallback error: " . $ex2->getMessage() . "\n";
                    }
                }
            }
        }
    } catch (Exception $e) {
        echo "Error querying modules: " . $e->getMessage() . "\n";
    }
}
