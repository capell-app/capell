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
    public static function all(): array
    {
        return [...self::sentinel(), ...self::behaviour(), ...self::unit()];
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
