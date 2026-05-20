<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Snmp;

use RuntimeException;
use SNMP;
use Throwable;

final class SnmpSession
{
    private SNMP $session;

    public function __construct(
        private readonly string $host,
        private readonly string $community,
        private readonly string $version = '2c',
        private readonly int $port = 161,
        private readonly int $timeoutUsec = 1000000,
        private readonly int $retries = 1,
        private readonly int $maxOids = 10,
        private readonly bool $quickPrint = true,
    ) {
        if (!extension_loaded('snmp')) {
            throw new RuntimeException('The php-snmp extension is required.');
        }

        $peer = sprintf('%s:%d', $this->host, $this->port);
        $this->session = new SNMP($this->versionConstant(), $peer, $this->community, $this->timeoutUsec, $this->retries);
        $this->session->exceptions_enabled = SNMP::ERRNO_ANY;
        $this->session->valueretrieval = SNMP_VALUE_PLAIN;
        $this->session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
        $this->session->quick_print = $this->quickPrint;
        $this->session->max_oids = $this->maxOids;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getCommunity(): string
    {
        return $this->community;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function get(string $oid): ?string
    {
        try {
            $value = $this->session->get($oid);
        } catch (Throwable) {
            return null;
        }

        if ($value === false || $value === null) {
            return null;
        }

        return $this->cleanValue((string) $value);
    }

    /**
     * @return array<string, string>
     */
    public function walk(string $oid): array
    {
        try {
            $values = $this->session->walk($oid);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $key => $value) {
            $result[SnmpHelper::normalizeOid((string) $key)] = $this->cleanValue((string) $value);
        }

        return $result;
    }

    public function close(): void
    {
        $this->session->close();
    }

    private function versionConstant(): int
    {
        return match (strtolower($this->version)) {
            '1', 'v1' => SNMP::VERSION_1,
            '2', '2c', 'v2c' => SNMP::VERSION_2C,
            default => throw new RuntimeException('Only SNMP v1 and v2c are supported by this provisioning bridge session.'),
        };
    }

    private function cleanValue(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^(STRING|INTEGER|Gauge32|Counter32|Counter64|OID|Timeticks|Hex-STRING):\s*/i', '', $value) ?? $value;

        return trim($value, "\" \t\n\r\0\x0B");
    }
}
