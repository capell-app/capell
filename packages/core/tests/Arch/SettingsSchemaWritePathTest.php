<?php

declare(strict_types=1);

it('keeps settings schema registry writes behind the canonical registrars', function (): void {
    $repositoryRoot = dirname(__DIR__, 4);
    $allowedWriters = [
        'packages/admin/src/Support/Bridges/AdminBridgeRegistrar.php',
        'packages/core/src/Support/Packages/PackageSurfaceRegistrar.php',
    ];
    $writers = [];

    foreach (settingsSchemaProductionPhpPaths($repositoryRoot) as $path) {
        $contents = (string) file_get_contents($path);

        if (! str_contains($contents, 'SettingsSchemaRegistry')) {
            continue;
        }

        if (settingsSchemaSourceWritesToRegistry($contents)) {
            $writers[] = str_replace($repositoryRoot . '/', '', $path);
        }
    }

    sort($writers);

    expect($writers)->toBe($allowedWriters);
});

it('recognises resolved and injected settings registry write idioms', function (): void {
    $resolved = <<<'PHP'
        use Capell\Core\Support\Settings\SettingsSchemaRegistry;
        $registry = resolve(SettingsSchemaRegistry::class);
        $registry->register('group', Schema::class);
        PHP;
    $injected = <<<'PHP'
        public function __construct(private SettingsSchemaRegistry $settings) {}
        $this->settings->register('group', Schema::class);
        PHP;

    expect(settingsSchemaSourceWritesToRegistry($resolved))->toBeTrue()
        ->and(settingsSchemaSourceWritesToRegistry($injected))->toBeTrue();
});

/**
 * @return list<string>
 */
function settingsSchemaProductionPhpPaths(string $repositoryRoot): array
{
    $paths = [];
    $packages = new DirectoryIterator($repositoryRoot . '/packages');

    foreach ($packages as $package) {
        $sourceRoot = $package->getPathname() . '/src';

        if ($package->isDot() || ! is_dir($sourceRoot)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
    }

    sort($paths);

    return $paths;
}

/**
 * Find variables resolved from or typed as the settings registry.
 *
 * @return list<string>
 */
function settingsSchemaRegistryVariables(string $contents): array
{
    preg_match_all(
        '/SettingsSchemaRegistry\s+\$(\w+)|\$(\w+)\s*=\s*(?:resolve|app)\(\s*SettingsSchemaRegistry::class/',
        $contents,
        $matches,
    );

    $variables = collect([...$matches[1], ...$matches[2]])
        ->filter()
        ->unique()
        ->values()
        ->all();

    return array_values($variables);
}

function settingsSchemaSourceWritesToRegistry(string $contents): bool
{
    if (preg_match('/->(?:registerSettingsClass|registerMetadata|replace|remove|removeGroup)\s*\(/', $contents) === 1) {
        return true;
    }

    foreach (settingsSchemaRegistryVariables($contents) as $variable) {
        if (preg_match(
            sprintf('/(?:\$this->|\$)%s->register\s*\(/', preg_quote($variable, '/')),
            $contents,
        ) === 1) {
            return true;
        }
    }

    return false;
}
