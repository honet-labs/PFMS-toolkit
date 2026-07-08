<?php
header('Content-Type: application/json');
$CONFIG_FILE = __DIR__ . '/metrics_config.json';
if (file_exists($CONFIG_FILE)) {
    echo file_get_contents($CONFIG_FILE);
} else {
    echo json_encode(["error" => "File not found at $CONFIG_FILE"]);
}
exit;
