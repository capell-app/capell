<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Symfony\Component\Console\Command\Command;

if (! function_exists('makeExtensionAuditPackage')) {
    /**
     * @param  array<string, mixed>  $manifestOverrides
     * @param  array<string, string>  $classes
     */
    function makeExtensionAuditPackage(
        string $packageName,
        array $manifestOverrides = [],
        array $classes = [],
    ): string {
        $directory = sys_get_temp_dir() . '/capell-extension-audit-' . bin2hex(random_bytes(6));
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

it('lists manifest errors with package names and manifest paths', function (): void {
    $directory = makeExtensionAuditPackage('vendor/broken', [
        'providers' => [
            'runtime' => ['Vendor\\Broken\\Providers\\MissingProvider'],
        ],
    ]);

    artisanCommand('capell:extension-audit', ['path' => $directory])
        ->expectsOutputToContain('vendor/broken')
        ->expectsOutputToContain($directory . '/capell.json')
        ->expectsOutputToContain('cannot be resolved')
        ->assertExitCode(Command::FAILURE);
});

it('passes valid extension manifests without errors', function (): void {
    $directory = makeExtensionAuditPackage('vendor/valid');

    artisanCommand('capell:extension-audit', ['path' => $directory])
        ->expectsOutputToContain('No extension contract errors found.')
        ->assertExitCode(Command::SUCCESS);
});

it('accepts both current and previous minor extension API constraints', function (string $constraint): void {
    $directory = makeExtensionAuditPackage('vendor/api-compatible-' . str_replace(['^', '.'], ['', '-'], $constraint), [
        'capellApiVersion' => $constraint,
    ]);

    $messages = collect(AuditExtensionContractsAction::run($directory))->pluck('message')->all();

    expect($messages)->not->toContain('Manifest capellApiVersion does not allow the current Capell API.');
})->with(['^1.0', '^1.1']);

it('fails unsafe public cache declarations for frontend contributions', function (): void {
    $directory = makeExtensionAuditPackage(
        'vendor/frontend',
        [
            'surfaces' => ['frontend'],
            'contributes' => [
                [
                    'type' => 'frontend-component',
                    'class' => 'Vendor\\Frontend\\Components\\PublicCard',
                    'component' => 'public-card',
                ],
            ],
            'performance' => [
                'cacheTags' => ['frontend'],
                'cacheSafety' => [
                    'cacheable' => true,
                    'variesBy' => ['auth'],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [
                        ['model' => 'Vendor\\Frontend\\Models\\Entry', 'events' => ['saved']],
                    ],
                    'queueInvalidation' => true,
                ],
            ],
        ],
        [
            'src/Components/PublicCard.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\Frontend\Components;

use Capell\Core\Contracts\Extensions\RegistersExtensionFrontendComponent;

final class PublicCard implements RegistersExtensionFrontendComponent
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP,
        ],
    );

    artisanCommand('capell:extension-audit', ['path' => $directory])
        ->expectsOutputToContain('unsafe public cache')
        ->assertExitCode(Command::FAILURE);
});

it('audits content widgets as cacheable public frontend output', function (): void {
    $directory = makeExtensionAuditPackage(
        'vendor/content-widget-cache',
        [
            'contributes' => [[
                'type' => 'content-widget',
                'class' => 'Vendor\\ContentWidgetCache\\Widgets\\HeroWidget',
                'key' => 'vendor.hero',
            ]],
            'performance' => [
                'cacheTags' => ['content-widget'],
                'cacheSafety' => [
                    'cacheable' => true,
                    'variesBy' => ['auth'],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [
                        ['model' => 'Vendor\\ContentWidgetCache\\Models\\Entry', 'events' => ['saved']],
                    ],
                    'queueInvalidation' => true,
                ],
            ],
        ],
        [
            'src/Widgets/HeroWidget.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\ContentWidgetCache\Widgets;

use Capell\Core\Contracts\Extensions\RegistersExtensionContentWidget;

final class HeroWidget implements RegistersExtensionContentWidget
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.1';
    }
}
PHP,
        ],
    );

    $messages = collect(AuditExtensionContractsAction::run($directory))->pluck('message')->all();

    expect($messages)->toContain(
        'Frontend package contribution is missing typed package capabilities.',
        'Frontend contribution declares unsafe public cache variance.',
    );
});

