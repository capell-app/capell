<?php

declare(strict_types=1);

final readonly class TestAllDatabaseService
{
    private const string PASSWORD = 'capell-test';

    /**
     * @param  array<string, string>  $cell
     */
    public function __construct(
        private array $cell,
        private string $containerName,
        private string $databaseName,
    ) {
        if (($this->cell['kind'] ?? null) !== 'portability') {
            throw new InvalidArgumentException('Only database portability cells may own a disposable database service.');
        }

        if ($this->isServer() && preg_match('/\Acapell-test-all-\d+-[0-9a-f]{6}-[a-z0-9-]+\z/', $this->containerName) !== 1) {
            throw new InvalidArgumentException('The Test All database container name is unsafe.');
        }

        if ($this->isServer() && preg_match('/\Acapell_test_[a-z0-9_]+\z/', $this->databaseName) !== 1) {
            throw new InvalidArgumentException('The Test All database name is unsafe.');
        }
    }

    public function isServer(): bool
    {
        return ($this->cell['database_image'] ?? 'none') !== 'none';
    }

    /** @return list<string> */
    public function startCommand(): array
    {
        $this->assertServer();
        $environment = $this->containerEnvironment();
        $command = [
            'docker',
            'run',
            '--detach',
            '--rm',
            '--name',
            $this->containerName,
            '--memory=1g',
            '--memory-swap=1g',
            '--health-cmd=' . $this->cell['database_health_command'],
            '--health-interval=2s',
            '--health-timeout=2s',
            '--health-retries=60',
            '--publish',
            '127.0.0.1::' . $this->cell['database_port'],
        ];

        foreach ($environment as $name => $value) {
            $command[] = '--env';
            $command[] = $name . '=' . $value;
        }

        $command[] = $this->cell['database_image'];

        return $command;
    }

    /** @return array<string, string> */
    public function connectionEnvironment(string $publishedPort): array
    {
        $this->assertServer();

        if (preg_match('/\A\d+\z/', $publishedPort) !== 1) {
            throw new InvalidArgumentException('The published Test All database port is invalid.');
        }

        return [
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => $publishedPort,
            'DB_DATABASE' => $this->databaseName,
            'DB_USERNAME' => $this->cell['database'] === 'postgresql' ? 'postgres' : 'root',
            'DB_PASSWORD' => self::PASSWORD,
        ];
    }

    /** @return list<string> */
    public function portCommand(): array
    {
        $this->assertServer();

        return [
            'docker',
            'port',
            $this->containerName,
            $this->cell['database_port'] . '/tcp',
        ];
    }

    /** @return list<string> */
    public function stopCommand(): array
    {
        $this->assertServer();

        return ['docker', 'rm', '--force', $this->containerName];
    }

    /** @return array<string, string> */
    private function containerEnvironment(): array
    {
        return match ($this->cell['database']) {
            'mysql' => [
                'MYSQL_ROOT_PASSWORD' => self::PASSWORD,
                'MYSQL_DATABASE' => $this->databaseName,
            ],
            'mariadb' => [
                'MARIADB_ROOT_PASSWORD' => self::PASSWORD,
                'MARIADB_DATABASE' => $this->databaseName,
            ],
            'postgresql' => [
                'POSTGRES_USER' => 'postgres',
                'POSTGRES_PASSWORD' => self::PASSWORD,
                'POSTGRES_DB' => $this->databaseName,
            ],
            default => throw new InvalidArgumentException(sprintf(
                'Unsupported Test All database service family [%s].',
                $this->cell['database'],
            )),
        };
    }

    private function assertServer(): void
    {
        if (! $this->isServer()) {
            throw new LogicException('SQLite portability cells do not start a database service.');
        }
    }
}
