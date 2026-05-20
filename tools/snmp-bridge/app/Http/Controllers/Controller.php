<?php

declare(strict_types=1);

namespace SnmpBridge\Http\Controllers;

abstract class Controller
{
    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = []): string
    {
        $viewPath = SNMP_BRIDGE_ROOT . '/app/Http/Views/' . $view . '.php';

        if (!is_file($viewPath)) {
            throw new \RuntimeException('View not found: ' . $view);
        }

        $baseUrl = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        $baseUrl = $baseUrl === '/' ? '' : $baseUrl;

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        $content = ob_get_clean();
        $title = $data['title'] ?? 'SNMP Bridge';

        ob_start();
        require SNMP_BRIDGE_ROOT . '/app/Http/Views/layouts/app.php';

        return (string) ob_get_clean();
    }
}
