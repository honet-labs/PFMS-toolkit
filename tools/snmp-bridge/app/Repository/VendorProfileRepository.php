<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use PDO;
use RuntimeException;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Vendor\VendorCapability;
use SnmpBridge\VendorAdapter\DatabaseVendorAdapter;

final class VendorProfileRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function match(string $sysObjectId, string $sysDescr): ?VendorAdapterInterface
    {
        $normalizedOid = $this->normalizeOid($sysObjectId);

        $exact = $this->matchExactSysObjectId($normalizedOid);
        if ($exact !== null) {
            return $exact;
        }

        $enterprise = $this->matchEnterpriseWildcard($normalizedOid);
        if ($enterprise !== null) {
            return $enterprise;
        }

        return $this->matchSysDescr($sysDescr);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allProfiles(): array
    {
        $statement = $this->pdo->query(
            'SELECT * FROM vendor_profiles WHERE active = 1 ORDER BY display_name ASC'
        );

        return $statement->fetchAll();
    }

    private function matchExactSysObjectId(string $sysObjectId): ?VendorAdapterInterface
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT vp.*, vso.model_profile_id, vmp.model_name
                FROM vendor_profile_sysobjectids vso
                INNER JOIN vendor_profiles vp ON vp.id = vso.vendor_profile_id
                LEFT JOIN vendor_model_profiles vmp ON vmp.id = vso.model_profile_id
                WHERE vp.active = 1
                  AND vso.active = 1
                  AND vso.sys_object_id = :sys_object_id
                ORDER BY vso.priority ASC
                LIMIT 1
                SQL
        );
        $statement->execute(['sys_object_id' => $sysObjectId]);
        $profile = $statement->fetch();

        return $profile === false ? null : $this->hydrateAdapter($profile);
    }

    private function matchEnterpriseWildcard(string $sysObjectId): ?VendorAdapterInterface
    {
        $statement = $this->pdo->query(
            <<<'SQL'
                SELECT *
                FROM vendor_profiles
                WHERE active = 1
                  AND enterprise_oid <> ''
                ORDER BY LENGTH(enterprise_oid) DESC
                SQL
        );

        foreach ($statement->fetchAll() as $profile) {
            $enterpriseOid = $this->normalizeOid((string) $profile['enterprise_oid']);

            if ($enterpriseOid !== '.' && str_starts_with($sysObjectId . '.', $enterpriseOid . '.')) {
                return $this->hydrateAdapter($profile);
            }
        }

        return null;
    }

    private function matchSysDescr(string $sysDescr): ?VendorAdapterInterface
    {
        $statement = $this->pdo->query(
            <<<'SQL'
                SELECT vp.*, vsp.model_profile_id, vmp.model_name, vsp.pattern
                FROM vendor_profile_sysdescr_patterns vsp
                INNER JOIN vendor_profiles vp ON vp.id = vsp.vendor_profile_id
                LEFT JOIN vendor_model_profiles vmp ON vmp.id = vsp.model_profile_id
                WHERE vp.active = 1
                  AND vsp.active = 1
                ORDER BY vsp.priority ASC, vp.display_name ASC
                SQL
        );

        foreach ($statement->fetchAll() as $profile) {
            $pattern = (string) $profile['pattern'];
            $result = @preg_match($pattern, $sysDescr);

            if ($result === false) {
                throw new RuntimeException(sprintf('Invalid vendor catalog sysDescr regex: %s', $pattern));
            }

            if ($result === 1) {
                return $this->hydrateAdapter($profile);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function hydrateAdapter(array $profile): DatabaseVendorAdapter
    {
        $profileId = (int) $profile['id'];
        $modelProfileId = isset($profile['model_profile_id']) ? (int) $profile['model_profile_id'] : null;

        return new DatabaseVendorAdapter(
            name: (string) $profile['display_name'],
            enterpriseOid: (string) $profile['enterprise_oid'],
            sysObjectIds: $this->sysObjectIds($profileId),
            sysDescrPatterns: $this->sysDescrPatterns($profileId),
            capabilities: new VendorCapability(
                supportsOpticalDom: (bool) $profile['supports_optical_dom'],
                supportsEnvironment: (bool) $profile['supports_environment'],
                supportsGpon: (bool) $profile['supports_gpon'],
                requiresEntityMapping: (bool) $profile['requires_entity_mapping'],
            ),
            entityMappingStrategy: (string) $profile['entity_mapping_strategy'],
            discoveryOids: $this->discoveryOids($profileId, $modelProfileId),
            modelName: isset($profile['model_name']) ? (string) $profile['model_name'] : null,
        );
    }

    /**
     * @return list<string>
     */
    private function sysObjectIds(int $profileId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sys_object_id FROM vendor_profile_sysobjectids WHERE vendor_profile_id = :id AND active = 1 ORDER BY priority ASC'
        );
        $statement->execute(['id' => $profileId]);

        return array_map(static fn (array $row): string => (string) $row['sys_object_id'], $statement->fetchAll());
    }

    /**
     * @return list<string>
     */
    private function sysDescrPatterns(int $profileId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pattern FROM vendor_profile_sysdescr_patterns WHERE vendor_profile_id = :id AND active = 1 ORDER BY priority ASC'
        );
        $statement->execute(['id' => $profileId]);

        return array_map(static fn (array $row): string => (string) $row['pattern'], $statement->fetchAll());
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function discoveryOids(int $profileId, ?int $modelProfileId): array
    {
        $sql = <<<'SQL'
            SELECT capability, sensor_name, oid, unit, scale, precision, sensor_type
            FROM vendor_sensor_oid_profiles
            WHERE vendor_profile_id = :profile_id
              AND active = 1
              AND (model_profile_id IS NULL OR model_profile_id = :model_profile_id)
            ORDER BY capability ASC, priority ASC, sensor_name ASC
            SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'profile_id' => $profileId,
            'model_profile_id' => $modelProfileId,
        ]);

        $groups = [];
        foreach ($statement->fetchAll() as $row) {
            $capability = (string) $row['capability'];
            $groups[$capability][] = [
                'name' => (string) $row['sensor_name'],
                'oid' => (string) $row['oid'],
                'unit' => (string) $row['unit'],
                'scale' => (string) $row['scale'],
                'precision' => (int) $row['precision'],
                'sensor_type' => (string) $row['sensor_type'],
            ];
        }

        return $groups;
    }

    private function normalizeOid(string $oid): string
    {
        $oid = trim($oid);
        $oid = preg_replace('/^OID:\s*/i', '', $oid) ?? $oid;
        $oid = preg_replace('/[^0-9.].*$/', '', $oid) ?? $oid;

        return '.' . trim($oid, '.');
    }
}
