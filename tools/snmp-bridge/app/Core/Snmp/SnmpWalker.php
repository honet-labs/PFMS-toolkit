<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Snmp;

final class SnmpWalker
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function __construct(private readonly SnmpSession $session)
    {
    }

    public function get(string $oid): ?string
    {
        return $this->session->get($oid);
    }

    public function walkSingle(string $oid): ?string
    {
        return $this->get($oid);
    }

    /**
     * @return array<string, string>
     */
    public function walk(string $oid): array
    {
        $normalizedOid = SnmpHelper::normalizeOid($oid);

        if (!isset($this->cache[$normalizedOid])) {
            $this->cache[$normalizedOid] = $this->session->walk($normalizedOid);
        }

        return $this->cache[$normalizedOid];
    }

    /**
     * @return array<string, string>
     */
    public function walkIndexed(string $oid): array
    {
        $baseOid = rtrim(SnmpHelper::normalizeOid($oid), '.');
        $indexed = [];

        foreach ($this->walk($oid) as $fullOid => $value) {
            $normalizedFullOid = SnmpHelper::normalizeOid($fullOid);
            $prefix = $baseOid . '.';
            $index = str_starts_with($normalizedFullOid, $prefix)
                ? substr($normalizedFullOid, strlen($prefix))
                : SnmpHelper::oidIndex($normalizedFullOid);

            $indexed[$index] = $value;
        }

        return $indexed;
    }

    public function session(): SnmpSession
    {
        return $this->session;
    }
}
