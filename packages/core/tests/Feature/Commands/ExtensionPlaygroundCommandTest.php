<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;
use Capell\Core\Contracts\Extensions\RegistersExtensionSetting;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;
use Symfony\Component\Console\Command\Command;

if (! function_exists('makeExtensionPlaygroundPackage')) {
    /**
     * @param  array<string, mixed>  $manifestOverrides
     * @param  array<string, string>  $classes
     */
    function makeExtensionPlaygroundPackage(
        string $packageName,
        array $manifestOverrides = [],
        array $classes = [],
    ): string {
        $directory = sys_get_temp_dir() . '/capell-extension-playground-' . bin2hex(random_bytes(6));
        $namespace = str($packageName)->after('/')->studly()->prepend('Vendor\\')->append('\\')->toString();

        mkdir($directory . '/src/Providers', 0755, true);

        file_put_contents($directory . '/composer.json', json_encode([
            'name' => $packageName,
            'autoload' => [
                'psr-4' => [
                    $namespace => 'src/',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        file_put_contents($directory . '/src/Providers/PackageServiceProvider.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}Providers;

use Illuminate\Support\ServiceProvider;

final class PackageServiceProvider extends ServiceProvider
{
}
PHP);

        foreach ($classes as $relativePath => $contents) {
            $path = $directory . '/' . $relativePath;
            mkdir(dirname($path), 0755, true);
            file_put_contents($path, $contents);
        }

        file_put_contents($directory . '/capell.json', json_encode(
            capellManifestV3Array(
                name: $packageName,
                surfaces: ['admin'],
                namespace: rtrim($namespace, '\\'),
                providers: [
                    'runtime' => [$namespace . 'Providers\\PackageServiceProvider'],
                ],
                overrides: $manifestOverrides,
            ),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));

        return $directory;
    }
}

it('prints a compact extension playground summary', function (): void {
    $directory = makeExtensionPlaygroundPackage(
        'vendor/playground',
        [
            'surfaces' => ['admin', 'frontend', 'console'],
            'contributes' => [
                [
                    'type' => 'route',
                    'class' => 'Vendor\\Playground\\Routes\\PlaygroundRoutes',
                    'surface' => 'frontend',
                    'namePrefix' => 'vendor.playground',
                ],
                [
                    'type' => 'setting',
                    'class' => 'Vendor\\Playground\\Settings\\PlaygroundSettings',
                    'label' => 'Playground settings',
                ],
                [
                    'type' => 'scheduled-job',
                    'class' => 'Vendor\\Playground\\Jobs\\PlaygroundJob',
                    'schedule' => 'daily',
                    'description' => 'Run playground maintenance.',
                ],
            ],
            'database' => [
                'migrations' => true,
                'settings' => true,
                'requiredTables' => ['playground_entries'],
            ],
            'settings' => ['Vendor\\Playground\\Settings\\PlaygroundSettings'],
        ],
        [
            'src/Routes/PlaygroundRoutes.php' => playgroundContributionClass(
                namespace: 'Vendor\\Playground\\Routes',
                class: 'PlaygroundRoutes',
                contract: RegistersExtensionRoute::class,
            ),
            'src/Settings/PlaygroundSettings.php' => playgroundContributionClass(
                namespace: 'Vendor\\Playground\\Settings',
                class: 'PlaygroundSettings',
                contract: RegistersExtensionSetting::class,
            ),
            'src/Jobs/PlaygroundJob.php' => playgroundContributionClass(
                namespace: 'Vendor\\Playground\\Jobs',
                class: 'PlaygroundJob',
                contract: RunsScheduledExtensionJob::class,
            ),
        ],
    );

    artisanCommand('capell:extension-playground', [
        'extension' => $directory,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('vendor/playground')
        ->expectsOutputToContain('routes: 1')
        ->expectsOutputToContain('migrations: yes')
        ->expectsOutputToContain('settings: 1')
        ->expectsOutputToContain('scheduled jobs: 1')
        ->expectsOutputToContain('contributions: 3')
        ->assertExitCode(Command::SUCCESS);
});

it('reports manifest validation failures without trying to summarize an invalid package', function (): void {
    $directory = makeExtensionPlaygroundPackage('vendor/broken-playground', [
        'providers' => [
            'runtime' => ['Vendor\\BrokenPlayground\\Providers\\MissingProvider'],
        ],
    ]);

    artisanCommand('capell:extension-playground', [
        'extension' => $directory,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('cannot be resolved')
        ->assertExitCode(Command::FAILURE);
});

if (! function_exists('playgroundContributionClass')) {
    function playgroundContributionClass(string $namespace, string $class, string $contract): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class {$class} implements \\{$contract}
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP;
    }
}
