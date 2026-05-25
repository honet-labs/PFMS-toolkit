<?php
namespace NetworkMapping\Engine\Contracts;

use PDO;

interface DiscoveryModuleInterface {
    /**
     * Executes the discovery logic for a specific protocol (LLDP, CDP, FDB, etc.)
     * 
     * @param PDO $pdo The existing Pandora FMS database connection
     * @param array $targetAgents Optional array of agent IDs to restrict discovery (empty = all)
     * @return array Returns an array of discovered edges:
     *               [
     *                  ['source' => 123, 'target' => 456, 'type' => 'LLDP', 'label' => 'Gi0/1 <-> Gi0/2'],
     *                  ...
     *               ]
     */
    public function discover(PDO $pdo, array $targetAgents = []): array;

    /**
     * Returns the priority weight of the module.
     * Lower numbers indicate higher reliability (e.g., LLDP/CDP = 10, FDB = 50, ARP = 90)
     */
    public function getPriority(): int;
}
