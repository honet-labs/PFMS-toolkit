<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use RuntimeException;

final readonly class DatabaseProfileRepository
{
    public function __construct(
        private string $configPath,
        private string $examplePath,
    ) {
    }

    /**
     * @return list<array{id:string,label:string,host:string,user:string,database:string,password:string}>
     */
    public function all(): array
    {
        return $this->profiles();
    }

    /**
     * @return array{id:string,label:string,host:string,user:string,database:string,password:string}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->profiles() as $profile) {
            if ($profile['id'] === $id) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @return array{id:string,label:string,host:string,user:string,database:string,password:string}
     */
    public function add(string $label, string $host, string $user, string $database, string $password): array
    {
        $profile = [
            'label' => trim($label) !== '' ? trim($label) : trim($database) . ' @ ' . trim($host),
            'host' => trim($host),
            'user' => trim($user),
            'database' => trim($database),
            'password' => $password,
        ];

        $this->validateProfile($profile);

        $profiles = $this->profiles();
        $profiles[] = $this->withId($profile);
        $this->save($profiles);

        return $this->withId($profile);
    }

    public function configPath(): string
    {
        return $this->configPath;
    }

    /**
     * @return list<array{id:string,label:string,host:string,user:string,database:string,password:string}>
     */
    private function profiles(): array
    {
        $this->seedConfigIfMissing();
        $payload = json_decode((string) file_get_contents($this->configPath), true);

        if (!is_array($payload) || !is_array($payload['databases'] ?? null)) {
            throw new RuntimeException('Invalid database profile config: ' . $this->configPath);
        }

        $profiles = [];

        foreach ($payload['databases'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $profile = [
                'label' => trim((string) ($entry['label'] ?? '')),
                'host' => trim((string) ($entry['host'] ?? '')),
                'user' => trim((string) ($entry['user'] ?? '')),
                'database' => trim((string) ($entry['database'] ?? '')),
                'password' => (string) ($entry['password'] ?? ''),
            ];

            if ($this->isUsable($profile)) {
                $profile['label'] = $profile['label'] !== '' ? $profile['label'] : $profile['database'];
                $profiles[] = $this->withId($profile);
            }
        }

        return $profiles;
    }

    /**
     * @param list<array{id:string,label:string,host:string,user:string,database:string,password:string}> $profiles
     */
    private function save(array $profiles): void
    {
        $this->ensureConfigDirectory();
        $rows = array_map(
            static fn (array $profile): array => [
                'label' => $profile['label'],
                'host' => $profile['host'],
                'user' => $profile['user'],
                'database' => $profile['database'],
                'password' => $profile['password'],
            ],
            $profiles,
        );
        $payload = json_encode(['databases' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents($this->configPath, $payload . PHP_EOL, LOCK_EX);
        $this->protectConfigFile();
    }

    private function seedConfigIfMissing(): void
    {
        $this->ensureConfigDirectory();

        if (is_file($this->configPath)) {
            return;
        }

        $source = is_file($this->examplePath)
            ? (string) file_get_contents($this->examplePath)
            : '{"databases":[]}';

        file_put_contents($this->configPath, $source, LOCK_EX);
        $this->protectConfigFile();
    }

    private function ensureConfigDirectory(): void
    {
        $directory = dirname($this->configPath);

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create database profile directory: ' . $directory);
        }

        @chmod($directory, 0750);
        @chgrp($directory, $this->storageGroup());
    }

    private function protectConfigFile(): void
    {
        @chgrp($this->configPath, $this->storageGroup());
        @chmod($this->configPath, 0640);
    }

    private function storageGroup(): string
    {
        return (string) ($_ENV['SNMP_BRIDGE_STORAGE_GROUP'] ?? $_SERVER['SNMP_BRIDGE_STORAGE_GROUP'] ?? getenv('SNMP_BRIDGE_STORAGE_GROUP') ?: 'apache');
    }

    /**
     * @param array{label:string,host:string,user:string,database:string,password:string} $profile
     */
    private function validateProfile(array $profile): void
    {
        if (!$this->isUsable($profile) || $profile['password'] === '') {
            throw new RuntimeException('Host, database user, database name, and password are required.');
        }
    }

    /**
     * @param array{label:string,host:string,user:string,database:string,password:string} $profile
     */
    private function isUsable(array $profile): bool
    {
        return $profile['host'] !== '' && $profile['user'] !== '' && $profile['database'] !== '';
    }

    /**
     * @param array{label:string,host:string,user:string,database:string,password:string} $profile
     * @return array{id:string,label:string,host:string,user:string,database:string,password:string}
     */
    private function withId(array $profile): array
    {
        $id = hash('sha256', implode("\0", [
            $profile['label'],
            $profile['host'],
            $profile['user'],
            $profile['database'],
        ]));

        return ['id' => substr($id, 0, 16)] + $profile;
    }
}
