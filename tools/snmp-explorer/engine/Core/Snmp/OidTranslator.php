<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Snmp;

final class OidTranslator
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    /** @var array<string, array<string, string|null>> */
    private array $detailsCache = [];

    /** @var array<string, bool> */
    private array $enterpriseRootCache = [];

    /** @var array<string, string|null> */
    private array $numericCache = [];

    /**
     * @param list<string> $mibDirs
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $binary = '/usr/bin/snmptranslate',
        private readonly array $mibDirs = [],
        private readonly string $mibs = '+ALL',
        private readonly float $timeoutSeconds = 2.0,
    ) {
    }

    /**
     * Resolve symbolic OID (e.g. IF-MIB::ifDescr, cpmCPUTotal5minRev) to numeric OID (.1.3.6.1.2.1.2.2.1.2)
     */
    public function toNumeric(string $symbolic): ?string
    {
        $symbolic = trim($symbolic);
        if ($symbolic === '') {
            return null;
        }

        // Already numeric format (e.g. .1.3.6.1.2.1...)
        if (preg_match('/^\.?[0-9]+(?:\.[0-9]+)*$/', $symbolic)) {
            return SnmpHelper::normalizeOid($symbolic);
        }

        if (array_key_exists($symbolic, $this->numericCache)) {
            return $this->numericCache[$symbolic];
        }

        if (!$this->enabled || !is_executable($this->binary)) {
            return $this->numericCache[$symbolic] = null;
        }

        // First attempt: snmptranslate -On <symbolic>
        $output = $this->run(['-On', $symbolic]);
        $output = $output !== null ? trim($output) : '';

        // Second attempt with random access lookup: snmptranslate -On -IR <symbolic>
        if ($output === '' || !preg_match('/^\.?[0-9]+(?:\.[0-9]+)+$/', $output)) {
            $output = $this->run(['-On', '-IR', $symbolic]);
            $output = $output !== null ? trim($output) : '';
        }

        if ($output !== '' && preg_match('/^\.?[0-9]+(?:\.[0-9]+)+$/', $output)) {
            $numOid = '.' . ltrim($output, '.');
            return $this->numericCache[$symbolic] = $numOid;
        }

        return $this->numericCache[$symbolic] = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function translate(string $oid): array
    {
        $trimmed = trim($oid);
        $symbolicProvided = null;

        // If input contains alphabetic characters (e.g. IF-MIB::ifDescr, hrStorageSize), resolve to numeric first
        if (preg_match('/[a-zA-Z]/', $trimmed)) {
            $resolvedNum = $this->toNumeric($trimmed);
            if ($resolvedNum !== null) {
                $numericOid = $resolvedNum;
                $symbolicProvided = $trimmed;
            } else {
                $numericOid = SnmpHelper::normalizeOid($trimmed);
            }
        } else {
            $numericOid = SnmpHelper::normalizeOid($trimmed);
        }

        if (isset($this->cache[$numericOid])) {
            return $this->cache[$numericOid];
        }

        $symbolicOid = $this->enabled ? ($this->symbolicOid($numericOid) ?: $symbolicProvided) : $symbolicProvided;
        $parts = $this->parseSymbolicOid($symbolicOid, $numericOid);
        $details = $this->enabled && $symbolicOid !== null && $parts['object'] !== null
            ? $this->details($symbolicOid)
            : [];
        $displayName = $this->displayName($parts, $numericOid);
        $suggestion = $this->suggestion($displayName, $symbolicOid, $details);

        return $this->cache[$numericOid] = [
            'numeric_oid' => $numericOid,
            'symbolic_oid' => $symbolicOid,
            'mib' => $parts['mib'],
            'object' => $parts['object'],
            'index' => $parts['index'],
            'display_name' => $displayName,
            'description' => $details['description'] ?? null,
            'syntax' => $details['syntax'] ?? null,
            'units' => $details['units'] ?? null,
            'suggested_class' => $suggestion['class'],
            'suggested_type' => $suggestion['type'],
            'suggested_unit' => $suggestion['unit'],
            'translated' => $symbolicOid !== null && $parts['object'] !== null,
        ];
    }

    private function symbolicOid(string $numericOid): ?string
    {
        $enterpriseId = $this->enterpriseId($numericOid);

        if ($enterpriseId !== null && !$this->enterpriseRootIsTranslated($enterpriseId)) {
            return null;
        }

        $output = $this->run([$numericOid]);
        $output = $output !== null ? trim($output) : '';

        if ($output === '' || $output === $numericOid || str_starts_with($output, '.')) {
            return null;
        }

        if ($enterpriseId !== null && str_starts_with($output, 'SNMPv2-SMI::enterprises.' . $enterpriseId . '.')) {
            return null;
        }

        return $output;
    }

    /**
     * @return array<string, string|null>
     */
    private function details(string $oid): array
    {
        $detailsOid = $this->detailsOid($oid);

        if (isset($this->detailsCache[$detailsOid])) {
            return $this->detailsCache[$detailsOid];
        }

        $output = $this->run(['-Td', $detailsOid]);
        $output = $output !== null ? trim($output) : '';

        if ($output === '') {
            return $this->detailsCache[$detailsOid] = [];
        }

        $details = [
            'description' => null,
            'syntax' => null,
            'units' => null,
        ];

        if (preg_match('/^\s*SYNTAX\s+(.+)$/mi', $output, $match) === 1) {
            $details['syntax'] = trim($match[1]);
        }

        if (preg_match('/^\s*UNITS\s+"?([^"\r\n]+)"?/mi', $output, $match) === 1) {
            $details['units'] = trim($match[1]);
        }

        if (preg_match('/DESCRIPTION\s+"(.+?)"\s*::=/is', $output, $match) === 1) {
            $description = preg_replace('/\s+/', ' ', trim($match[1])) ?? trim($match[1]);
            $details['description'] = substr($description, 0, 240);
        }

        return $this->detailsCache[$detailsOid] = $details;
    }

    private function detailsOid(string $oid): string
    {
        if (preg_match('/^([^:]+::[A-Za-z][A-Za-z0-9_-]*)(?:\..*)?$/', $oid, $match) === 1) {
            return $match[1];
        }

        return $oid;
    }

    /**
     * @return array{mib:string|null,object:string|null,index:string|null}
     */
    private function parseSymbolicOid(?string $symbolicOid, string $numericOid): array
    {
        if ($symbolicOid !== null && preg_match('/^([^:]+)::([A-Za-z][A-Za-z0-9_-]*)(?:\.(.*))?$/', $symbolicOid, $match) === 1) {
            $mib = $match[1];
            $object = $match[2];
            $index = $match[3] ?? null;

            if ($mib === 'SNMPv2-SMI' && $object === 'enterprises') {
                return [
                    'mib' => null,
                    'object' => null,
                    'index' => $this->enterpriseTail($numericOid),
                ];
            }

            return [
                'mib' => $mib,
                'object' => $object,
                'index' => $index !== '' ? $index : null,
            ];
        }

        return [
            'mib' => null,
            'object' => null,
            'index' => $this->enterpriseTail($numericOid),
        ];
    }

    /**
     * @param array{mib:string|null,object:string|null,index:string|null} $parts
     */
    private function displayName(array $parts, string $numericOid): string
    {
        if ($parts['object'] !== null) {
            return $this->humanizeObjectName($parts['object']);
        }

        if (preg_match('/^\.?1\.3\.6\.1\.4\.1\.(\d+)(?:\.(.*))?$/', $numericOid, $match) === 1) {
            $enterprise = $match[1];
            $tail = $match[2] ?? '';

            return trim('Enterprise ' . $enterprise . ($tail !== '' ? ' OID ' . $tail : ''));
        }

        return 'SNMP OID ' . ltrim($numericOid, '.');
    }

    private function humanizeObjectName(string $object): string
    {
        $name = preg_replace('/^(?:iso|org|dod|internet|mgmt|mib2|mib|snmp|enterprises|hw|jnx|cisco|ent|hr|prt)/i', '', $object) ?? $object;
        $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name) ?? $name;
        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = trim($name);

        if ($name === '') {
            $name = $object;
        }

        $upper = [
            'cpu' => 'CPU',
            'dbm' => 'dBm',
            'dns' => 'DNS',
            'if' => 'Interface',
            'ip' => 'IP',
            'mac' => 'MAC',
            'oid' => 'OID',
            'onu' => 'ONU',
            'ont' => 'ONT',
            'olt' => 'OLT',
            'rx' => 'RX',
            'snmp' => 'SNMP',
            'tcp' => 'TCP',
            'tx' => 'TX',
            'udp' => 'UDP',
            'vlan' => 'VLAN',
        ];

        $words = explode(' ', strtolower($name));
        $words = array_map(static fn (string $word): string => $upper[$word] ?? ucfirst($word), $words);

        return implode(' ', $words);
    }

    /**
     * @param array<string, string|null> $details
     * @return array{class:string,type:string,unit:string}
     */
    private function suggestion(string $displayName, ?string $symbolicOid, array $details): array
    {
        $source = strtolower(implode(' ', array_filter([
            $displayName,
            $symbolicOid,
            $details['description'] ?? null,
            $details['syntax'] ?? null,
            $details['units'] ?? null,
        ])));

        $unit = $this->normalizeUnit((string) ($details['units'] ?? ''));
        $class = 'misc';
        $type = 'numeric';

        if (str_contains($source, 'temperature') || str_contains($source, 'thermal')) {
            return ['class' => 'temperature', 'type' => 'temperature', 'unit' => $unit !== '' ? $unit : 'C'];
        }

        if (str_contains($source, 'humidity')) {
            return ['class' => 'humidity', 'type' => 'humidity', 'unit' => $unit !== '' ? $unit : '%'];
        }

        if (str_contains($source, 'fan') || str_contains($source, 'rpm')) {
            return ['class' => 'fan', 'type' => 'fan_speed', 'unit' => $unit !== '' ? $unit : 'rpm'];
        }

        if (str_contains($source, 'voltage') || preg_match('/\bvolt/', $source) === 1) {
            return ['class' => 'voltage', 'type' => 'voltage', 'unit' => $unit !== '' ? $unit : 'V'];
        }

        if (str_contains($source, 'current') || str_contains($source, 'ampere') || str_contains($source, 'bias')) {
            return ['class' => 'current', 'type' => 'current', 'unit' => $unit !== '' ? $unit : 'mA'];
        }

        if (str_contains($source, 'rx power') || str_contains($source, 'tx power') || str_contains($source, 'optical')) {
            return ['class' => 'optical_dom', 'type' => 'optical_power', 'unit' => $unit !== '' ? $unit : 'dBm'];
        }

        if (str_contains($source, 'cpu') || str_contains($source, 'processor')) {
            return ['class' => 'processor', 'type' => 'cpu_usage', 'unit' => $unit !== '' ? $unit : '%'];
        }

        if (str_contains($source, 'memory') || str_contains($source, 'ram')) {
            return ['class' => 'memory', 'type' => 'memory_usage', 'unit' => $unit];
        }

        if (str_contains($source, 'disk') || str_contains($source, 'storage') || str_contains($source, 'filesystem')) {
            return ['class' => 'storage', 'type' => 'storage', 'unit' => $unit];
        }

        if (preg_match('/\bif(?:in|out|admin|oper|descr|name|alias|speed|highspeed|mtu|type)/', $source) === 1
            || str_contains($source, 'interface')) {
            return ['class' => 'interface', 'type' => 'interface_metric', 'unit' => $unit];
        }

        if (str_contains($source, 'status') || str_contains($source, 'state')) {
            $class = 'status';
            $type = 'status';
        }

        return ['class' => $class, 'type' => $type, 'unit' => $unit];
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));

        return match (true) {
            $unit === '' => '',
            str_contains($unit, 'dbm') => 'dBm',
            str_contains($unit, 'celsius'), $unit === 'c' => 'C',
            str_contains($unit, 'fahrenheit'), $unit === 'f' => 'F',
            str_contains($unit, 'percent'), str_contains($unit, '%') => '%',
            str_contains($unit, 'rpm') => 'rpm',
            str_contains($unit, 'millivolt'), $unit === 'mv' => 'mV',
            str_contains($unit, 'volt'), $unit === 'v' => 'V',
            str_contains($unit, 'milliamp'), $unit === 'ma' => 'mA',
            str_contains($unit, 'amp'), $unit === 'a' => 'A',
            str_contains($unit, 'byte') => 'bytes',
            str_contains($unit, 'packet') => 'packets',
            default => trim($unit),
        };
    }

    private function enterpriseTail(string $numericOid): ?string
    {
        if (preg_match('/^\.?1\.3\.6\.1\.4\.1\.\d+\.(.+)$/', $numericOid, $match) === 1) {
            return $match[1];
        }

        $parts = explode('.', trim($numericOid, '.'));

        return $parts !== [] ? end($parts) : null;
    }

    private function enterpriseId(string $numericOid): ?string
    {
        if (preg_match('/^\.?1\.3\.6\.1\.4\.1\.(\d+)(?:\.|$)/', $numericOid, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function enterpriseRootIsTranslated(string $enterpriseId): bool
    {
        if (isset($this->enterpriseRootCache[$enterpriseId])) {
            return $this->enterpriseRootCache[$enterpriseId];
        }

        $rootOid = '.1.3.6.1.4.1.' . $enterpriseId;
        $output = $this->run([$rootOid]);
        $output = $output !== null ? trim($output) : '';

        return $this->enterpriseRootCache[$enterpriseId] = !in_array($output, ['', $rootOid, 'SNMPv2-SMI::enterprises.' . $enterpriseId], true)
            && !str_starts_with($output, '.');
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): ?string
    {
        if (!$this->enabled || !is_executable($this->binary)) {
            return null;
        }

        $command = escapeshellcmd($this->binary) . ' ' . implode(
            ' ',
            array_map(escapeshellarg(...), $arguments),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $env = [];

        if ($this->mibs !== '') {
            $env['MIBS'] = $this->mibs;
        }

        if ($this->mibDirs !== []) {
            $env['MIBDIRS'] = implode(':', $this->mibDirs);
        }

        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $stdout = '';
        $deadline = microtime(true) + max(0.1, $this->timeoutSeconds);

        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                usleep(50000);
                $status = proc_get_status($process);

                if ($status['running']) {
                    proc_terminate($process, 9);
                }

                fclose($pipes[1]);
                proc_close($process);

                return null;
            }

            usleep(10000);
        } while (true);

        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $status = proc_close($process);

        if ($status !== 0) {
            return null;
        }

        return trim($stdout);
    }
}
