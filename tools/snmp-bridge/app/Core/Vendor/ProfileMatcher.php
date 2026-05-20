<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Vendor;

use RuntimeException;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\VendorAdapter\GenericAdapter;

final class ProfileMatcher
{
    public function __construct(private readonly VendorRegistry $registry)
    {
    }

    public function match(string $sysObjectId, string $sysDescr): VendorAdapterInterface
    {
        $normalizedSysObjectId = $this->normalizeOid($sysObjectId);

        foreach ($this->registry->all() as $adapter) {
            foreach ($adapter->sysObjectIds() as $exactOid) {
                if ($normalizedSysObjectId === $this->normalizeOid($exactOid)) {
                    return $adapter;
                }
            }
        }

        foreach ($this->registry->all() as $adapter) {
            $enterpriseOid = $this->normalizeOid($adapter->enterpriseOid());

            if ($enterpriseOid !== '' && str_starts_with($normalizedSysObjectId . '.', $enterpriseOid . '.')) {
                return $adapter;
            }
        }

        foreach ($this->registry->all() as $adapter) {
            foreach ($adapter->sysDescrPatterns() as $pattern) {
                $result = @preg_match($pattern, $sysDescr);

                if ($result === false) {
                    throw new RuntimeException(sprintf('Invalid sysDescr regex for vendor %s: %s', $adapter->name(), $pattern));
                }

                if ($result === 1) {
                    return $adapter;
                }
            }
        }

        return new GenericAdapter();
    }

    private function normalizeOid(string $oid): string
    {
        $oid = trim($oid);
        $oid = preg_replace('/^OID:\s*/i', '', $oid) ?? $oid;
        $oid = preg_replace('/[^0-9.].*$/', '', $oid) ?? $oid;

        return '.' . trim($oid, '.');
    }
}
