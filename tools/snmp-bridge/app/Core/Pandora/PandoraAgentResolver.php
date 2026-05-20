<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Pandora;

use InvalidArgumentException;
use SnmpBridge\Repository\AgentRepository;

final class PandoraAgentResolver
{
    public function __construct(private readonly AgentRepository $agents)
    {
    }

    public function assertExists(int $agentId): void
    {
        if (!$this->agents->exists($agentId)) {
            throw new InvalidArgumentException('Selected Pandora agent does not exist.');
        }
    }
}
