<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain');

echo "--- STARTING NETWORK TOPOLOGY PANEL DIAGNOSTICS ---\n\n";

// Mock Session for testing without redirecting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['id_usuario'] = 'admin';

echo "1. Checking db-connection.php...\n";
try {
    require_once __DIR__ . '/../../includes/db-connection.php';
    echo "   [OK] db-connection.php loaded successfully.\n";
    if ($db_status) {
        echo "   [OK] Database connected successfully via PDO.\n";
    } else {
        echo "   [WARNING] Database not connected: " . $db_error . "\n";
    }
} catch (Throwable $e) {
    echo "   [FATAL] Error loading db-connection.php: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
}

echo "\n2. Checking Discovery Modules namespace resolution...\n";
try {
    require_once __DIR__ . '/Engine/Contracts/DiscoveryModuleInterface.php';
    require_once __DIR__ . '/Engine/TopologyInferenceEngine.php';
    require_once __DIR__ . '/Engine/Modules/LLDPDiscoveryModule.php';
    require_once __DIR__ . '/Engine/Modules/CDPDiscoveryModule.php';
    require_once __DIR__ . '/Engine/Modules/FDBDiscoveryModule.php';
    echo "   [OK] Discovery modules and engine required successfully.\n";
    
    // Test instantiation
    $lldp = new \NetworkMapping\Engine\Modules\LLDPDiscoveryModule();
    $cdp = new \NetworkMapping\Engine\Modules\CDPDiscoveryModule();
    $fdb = new \NetworkMapping\Engine\Modules\FDBDiscoveryModule();
    $engine = new \NetworkMapping\Engine\TopologyInferenceEngine($pdo);
    echo "   [OK] Discovery modules and engine instantiated successfully.\n";
} catch (Throwable $e) {
    echo "   [FATAL] Namespace or class error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
}

echo "\n3. Testing api-network.php (api=load_config)...\n";
try {
    $_GET['api'] = 'load_config';
    ob_start();
    include __DIR__ . '/api-network.php';
    $output = ob_get_clean();
    echo "   [OK] api-network.php included.\n";
    echo "   [OUTPUT]: " . substr($output, 0, 500) . "\n";
} catch (Throwable $e) {
    ob_get_clean();
    echo "   [FATAL] api-network.php crashed: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
}

echo "\n4. Testing api-topology.php (api=get_topology)...\n";
try {
    $_GET['api'] = 'get_topology';
    $_GET['dash_id'] = 'dash_default';
    $_GET['mode'] = 'layer2';
    ob_start();
    include __DIR__ . '/api-topology.php';
    $output = ob_get_clean();
    echo "   [OK] api-topology.php included.\n";
    echo "   [OUTPUT]: " . substr($output, 0, 500) . "\n";
} catch (Throwable $e) {
    ob_get_clean();
    echo "   [FATAL] api-topology.php crashed: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n";
}

echo "\n--- DIAGNOSTICS COMPLETED ---\n";
?>