it('warns when frontend contributions are cacheable but lack delivery metadata', function (): void {
    $directory = makeExtensionAuditPackage(
        'vendor/frontend-cache-metadata',
        [
            'surfaces' => ['frontend'],
            'contributes' => [
                [
                    'type' => 'route',
                    'class' => 'Vendor\\FrontendCacheMetadata\\Routes\\PublicRoutes',
                    'surface' => 'frontend',
                    'namePrefix' => 'vendor.frontend-cache-metadata',
                ],
            ],
            'performance' => [
                'cacheTags' => [],
                'cacheSafety' => [
                    'cacheable' => true,
                    'variesBy' => [],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [
                        ['model' => 'Vendor\\FrontendCacheMetadata\\Models\\Entry', 'events' => ['saved']],
                    ],
                    'queueInvalidation' => true,
                ],
            ],
        ],
        [
            'src/Routes/PublicRoutes.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\FrontendCacheMetadata\Routes;

use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;

final class PublicRoutes implements RegistersExtensionRoute
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP,
        ],
    );

    $messages = collect(AuditExtensionContractsAction::run($directory))->pluck('message')->all();

    expect($messages)->toContain(
        'Frontend package contribution is missing typed package capabilities.',
        'Cacheable frontend contribution is missing cache tags.',
    );
});

it('warns when manifests declare unknown typed capabilities', function (): void {
    $directory = makeExtensionAuditPackage('vendor/future-capability', [
        'capabilities' => ['future-capability'],
    ]);

    $result = collect(AuditExtensionContractsAction::run($directory))
        ->firstWhere('message', 'Manifest declares capability strings outside the typed package capability graph.');

    expect($result)->not->toBeNull()
        ->and($result['context']['capabilities'] ?? [])->toBe(['future-capability']);
});

it('warns when contribution declarations drift from manifest permissions settings and health checks', function (): void {
    $directory = makeExtensionAuditPackage(
        'vendor/declaration-drift',
        [
            'contributes' => [
                [
                    'type' => 'setting',
                    'class' => 'Vendor\\DeclarationDrift\\Settings\\BillingSettings',
                    'permission' => 'manage-billing-settings',
                ],
                [
                    'type' => 'health-check',
                    'class' => 'Vendor\\DeclarationDrift\\Health\\BillingHealthCheck',
                ],
            ],
            'settings' => [],
            'permissions' => [],
            'healthChecks' => [],
        ],
        [
            'src/Settings/BillingSettings.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\DeclarationDrift\Settings;

use Capell\Core\Contracts\Extensions\RegistersExtensionSetting;

final class BillingSettings implements RegistersExtensionSetting
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP,
            'src/Health/BillingHealthCheck.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\DeclarationDrift\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;

final class BillingHealthCheck implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP,
        ],
    );

    $messages = collect(AuditExtensionContractsAction::run($directory))->pluck('message')->all();

    expect($messages)->toContain(
        'Contribution permission is missing from manifest permissions.',
        'Settings contribution is missing from manifest settings.',
        'Health-check contribution is missing from manifest healthChecks.',
    );
});

