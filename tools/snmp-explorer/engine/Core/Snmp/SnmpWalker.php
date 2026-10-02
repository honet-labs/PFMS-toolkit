<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Snmp;

class SnmpWalker
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
    public function walkUncached(string $oid): array
    {
        return $this->session->walk(SnmpHelper::normalizeOid($oid));
    }

    /**
     * @return array<string, string>
     */
    public function walkIndexed(string $oid): array
    {
        return $this->indexWalk($oid, $this->walk($oid));
    }

    /**
     * @return array<string, string>
     */
    public function walkIndexedUncached(string $oid): array
    {
        return $this->indexWalk($oid, $this->walkUncached($oid));
    }

    /**
     * @return \Generator<string, string>
     */
    public function walkGenerator(string $oid, bool $cache = false): \Generator
    {
        $normalizedOid = SnmpHelper::normalizeOid($oid);

        if ($cache && isset($this->cache[$normalizedOid])) {
            $data = $this->cache[$normalizedOid];
        } else {
            $data = $this->session->walk($normalizedOid);
            if ($cache) {
                $this->cache[$normalizedOid] = $data;
            }
        }

        foreach ($data as $key => $value) {
            yield $key => $value;
        }

        if (!$cache) {
            unset($data);
        }
    }

    /**
     * @return \Generator<string, string>
     */
    public function walkIndexedGenerator(string $oid, bool $cache = false): \Generator
    {
        $baseOid = rtrim(SnmpHelper::normalizeOid($oid), '.');
        
        foreach ($this->walkGenerator($oid, $cache) as $fullOid => $value) {
            $normalizedFullOid = SnmpHelper::normalizeOid($fullOid);
            $prefix = $baseOid . '.';

            if (str_starts_with($normalizedFullOid, $prefix)) {
                $index = substr($normalizedFullOid, strlen($prefix));
            } else {
                $index = SnmpHelper::oidIndex($normalizedFullOid);
            }
            
            yield $index => $value;
        }
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function indexWalk(string $oid, array $values): array
    {
        $baseOid = rtrim(SnmpHelper::normalizeOid($oid), '.');
        $indexed = [];

        foreach ($values as $fullOid => $value) {
            $normalizedFullOid = SnmpHelper::normalizeOid($fullOid);
            $prefix = $baseOid . '.';

            if (str_starts_with($normalizedFullOid, $prefix)) {
                $index = substr($normalizedFullOid, strlen($prefix));
            } else {
                $index = SnmpHelper::oidIndex($normalizedFullOid);
            }
            $indexed[$index] = $value;
        }

        return $indexed;
    }

    public function session(): SnmpSession
    {
        return $this->session;
    }
}
