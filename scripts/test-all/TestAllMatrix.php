<?php

declare(strict_types=1);

final class TestAllMatrix
{
    /**
     * @return list<array<string, string>>
     */
    public static function sentinel(): array
    {
        return [
            [
                'id' => 'sentinel-unit',
                'php' => '8.4',
                'laravel' => '13.*',
                'testbench' => '11.*',
                'test_suite' => 'Unit',
                'package' => 'Sentinel',
                'database' => 'sqlite',
                'command' => 'test:sentinel:unit:ci',
                'junit' => 'junit-sentinel-unit.xml',
                'log' => 'pest-output-sentinel-unit.txt',
                'artifact_slug' => 'sentinel-unit',
            ],
            [
                'id' => 'sentinel-database',
                'php' => '8.4',
                'laravel' => '13.*',
                'testbench' => '11.*',
                'test_suite' => 'Feature',
                'package' => 'Sentinel',
                'database' => 'mysql',
                'command' => 'test:sentinel:database:ci',
                'junit' => 'junit-sentinel-database.xml',
                'log' => 'pest-output-sentinel-database.txt',
                'artifact_slug' => 'sentinel-database',
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public static function behaviour(): array
    {
        $cells = [];

        foreach (self::frameworks() as $framework) {
            foreach ([
                ['package' => 'Core', 'group' => 'core'],
                ['package' => 'Admin', 'group' => 'admin'],
                ['package' => 'Frontend', 'group' => 'frontend'],
                ['package' => 'Installer', 'group' => 'installer'],
                ['package' => 'Marketplace', 'group' => 'marketplace', 'max_processes' => '1'],
            ] as $package) {
                $slug = strtolower($package['package']);
                $cell = [
                    ...$framework,
                    'id' => $framework['framework_slug'] . '-feature-' . $slug,
                    'test_suite' => 'Feature',
                    'test_suite_slug' => 'feature',
                    'package' => $package['package'],
                    'test_group' => $package['group'],
                    'database' => 'mysql',
                    'command' => 'test:database:package:ci',
                    'junit' => 'junit-feature-' . $package['package'] . '.xml',
                    'log' => 'pest-output-feature-' . $package['package'] . '.txt',
                    'artifact_slug' => $framework['framework_slug'] . '-feature-' . $slug,
                ];

                if (isset($package['max_processes'])) {
                    $cell['max_processes'] = $package['max_processes'];
                }

                $cells[] = $cell;
            }

            $cells[] = [
                ...$framework,
                'id' => $framework['framework_slug'] . '-integration',
                'test_suite' => 'Integration',
                'test_suite_slug' => 'integration',
                'package' => 'All',
                'test_group' => 'unused',
                'database' => 'mysql',
                'command' => 'test:database:ci',
                'junit' => 'junit-integration-All.xml',
                'log' => 'pest-output-integration-All.txt',
                'artifact_slug' => $framework['framework_slug'] . '-integration',
            ];
        }

        return $cells;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function unit(): array
    {
        $cells = [];

        foreach (self::frameworks() as $framework) {
            foreach ([
                ['package' => 'Core', 'group' => 'core'],
                ['package' => 'Admin', 'group' => 'admin'],
                ['package' => 'Frontend', 'group' => 'frontend'],
                ['package' => 'Installer', 'group' => 'installer'],
                ['package' => 'Marketplace', 'group' => 'marketplace'],
            ] as $package) {
                $slug = strtolower($package['package']);
                $cells[] = [
                    ...$framework,
                    'id' => $framework['framework_slug'] . '-unit-' . $slug,
                    'test_suite' => 'Unit',
                    'package' => $package['package'],
                    'test_group' => $package['group'],
                    'package_slug' => $slug,
                    'database' => 'sqlite',
                    'command' => 'test:database:package:ci',
                    'junit' => 'junit-unit-' . $slug . '.xml',
                    'log' => 'pest-output-unit-' . $slug . '.txt',
                    'artifact_slug' => $framework['framework_slug'] . '-unit-' . $slug,
                ];
            }
        }

        return $cells;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function portability(): array
    {
        $framework = self::frameworks()[0];

        return array_values(array_map(
            static fn (array $database): array => [
                ...$framework,
                'id' => $framework['framework_slug'] . '-portability-' . $database['slug'],
                'kind' => 'portability',
                'test_suite' => 'Portability',
                'package' => 'Database',
                'test_group' => 'database-portability',
                'database' => $database['family'],
                'database_driver' => $database['driver'],
                'database_version' => $database['version'],
                'database_image' => $database['image'],
                'database_port' => $database['port'],
                'database_health_command' => $database['health_command'],
                'command' => 'test:database:portability:ci',
                'junit' => 'junit-portability-' . $database['slug'] . '.xml',
                'log' => 'pest-output-portability-' . $database['slug'] . '.txt',
                'artifact_slug' => $framework['framework_slug'] . '-portability-' . $database['slug'],
                'max_processes' => '1',
            ],
            [
                [
                    'slug' => 'sqlite',
                    'family' => 'sqlite',
                    'driver' => 'sqlite',
                    'version' => 'runtime',
                    'image' => 'none',
                    'port' => '0',
                    'health_command' => 'none',
                ],
                [
                    'slug' => 'mysql-8',
                    'family' => 'mysql',
                    'driver' => 'mysql',
                    'version' => '8.0',
                    'image' => 'mysql:8.0',
                    'port' => '3306',
                    'health_command' => 'mysqladmin ping -h 127.0.0.1 -uroot -pcapell-test',
                ],
                [
                    'slug' => 'mariadb-10-5',
                    'family' => 'mariadb',
                    'driver' => 'mariadb',
                    'version' => '10.5',
                    'image' => 'mariadb:10.5',
                    'port' => '3306',
                    'health_command' => 'mariadb-admin ping -h 127.0.0.1 -uroot -pcapell-test',
                ],
                [
                    'slug' => 'postgresql-16',
                    'family' => 'postgresql',
                    'driver' => 'pgsql',
                    'version' => '16',
                    'image' => 'postgres:16',
                    'port' => '5432',
                    'health_command' => 'pg_isready -U postgres',
                ],
            ],
        ));
    }

    /**
     * @return list<array<string, string>>
     */
    public static function all(): array
    {
        return [...self::sentinel(), ...self::behaviour(), ...self::unit(), ...self::portability()];
    }

    /**
     * @return array<string, string>
     */
    public static function find(string $id): array
    {
        foreach (self::all() as $cell) {
            if ($cell['id'] === $id) {
                return $cell;
            }
        }

        throw new InvalidArgumentException(sprintf('Unknown Test All matrix cell [%s].', $id));
    }

    /**
     * @return list<array{php: string, laravel: string, testbench: string, framework_slug: string}>
     */
    private static function frameworks(): array
    {
        return [
            [
                'php' => '8.4',
                'laravel' => '13.*',
                'testbench' => '11.*',
                'framework_slug' => 'l13',
            ],
        ];
    }
}
