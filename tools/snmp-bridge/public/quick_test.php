<?php
require_once __DIR__ . '/../bootstrap/app.php';

try {
    $snmp = new SNMP(SNMP::VERSION_2C, '192.168.10.7', 'public', 1000000, 1);
    $snmp->valueretrieval = SNMP_VALUE_PLAIN;
    $snmp->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
    $sysDescr = $snmp->get('.1.3.6.1.2.1.1.1.0');
    $sysOID = $snmp->get('.1.3.6.1.2.1.1.2.0');
    $snmp->close();
    echo json_encode([
        'success' => true,
        'sysDescr' => $sysDescr ? substr($sysDescr, 0, 80) : 'null',
        'sysOID' => $sysOID,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
