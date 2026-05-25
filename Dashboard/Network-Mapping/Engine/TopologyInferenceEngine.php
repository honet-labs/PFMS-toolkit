<?php
namespace NetworkMapping\Engine;

use NetworkMapping\Engine\Contracts\DiscoveryModuleInterface;
use PDO;

class TopologyInferenceEngine {
    private PDO $pdo;
    private array $modules = [];
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    public function registerModule(DiscoveryModuleInterface $module): void {
        $this->modules[] = $module;
        // Ensure modules are sorted by priority ascending
        usort($this->modules, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }
    
    /**
     * Infer topology edges for the specified agents.
     * Deduplicates edges using priority (e.g., if LLDP finds a link, ignore FDB for that link).
     */
    public function inferTopology(array $targetAgents = []): array {
        $allEdges = [];
        
        foreach ($this->modules as $module) {
            $discovered = $module->discover($this->pdo, $targetAgents);
            foreach ($discovered as $edge) {
                $src = (string)$edge['source'];
                $tgt = (string)$edge['target'];
                
                // Undirected graph key for deduplication
                $hashKey = $src < $tgt ? "{$src}_{$tgt}" : "{$tgt}_{$src}";
                
                // Only add if not already discovered by a higher priority module
                if (!isset($allEdges[$hashKey])) {
                    $allEdges[$hashKey] = [
                        'id' => "auto_{$hashKey}",
                        'source' => $src,
                        'target' => $tgt,
                        'label' => $edge['label'] ?? '',
                        'type' => $edge['type'],
                        'weight' => $module->getPriority()
                    ];
                }
            }
        }
        
        return array_values($allEdges);
    }
}