it('accepts wrapper contributions that declare their settings and health-check targets via metadata', function (): void {
    $directory = makeExtensionAuditPackage(
        'vendor/wrapper-pattern',
        [
            'contributes' => [
                [
                    'type' => 'setting',
                    'class' => 'Vendor\\WrapperPattern\\SettingsManifest\\BillingSettingsContribution',
                    'settingsClass' => 'Vendor\\WrapperPattern\\Settings\\BillingSettings',
                    'settingsGroup' => 'billing',
                ],
                [
                    'type' => 'health-check',
                    'class' => 'Vendor\\WrapperPattern\\HealthManifest\\BillingHealthContribution',
                    'checkClass' => 'Vendor\\WrapperPattern\\Health\\BillingHealthCheck',
                ],
            ],
            'settings' => ['Vendor\\WrapperPattern\\Settings\\BillingSettings'],
            'permissions' => [],
            'healthChecks' => [
                [
                    'key' => 'wrapper-pattern.package-health',
                    'label' => 'Wrapper pattern package health.',
                    'class' => 'Vendor\\WrapperPattern\\Health\\BillingHealthCheck',
                    'severity' => 'critical',
                    'surface' => 'admin',
                ],
            ],
        ],
        [
            'src/SettingsManifest/BillingSettingsContribution.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\WrapperPattern\SettingsManifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionSetting;

final class BillingSettingsContribution implements ExtensionContribution, RegistersExtensionSetting
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP,
            'src/HealthManifest/BillingHealthContribution.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\WrapperPattern\HealthManifest;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;

final class BillingHealthContribution implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP,
            'src/Settings/BillingSettings.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\WrapperPattern\Settings;

use Capell\Core\Contracts\Extensions\RegistersExtensionSetting;

final class BillingSettings implements RegistersExtensionSetting
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP,
            'src/Health/BillingHealthCheck.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\WrapperPattern\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;

final class BillingHealthCheck implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP,
        ],
    );

    $messages = collect(AuditExtensionContractsAction::run($directory))->pluck('message')->all();

    expect($messages)->not->toContain('Settings contribution is missing from manifest settings.')
        ->and($messages)->not->toContain('Health-check contribution is missing from manifest healthChecks.');
});

it('fails manifests and contribution classes that target an incompatible Capell API', function (): void {
    $directory = makeExtensionAuditPackage(
        'vendor/incompatible-api',
        [
            'capellApiVersion' => '^3.0',
            'contributes' => [
                [
                    'type' => 'route',
                    'class' => 'Vendor\\IncompatibleApi\\Routes\\LegacyRoutes',
                ],
            ],
        ],
        [
            'src/Routes/LegacyRoutes.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\IncompatibleApi\Routes;

use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;

final class LegacyRoutes implements RegistersExtensionRoute
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^3.0';
    }
}
PHP,
        ],
    );

    artisanCommand('capell:extension-audit', ['path' => $directory])
        ->expectsOutputToContain('Manifest capellApiVersion does not allow the current Capell API.')
        ->expectsOutputToContain('Contribution compatibleCapellApiVersion does not allow the current Capell API.')
        ->assertExitCode(Command::FAILURE);
});

it('reports invalid explicit manifest paths with an unknown package fallback', function (): void {
    $manifestPath = sys_get_temp_dir() . '/capell-extension-audit-invalid-' . bin2hex(random_bytes(6)) . '/capell.json';

    mkdir(dirname($manifestPath), 0755, true);
    file_put_contents($manifestPath, '{not-json');

    artisanCommand('capell:extension-audit', ['path' => $manifestPath])
        ->expectsOutputToContain('unknown')
        ->expectsOutputToContain('Manifest file is missing or invalid JSON.')
        ->assertExitCode(Command::FAILURE);
});

it('reports missing explicit manifest paths using the unknown package fallback', function (): void {
    $missingPath = sys_get_temp_dir() . '/capell-extension-audit-missing-' . bin2hex(random_bytes(6)) . '/capell.json';

    $results = AuditExtensionContractsAction::run($missingPath);

    expect($results)->toHaveCount(1)
        ->and($results[0]['package'])->toBe('unknown')
        ->and($results[0]['manifest_path'])->toBe($missingPath)
        ->and($results[0]['message'])->toBe('Manifest file is missing or invalid JSON.');
});
