<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Pandora;

use DOMDocument;
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
    public function provision(array $sensorIds, int $agentId, int $interval = 0): array
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
        $customIdsBySensorId = [];

        $moduleNames = [];
        foreach ($sensors as $sensor) {
            $customIdsBySensorId[(int) $sensor['id']] = $this->moduleBuilder->customId((int) $sensor['id']);
            $moduleNames[] = $this->moduleBuilder->moduleName($sensor);
        }

        $existingModules = $this->pandoraRepository->findModulesByCustomIds($agentId, array_values($customIdsBySensorId));
        $existingModulesByName = $this->pandoraRepository->findModulesByNames($agentId, $moduleNames);

        $this->pandoraRepository->beginTransaction();

        try {
            foreach ($sensors as $sensor) {
                $rawValue = $sensor['raw_value'] ?? null;
                $hasRawString = is_string($rawValue) && trim($rawValue) !== '';
                $isNumeric = ($sensor['normalized_value'] ?? null) !== null;

                if (!$isNumeric && !$hasRawString) {
                    $summary['skipped']++;
                    $summary['results'][] = [
                        'sensor_id' => (int) $sensor['id'],
                        'sensor_name' => $sensor['sensor_name'],
                        'status' => 'skipped',
                        'message' => 'Inventory/non-value rows cannot be provisioned.',
                    ];
                    continue;
                }

                $customId = $customIdsBySensorId[(int) $sensor['id']];
                $sensorClass = (string) ($sensor['sensor_class'] ?? '');
                $moduleGroupId = $this->pandoraRepository->resolveModuleGroupId($sensorClass);
                $moduleOverrides = [
                    'id_module_group' => $moduleGroupId,
                ];
                if ($interval > 0) {
                    $moduleOverrides['module_interval'] = $interval;
                }
                $module = $this->moduleBuilder->build($sensor, $agentId, $moduleOverrides);

                $existingModuleId = $existingModules[$customId] ?? $existingModulesByName[$module['nombre']] ?? null;

                if ($existingModuleId !== null) {
                    // Update existing module definition with latest SNMP v3 credentials & OID
                    $this->pandoraRepository->updateModuleDefinition($existingModuleId, $module);
                    $provisionedSensors[] = [(int) $sensor['id'], $agentId, $existingModuleId];
                    $summary['existing']++;
                    $summary['results'][] = [
                        'sensor_id' => (int) $sensor['id'],
                        'sensor_name' => $sensor['sensor_name'],
                        'status' => 'updated',
                        'module_id' => $existingModuleId,
                    ];
                    continue;
                }

                $moduleId = $this->pandoraRepository->insertModule($module, updateCounters: false);
                $provisionedSensors[] = [(int) $sensor['id'], $agentId, $moduleId];

                $summary['created']++;
                $summary['results'][] = [
                    'sensor_id' => (int) $sensor['id'],
                    'sensor_name' => $sensor['sensor_name'],
                    'status' => 'created',
                    'module_id' => $moduleId,
                ];
            }

            $this->pandoraRepository->incrementAgentCounters($agentId, $summary['created']);
            $this->pandoraRepository->commit();
        } catch (Throwable $throwable) {
            $this->pandoraRepository->rollBack();
            throw $throwable;
        }

        $this->sensorRepository->markProvisionedBatch($provisionedSensors);

        return $summary;
    }

    /**
     * @param list<array<string, mixed>> $sensors
     * @param string $agentName
     * @return string XML Document string
     */
    public function generateXml(array $sensors, string $agentName): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $agentData = $dom->createElement('agent_data');
        $agentData->setAttribute('agent_name', $agentName);
        $agentData->setAttribute('timestamp', date('Y-m-d H:i:s'));
        $agentData->setAttribute('version', '1.0');
        $agentData->setAttribute('os_name', 'linux');
        $agentData->setAttribute('os_version', 'snmp-bridge-plugin');
        $dom->appendChild($agentData);

        foreach ($sensors as $sensor) {
            try {
                $moduleData = $this->moduleBuilder->buildForXml($sensor);
                
                $module = $dom->createElement('module');
                
                $name = $dom->createElement('name');
                $name->appendChild($dom->createCDATASection($moduleData['name']));
                $module->appendChild($name);

                $desc = $dom->createElement('description');
                $desc->appendChild($dom->createCDATASection($moduleData['description']));
                $module->appendChild($desc);

                $type = $dom->createElement('type', $moduleData['type']);
                $module->appendChild($type);

                $data = $dom->createElement('data');
                $data->appendChild($dom->createCDATASection($moduleData['data']));
                $module->appendChild($data);

                if (!empty($moduleData['unit'])) {
                    $unit = $dom->createElement('unit');
                    $unit->appendChild($dom->createCDATASection($moduleData['unit']));
                    $module->appendChild($unit);
                }
                
                $group = $dom->createElement('module_group');
                $group->appendChild($dom->createCDATASection($moduleData['module_group']));
                $module->appendChild($group);

                $agentData->appendChild($module);
            } catch (\InvalidArgumentException $e) {
                error_log(sprintf('[SNMP Explorer] Skipping sensor %s: %s', (string) ($sensor['id'] ?? 'unknown'), $e->getMessage()));
            }
        }

        return $dom->saveXML();
    }
}
