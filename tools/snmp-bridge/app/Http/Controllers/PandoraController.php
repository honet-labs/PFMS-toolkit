<?php

declare(strict_types=1);

namespace SnmpBridge\Http\Controllers;

use Throwable;
use SnmpBridge\Core\Pandora\PandoraProvisioner;

final class PandoraController extends Controller
{
    public function __construct(private readonly PandoraProvisioner $provisioner)
    {
    }

    public function provision(): string
    {
        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $sensorIds = array_values(array_filter(
            array_map('intval', (array) ($_POST['sensor_ids'] ?? [])),
            static fn (int $id): bool => $id > 0,
        ));

        try {
            $result = $this->provisioner->provision($sensorIds, $agentId);
            $error = null;
        } catch (Throwable $throwable) {
            $result = null;
            $error = $throwable->getMessage();
        }

        return $this->render('inventory/provision_result', [
            'title' => 'Pandora Provisioning',
            'result' => $result,
            'error' => $error,
        ]);
    }
}
