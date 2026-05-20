<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Pandora;

use Throwable;
use SnmpBridge\Repository\PandoraRepository;
use SnmpBridge\Repository\SensorInventoryRepository;

final class PandoraProvisioner
{
    public function __construct(
        private readonly PandoraRepository $pandoraRepository,
        private readonly SensorInventoryRepository $sensorRepository,
        private readonly PandoraModuleBuilder $moduleBuilder,
        private readonly PandoraAgentResolver $agentResolver,
    ) {
    }

    /**
     * @param list<int> $sensorIds
     * @return array{created:int,existing:int,skipped:int,results:list<array<string, mixed>>}
     */
    public function provision(array $sensorIds, int $agentId): array
    {
        $this->agentResolver->assertExists($agentId);

        $sensors = $this->sensorRepository->findByIds($sensorIds);
        $summary = [
            'created' => 0,
            'existing' => 0,
            'skipped' => 0,
            'results' => [],
        ];
        $provisionedSensors = [];

        $this->pandoraRepository->beginTransaction();

        try {
            foreach ($sensors as $sensor) {
                if (($sensor['normalized_value'] ?? null) === null) {
                    $summary['skipped']++;
                    $summary['results'][] = [
                        'sensor_id' => (int) $sensor['id'],
                        'sensor_name' => $sensor['sensor_name'],
                        'status' => 'skipped',
                        'message' => 'Inventory/string rows are not Pandora SNMP numeric modules.',
                    ];
                    continue;
                }

                $customId = $this->moduleBuilder->customId((int) $sensor['id']);
                $existingModuleId = $this->pandoraRepository->findModuleByCustomId($agentId, $customId);

                if ($existingModuleId !== null) {
                    $provisionedSensors[] = [(int) $sensor['id'], $agentId, $existingModuleId];
                    $summary['existing']++;
                    $summary['results'][] = [
                        'sensor_id' => (int) $sensor['id'],
                        'sensor_name' => $sensor['sensor_name'],
                        'status' => 'existing',
                        'module_id' => $existingModuleId,
                    ];
                    continue;
                }

                $module = $this->moduleBuilder->build($sensor, $agentId);
                $moduleId = $this->pandoraRepository->insertModule($module);
                $provisionedSensors[] = [(int) $sensor['id'], $agentId, $moduleId];

                $summary['created']++;
                $summary['results'][] = [
                    'sensor_id' => (int) $sensor['id'],
                    'sensor_name' => $sensor['sensor_name'],
                    'status' => 'created',
                    'module_id' => $moduleId,
                ];
            }

            $this->pandoraRepository->commit();
        } catch (Throwable $throwable) {
            $this->pandoraRepository->rollBack();
            throw $throwable;
        }

        foreach ($provisionedSensors as [$sensorId, $resolvedAgentId, $moduleId]) {
            $this->sensorRepository->markProvisioned($sensorId, $resolvedAgentId, $moduleId);
        }

        return $summary;
    }
}
