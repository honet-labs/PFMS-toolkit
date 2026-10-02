<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Vendor;

final readonly class VendorCapability
{
    public function __construct(
        public bool $supportsOpticalDom,
        public bool $supportsEnvironment,
        public bool $supportsGpon,
        public bool $requiresEntityMapping,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'supportsOpticalDom' => $this->supportsOpticalDom,
            'supportsEnvironment' => $this->supportsEnvironment,
            'supportsGpon' => $this->supportsGpon,
            'requiresEntityMapping' => $this->requiresEntityMapping,
        ];
    }
}
