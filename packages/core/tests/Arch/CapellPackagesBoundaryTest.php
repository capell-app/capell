<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function capellPackagesNamespaces(): array
{
    return [
        'Capell\AccessGate',
        'Capell\Address',
        'Capell\AgentBridge',
        'Capell\AIOrchestrator',
        'Capell\Blog',
        'Capell\CampaignStudio',
        'Capell\ContentSections',
        'Capell\DashboardReports',
        'Capell\DemoKit',
        'Capell\Deployments',
        'Capell\Diagnostics',
        'Capell\EmailStudio',
        'Capell\Events',
        'Capell\FormBuilder',
        'Capell\FoundationTheme',
        'Capell\FrontendAuthoring',
        'Capell\FrontendOptimizer',
        'Capell\GA4Reports',
        'Capell\HtmlCache',
        'Capell\Insights',
        'Capell\LoginAudit',
        'Capell\MediaAI',
        'Capell\MediaLibrary',
        'Capell\MigrationAssistant',
        'Capell\Navigation',
        'Capell\Newsletter',
        'Capell\Notes',
        'Capell\PasswordPolicy',
        'Capell\PublicActions',
        'Capell\PublishingStudio',
        'Capell\Search',
        'Capell\SeoSuite',
        'Capell\SiteDiscovery',
        'Capell\Tags',
        'Capell\ThemeStudio\Agency',
        'Capell\ThemeStudio\Corporate',
        'Capell\ThemeStudio\Saas',
        'Capell\TranslationManager',
        'Capell\WelcomeTour',
        'Capell\WordPressImporter',
    ];
}

/**
 * @return list<string>
 */
function composerManifestStrings(array|string|int|float|bool|null $value): array
{
    if (is_string($value)) {
        return [$value];
    }

    if (! is_array($value)) {
        return [];
    }

    $strings = collect($value)
        ->flatMap(fn (mixed $item): array => composerManifestStrings($item))
        ->values()
        ->all();

    return array_values($strings);
}

arch('Capell 4 packages do not reference packages monorepo namespaces')
    ->expect([
        'Capell\Admin',
        'Capell\Core',
        'Capell\Frontend',
        'Capell\Installer',
        'Capell\Marketplace',
    ])
    ->not()->toUse(capellPackagesNamespaces());

arch('Capell 4 packages do not reference the extracted block library package')
    ->expect([
        'Capell\Admin',
        'Capell\Core',
        'Capell\Frontend',
    ])
    ->not()->toUse([
        'Capell\ContentFilamentWidgets',
    ]);

it('does not autoload sibling package monorepos from composer manifests', function (string $manifest): void {
    $manifestPath = dirname(__DIR__, 4) . '/' . $manifest;

    if (! file_exists($manifestPath)) {
        expect($manifestPath)->not->toBeFile();

        return;
    }

    /** @var array<string, mixed> $composer */
    $composer = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

    $forbiddenFragments = [
        '../capell-packages-4',
        '../capell-packages-4-cms-editable-public-content',
        'capell-packages-4/',
        'capell-packages-4-cms-editable-public-content/',
    ];

    foreach ($forbiddenFragments as $forbiddenFragment) {
        foreach (composerManifestStrings($composer) as $manifestString) {
            expect($manifestString)->not->toContain($forbiddenFragment);
        }
    }
})->with([
    'composer.json',
    'composer.local.json',
]);

it('does not reference the sibling package monorepo outside documentation', function (): void {
    $repositoryRoot = dirname(__DIR__, 4);
    $pathsToInspect = [
        $repositoryRoot . '/.github',
        $repositoryRoot . '/packages/admin/config',
        $repositoryRoot . '/packages/admin/database',
        $repositoryRoot . '/packages/admin/resources',
        $repositoryRoot . '/packages/admin/src',
        $repositoryRoot . '/packages/core/config',
        $repositoryRoot . '/packages/core/database',
        $repositoryRoot . '/packages/core/resources',
        $repositoryRoot . '/packages/core/src',
        $repositoryRoot . '/packages/frontend/config',
        $repositoryRoot . '/packages/frontend/database',
        $repositoryRoot . '/packages/frontend/resources',
        $repositoryRoot . '/packages/frontend/src',
        $repositoryRoot . '/packages/installer/config',
        $repositoryRoot . '/packages/installer/database',
        $repositoryRoot . '/packages/installer/resources',
        $repositoryRoot . '/packages/installer/src',
        $repositoryRoot . '/packages/marketplace/config',
        $repositoryRoot . '/packages/marketplace/database',
        $repositoryRoot . '/packages/marketplace/resources',
        $repositoryRoot . '/packages/marketplace/src',
        $repositoryRoot . '/scripts',
        $repositoryRoot . '/composer.json',
        $repositoryRoot . '/docker-compose.yml',
    ];
    $forbiddenFragments = [
        'capell-packages-4',
        'capell-packages',
        'capell-app/capell-packages',
        'repositories/capell-packages',
        'CAPELL_PACKAGES_REPO',
    ];

    foreach (nonDocumentationTextFiles($pathsToInspect) as $filePath) {
        $contents = (string) file_get_contents($filePath);

        foreach ($forbiddenFragments as $forbiddenFragment) {
            expect($contents)
                ->not->toContain($forbiddenFragment, sprintf('%s references %s', $filePath, $forbiddenFragment));
        }
    }
});

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function nonDocumentationTextFiles(array $paths): array
{
    $files = [];

    foreach ($paths as $path) {
        if (is_file($path)) {
            $files[] = $path;

            continue;
        }

        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $filePath = $file->getPathname();
            if (str_ends_with((string) $filePath, '.md')) {
                continue;
            }

            if (str_contains((string) $filePath, '/docs/')) {
                continue;
            }

            $files[] = $filePath;
        }
    }

    sort($files);

    return $files;
}
