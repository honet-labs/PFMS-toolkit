<?php
declare(strict_types=1);

/**
 * Autoloader for SnmpBridge namespace in PFMS-Toolkit
 */
spl_autoload_register(function ($class) {
    $prefix = 'SnmpBridge\\';
    $base_dir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
