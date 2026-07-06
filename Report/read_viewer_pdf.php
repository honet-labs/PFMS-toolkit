<?php
// Dynamically locate includes/db-connection.php by searching parent directories upwards
$dir = __DIR__;
while ($dir !== '/' && $dir !== '.' && !file_exists($dir . '/includes/db-connection.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) break;
    $dir = $parent;
}
require_once $dir . '/includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['id_usuario'] ?? 0;

// Security Check: Ensure valid Pandora FMS session
if (empty($user_id)) {
    header("Location: /pandora_console/index.php");
    exit;
}

$files = [
    'pdf_viewer' => '/var/www/html/pandora_console/enterprise/operation/reporting/reporting_viewer_pdf.php',
    'csv_viewer' => '/var/www/html/pandora_console/enterprise/operation/reporting/reporting_viewer_csv.php',
    'reporting_viewer' => '/var/www/html/pandora_console/enterprise/operation/reporting/reporting_viewer.php'
];

foreach ($files as $name => $path) {
    echo "<h1>File: $name ($path)</h1>";
    if (file_exists($path)) {
        echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc; max-height:400px; overflow:auto;'>" . htmlspecialchars(file_get_contents($path)) . "</pre>";
    } else {
        echo "<p style='color:red;'>File not found</p>";
    }
}
?>
