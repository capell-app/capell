<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;

/**
 * Registers an installed theme manifest so the screenshot workbench can render
 * the theme extension page at /admin/extensions/themes/harbour.
 */
final class ThemeExtensionScreenshotFixture
{
    public const string ThemeKey = 'harbour';

    public static function register(): void
    {
        $manifest = CapellManifestData::fromArray(self::manifest());

        CapellCore::registerManifestPackage($manifest);

        $registry = resolve(CapellPackageRegistry::class);
        $registry->fill([
            ...$registry->all(),
            $manifest->name => $manifest,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        return [
            'manifest-version' => 3,
            'name' => 'capell-theme/harbour-theme',
            'slug' => 'harbour-theme',
            'displayName' => 'Harbour',
            'kind' => 'theme',
            'themeKey' => self::ThemeKey,
            'capellApiVersion' => '^1.0',
            'version' => '2.3.0',
            'description' => 'A calm editorial theme with generous type and quiet colour.',
            'product' => ['group' => 'Themes', 'tier' => 'free', 'bundle' => null],
            'surfaces' => ['frontend'],
            'dependencies' => ['requires' => [], 'supports' => [], 'conflicts' => []],
            'providers' => [
                'metadata' => [],
                'install' => [],
                'runtime' => [],
                'auth' => [],
                'admin' => [],
                'frontend' => [],
            ],
            'contributes' => [],
            'database' => ['migrations' => false, 'settings' => false, 'requiredTables' => []],
            'commands' => ['install' => null, 'setup' => null, 'demo' => null, 'doctor' => null],
            'settings' => [],
            'permissions' => [],
            'capabilities' => [],
            'performance' => [
                'frontendRenderBudgetMs' => 0,
                'adminQueryBudget' => 0,
                'cacheTags' => [],
                'cacheSafety' => [
                    'cacheable' => false,
                    'variesBy' => [],
                    'sensitiveOutput' => false,
                    'invalidationSources' => [],
                    'queueInvalidation' => false,
                ],
            ],
            'healthChecks' => [],
            'commercial' => [
                'proposedLicense' => 'free',
                'requestedCertification' => 'community',
                'supportPolicy' => 'community',
                'privateDocsRequested' => false,
            ],
            'marketplace' => [
                'summary' => 'A calm editorial theme with generous type and quiet colour, built for long reads and simple navigation.',
                'screenshots' => [],
                'categories' => ['editorial'],
            ],
        ];
    }
}
