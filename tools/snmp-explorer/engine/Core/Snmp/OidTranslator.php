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

        // Fast-path resolution for common standard MIB symbols
        $cleanSymbolic = strtolower(str_contains($symbolic, '::') ? substr($symbolic, strrpos($symbolic, '::') + 2) : $symbolic);
        static $symbolicToNumeric = [
            'sysdescr' => '.1.3.6.1.2.1.1.1.0',
            'sysuptime' => '.1.3.6.1.2.1.1.3.0',
            'sysname' => '.1.3.6.1.2.1.1.5.0',
            'ifindex' => '.1.3.6.1.2.1.2.2.1.1',
            'ifdescr' => '.1.3.6.1.2.1.2.2.1.2',
            'iftype' => '.1.3.6.1.2.1.2.2.1.3',
            'ifmtu' => '.1.3.6.1.2.1.2.2.1.4',
            'ifspeed' => '.1.3.6.1.2.1.2.2.1.5',
            'ifphysaddress' => '.1.3.6.1.2.1.2.2.1.6',
            'ifadminstatus' => '.1.3.6.1.2.1.2.2.1.7',
            'ifoperstatus' => '.1.3.6.1.2.1.2.2.1.8',
            'ifinoctets' => '.1.3.6.1.2.1.2.2.1.10',
            'ifinucastpkts' => '.1.3.6.1.2.1.2.2.1.11',
            'ifinerrors' => '.1.3.6.1.2.1.2.2.1.14',
            'ifoutoctets' => '.1.3.6.1.2.1.2.2.1.16',
            'ifoutucastpkts' => '.1.3.6.1.2.1.2.2.1.17',
            'ifouterrors' => '.1.3.6.1.2.1.2.2.1.20',
            'ifname' => '.1.3.6.1.2.1.31.1.1.1.1',
            'ifhcinoctets' => '.1.3.6.1.2.1.31.1.1.1.6',
            'ifhcinucastpkts' => '.1.3.6.1.2.1.31.1.1.1.7',
            'ifhcoutoctets' => '.1.3.6.1.2.1.31.1.1.1.10',
            'ifhcoutucastpkts' => '.1.3.6.1.2.1.31.1.1.1.11',
            'ifhighspeed' => '.1.3.6.1.2.1.31.1.1.1.15',
            'ifalias' => '.1.3.6.1.2.1.31.1.1.1.18',
            'hrprocessorload' => '.1.3.6.1.2.1.25.3.3.1.2',
            'hrstoragetype' => '.1.3.6.1.2.1.25.2.3.1.2',
            'hrstoragedescr' => '.1.3.6.1.2.1.25.2.3.1.3',
            'hrstorageallocationunits' => '.1.3.6.1.2.1.25.2.3.1.4',
            'hrstoragesize' => '.1.3.6.1.2.1.25.2.3.1.5',
            'hrstorageused' => '.1.3.6.1.2.1.25.2.3.1.6',
        ];
        if (isset($symbolicToNumeric[$cleanSymbolic])) {
            return $this->numericCache[$symbolic] = $symbolicToNumeric[$cleanSymbolic];
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

        // In-memory fast path for standard ubiquitous OIDs
        $fast = $this->fastTranslate($numericOid);
        if ($fast !== null) {
            return $this->cache[$numericOid] = $fast;
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

    private static int $procRunCount = 0;
    private const int MAX_CLI_RUNS_PER_REQUEST = 10;

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): ?string
    {
        if (!$this->enabled || !is_executable($this->binary)) {
            return null;
        }

        if (self::$procRunCount >= self::MAX_CLI_RUNS_PER_REQUEST) {
            return null;
        }
        self::$procRunCount++;

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
        $deadline = microtime(true) + min(0.3, max(0.05, $this->timeoutSeconds));

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

    /**
     * In-memory fast-path for ubiquitous standard MIB objects (IF-MIB, RFC1213, HOST-RESOURCES)
     * Avoids running CLI snmptranslate subprocess hundreds of times during interface discovery.
     *
     * @return array<string, mixed>|null
     */
    private function fastTranslate(string $numericOid): ?array
    {
        static $map = [
            '.1.3.6.1.2.1.1.1.0' => ['SNMPv2-MIB', 'sysDescr', 'System Description', 'A textual description of the entity.', 'DisplayString', '', 'system', 'string', ''],
            '.1.3.6.1.2.1.1.3.0' => ['SNMPv2-MIB', 'sysUpTime', 'System Uptime', 'The time since the network management portion of the system was re-initialized.', 'TimeTicks', 'timeticks', 'uptime', 'numeric', 'timeticks'],
            '.1.3.6.1.2.1.1.5.0' => ['SNMPv2-MIB', 'sysName', 'System Name', 'An administratively-assigned name for this managed node.', 'DisplayString', '', 'system', 'string', ''],
            '.1.3.6.1.2.1.2.2.1.1' => ['IF-MIB', 'ifIndex', 'Interface Index', 'A unique value, greater than zero, for each interface.', 'InterfaceIndex', '', 'interface', 'numeric', ''],
            '.1.3.6.1.2.1.2.2.1.2' => ['IF-MIB', 'ifDescr', 'Interface Description', 'A textual string containing information about the interface.', 'DisplayString', '', 'interface', 'string', ''],
            '.1.3.6.1.2.1.2.2.1.3' => ['IF-MIB', 'ifType', 'Interface Type', 'The type of interface.', 'IANAifType', '', 'interface', 'numeric', ''],
            '.1.3.6.1.2.1.2.2.1.4' => ['IF-MIB', 'ifMtu', 'Interface MTU', 'The size of the largest packet which can be sent/received on the interface.', 'Integer32', 'bytes', 'interface', 'numeric', 'bytes'],
            '.1.3.6.1.2.1.2.2.1.5' => ['IF-MIB', 'ifSpeed', 'Interface Speed', 'An estimate of the interface bandwidth in bits per second.', 'Gauge32', 'bps', 'interface', 'numeric', 'bps'],
            '.1.3.6.1.2.1.2.2.1.6' => ['IF-MIB', 'ifPhysAddress', 'Interface Physical Address', 'The interface address at the protocol sublayer.', 'PhysAddress', '', 'interface', 'string', ''],
            '.1.3.6.1.2.1.2.2.1.7' => ['IF-MIB', 'ifAdminStatus', 'Interface Admin Status', 'The desired state of the interface.', 'INTEGER', 'status', 'interface', 'numeric', 'status'],
            '.1.3.6.1.2.1.2.2.1.8' => ['IF-MIB', 'ifOperStatus', 'Interface Operational Status', 'The current operational state of the interface.', 'INTEGER', 'status', 'interface', 'numeric', 'status'],
            '.1.3.6.1.2.1.2.2.1.10' => ['IF-MIB', 'ifInOctets', 'Interface In Octets', 'The total number of octets received on the interface.', 'Counter32', 'bytes', 'traffic', 'counter', 'bytes'],
            '.1.3.6.1.2.1.2.2.1.11' => ['IF-MIB', 'ifInUcastPkts', 'Interface In Packets', 'The number of packets delivered to a higher-layer protocol.', 'Counter32', 'pkts', 'interface', 'counter', 'pkts'],
            '.1.3.6.1.2.1.2.2.1.14' => ['IF-MIB', 'ifInErrors', 'Interface In Errors', 'The number of inbound packets that contained errors.', 'Counter32', 'errors', 'interface', 'counter', 'errors'],
            '.1.3.6.1.2.1.2.2.1.16' => ['IF-MIB', 'ifOutOctets', 'Interface Out Octets', 'The total number of octets transmitted out of the interface.', 'Counter32', 'bytes', 'traffic', 'counter', 'bytes'],
            '.1.3.6.1.2.1.2.2.1.17' => ['IF-MIB', 'ifOutUcastPkts', 'Interface Out Packets', 'The total number of packets that higher-level protocols requested be transmitted.', 'Counter32', 'pkts', 'interface', 'counter', 'pkts'],
            '.1.3.6.1.2.1.2.2.1.20' => ['IF-MIB', 'ifOutErrors', 'Interface Out Errors', 'The number of outbound packets that could not be transmitted because of errors.', 'Counter32', 'errors', 'interface', 'counter', 'errors'],
            '.1.3.6.1.2.1.31.1.1.1.1' => ['IF-MIB', 'ifName', 'Interface Name', 'The textual name of the interface.', 'DisplayString', '', 'interface', 'string', ''],
            '.1.3.6.1.2.1.31.1.1.1.6' => ['IF-MIB', 'ifHCInOctets', 'Interface 64-bit In Octets', 'The total number of octets received on the interface (64-bit).', 'Counter64', 'bytes', 'traffic', 'counter', 'bytes'],
            '.1.3.6.1.2.1.31.1.1.1.7' => ['IF-MIB', 'ifHCInUcastPkts', 'Interface 64-bit In Packets', 'The number of packets delivered to a higher-layer protocol (64-bit).', 'Counter64', 'pkts', 'interface', 'counter', 'pkts'],
            '.1.3.6.1.2.1.31.1.1.1.10' => ['IF-MIB', 'ifHCOutOctets', 'Interface 64-bit Out Octets', 'The total number of octets transmitted out of the interface (64-bit).', 'Counter64', 'bytes', 'traffic', 'counter', 'bytes'],
            '.1.3.6.1.2.1.31.1.1.1.11' => ['IF-MIB', 'ifHCOutUcastPkts', 'Interface 64-bit Out Packets', 'The total number of packets requested transmitted (64-bit).', 'Counter64', 'pkts', 'interface', 'counter', 'pkts'],
            '.1.3.6.1.2.1.31.1.1.1.15' => ['IF-MIB', 'ifHighSpeed', 'Interface High Speed', 'An estimate of the interface bandwidth in megabits per second.', 'Gauge32', 'Mbps', 'interface', 'numeric', 'Mbps'],
            '.1.3.6.1.2.1.31.1.1.1.18' => ['IF-MIB', 'ifAlias', 'Interface Alias', 'The description string for this interface assigned by the administrator.', 'DisplayString', '', 'interface', 'string', ''],
            '.1.3.6.1.2.1.25.3.3.1.2' => ['HOST-RESOURCES-MIB', 'hrProcessorLoad', 'Processor Load', 'The average percentage of time that this processor was not idle.', 'Integer32', '%', 'processor', 'numeric', '%'],
            '.1.3.6.1.2.1.25.2.3.1.2' => ['HOST-RESOURCES-MIB', 'hrStorageType', 'Storage Type', 'The type of storage.', 'AutonomousType', '', 'storage', 'string', ''],
            '.1.3.6.1.2.1.25.2.3.1.3' => ['HOST-RESOURCES-MIB', 'hrStorageDescr', 'Storage Description', 'A description of the type and instance of storage.', 'DisplayString', '', 'storage', 'string', ''],
            '.1.3.6.1.2.1.25.2.3.1.4' => ['HOST-RESOURCES-MIB', 'hrStorageAllocationUnits', 'Storage Allocation Units', 'The size of allocation units in bytes.', 'Integer32', 'bytes', 'storage', 'numeric', 'bytes'],
            '.1.3.6.1.2.1.25.2.3.1.5' => ['HOST-RESOURCES-MIB', 'hrStorageSize', 'Storage Size', 'The size of the storage represented by this entry.', 'Integer32', '', 'storage', 'numeric', ''],
            '.1.3.6.1.2.1.25.2.3.1.6' => ['HOST-RESOURCES-MIB', 'hrStorageUsed', 'Storage Used', 'The amount of storage represented by this entry that is allocated.', 'Integer32', '', 'storage', 'numeric', ''],
            // H3C Comware Entity MIB
            '.1.3.6.1.4.1.25506.2.6.1.1.1.1.6' => ['HH3C-ENTITY-EXT-MIB', 'hh3cEntityExtCpuUsage', 'CPU Usage', 'The CPU usage ratio of the entity.', 'Integer32', '%', 'processor', 'numeric', '%'],
            '.1.3.6.1.4.1.25506.2.6.1.1.1.1.8' => ['HH3C-ENTITY-EXT-MIB', 'hh3cEntityExtMemUsage', 'Memory Usage', 'The memory usage ratio of the entity.', 'Integer32', '%', 'memory', 'numeric', '%'],
            '.1.3.6.1.4.1.25506.2.6.1.1.1.1.7' => ['HH3C-ENTITY-EXT-MIB', 'hh3cEntityExtTemperature', 'Temperature', 'The temperature of the entity.', 'Integer32', 'Celsius', 'temperature', 'numeric', 'Celsius'],
            // Huawei Entity MIB
            '.1.3.6.1.4.1.2011.6.3.4.1.2' => ['HUAWEI-DEVICE-MIB', 'hwDevCpuDuty', 'CPU Usage', 'The CPU duty ratio.', 'Integer32', '%', 'processor', 'numeric', '%'],
            '.1.3.6.1.4.1.2011.6.3.4.1.3' => ['HUAWEI-DEVICE-MIB', 'hwDevMemDuty', 'Memory Usage', 'The memory duty ratio.', 'Integer32', '%', 'memory', 'numeric', '%'],
            '.1.3.6.1.4.1.2011.6.3.4.1.4' => ['HUAWEI-DEVICE-MIB', 'hwDevTemperature', 'Temperature', 'The temperature of the device.', 'Integer32', 'Celsius', 'temperature', 'numeric', 'Celsius'],
            // Cisco Process & Memory MIB
            '.1.3.6.1.4.1.9.9.109.1.1.1.1.3' => ['CISCO-PROCESS-MIB', 'cpmCPUTotal5minRev', 'CPU Total 5min', 'Overall CPU busy percentage in the last 5 minute period.', 'Gauge32', '%', 'processor', 'numeric', '%'],
            '.1.3.6.1.4.1.9.9.48.1.1.1.5' => ['CISCO-MEMORY-POOL-MIB', 'ciscoMemoryPoolUsed', 'Memory Pool Used', 'Indicates the number of bytes from the memory pool that are currently in use.', 'Gauge32', 'bytes', 'memory', 'numeric', 'bytes'],
            '.1.3.6.1.4.1.9.9.48.1.1.1.6' => ['CISCO-MEMORY-POOL-MIB', 'ciscoMemoryPoolFree', 'Memory Pool Free', 'Indicates the number of bytes from the memory pool that are currently free.', 'Gauge32', 'bytes', 'memory', 'numeric', 'bytes'],
            // Fortinet FortiGate MIB
            '.1.3.6.1.4.1.12356.101.4.1.1.0' => ['FORTINET-FORTIGATE-MIB', 'fgSysCpuUsage', 'FortiGate CPU Usage', 'Current CPU usage percentage.', 'Gauge32', '%', 'processor', 'numeric', '%'],
            '.1.3.6.1.4.1.12356.101.4.1.2.0' => ['FORTINET-FORTIGATE-MIB', 'fgSysMemUsage', 'FortiGate Memory Usage', 'Current memory usage percentage.', 'Gauge32', '%', 'memory', 'numeric', '%'],
            // MikroTik MIB
            '.1.3.6.1.4.1.14988.1.1.1.2.1.3' => ['MIKROTIK-MIB', 'mtxrProcessorLoad', 'Processor Load', 'The average percentage of time that this processor was not idle.', 'Integer32', '%', 'processor', 'numeric', '%'],
            '.1.3.6.1.4.1.14988.1.1.1.1.1.0' => ['MIKROTIK-MIB', 'mtxrCpuFrequency', 'CPU Frequency', 'CPU frequency in MHz.', 'Integer32', 'MHz', 'processor', 'numeric', 'MHz'],
        ];

        // Exact match
        if (isset($map[$numericOid])) {
            $def = $map[$numericOid];
            return [
                'numeric_oid' => $numericOid,
                'symbolic_oid' => $def[0] . '::' . $def[1] . '.0',
                'mib' => $def[0],
                'object' => $def[1],
                'index' => '0',
                'display_name' => $def[2],
                'description' => $def[3],
                'syntax' => $def[4],
                'units' => $def[5],
                'suggested_class' => $def[6],
                'suggested_type' => $def[7],
                'suggested_unit' => $def[8],
                'translated' => true,
            ];
        }

        // Tabular match: <prefix>.<index>
        $lastDot = strrpos($numericOid, '.');
        if ($lastDot !== false) {
            $prefix = substr($numericOid, 0, $lastDot);
            $index = substr($numericOid, $lastDot + 1);

            if (isset($map[$prefix])) {
                $def = $map[$prefix];
                return [
                    'numeric_oid' => $numericOid,
                    'symbolic_oid' => $def[0] . '::' . $def[1] . '.' . $index,
                    'mib' => $def[0],
                    'object' => $def[1],
                    'index' => $index,
                    'display_name' => $def[2] . ' #' . $index,
                    'description' => $def[3],
                    'syntax' => $def[4],
                    'units' => $def[5],
                    'suggested_class' => $def[6],
                    'suggested_type' => $def[7],
                    'suggested_unit' => $def[8],
                    'translated' => true,
                ];
            }
        }

        return null;
    }
}
