<?php
namespace NetworkMapping\Engine\Contracts;

use PDO;

interface DiscoveryModuleInterface {
    /**
     * Executes the discovery process for a given agent.
     * @param PDO $pdo The database connection.
     * @param int $agentId The Pandora FMS agent ID.
     * @return array Array of discovered relationships or data.
     */
    public function discover(PDO $pdo, int $agentId): array;
}
