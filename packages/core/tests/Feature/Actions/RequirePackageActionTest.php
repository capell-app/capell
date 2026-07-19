<?php

declare(strict_types=1);

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Actions\RequirePackageAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

function requirePackageActionDownloadPackageNames(): array
{
    return GetPluginsAction::run('download')
        ->pluck('name')
        ->reject(fn (string $packageName): bool => TrustedCorePackages::contains($packageName))
        ->values()
        ->all();
}

it('clears plugin packages cache and removes installed package from install filter', function (): void {
    config()->set('capell.plugins_source_url', 'https://example.test/plugins.json');
    config()->set('capell.plugins_cache_ttl', 60);

    $remotePayload = [
        'packages' => [
            ['name' => 'alpha-remote', 'version' => '1.0.0'],
            ['name' => 'beta-remote', 'version' => '1.2.3'],
        ],
    ];

    Http::fake([
        'https://example.test/plugins.json' => Http::response($remotePayload, 200),
    ]);

    // Use real fetcher; initial cache should be empty then populated by fetch.
    /** @var PluginPackagesFetcher $fetcher */
    $fetcher = resolve(PluginPackagesFetcher::class);
    $cachedBefore = $fetcher->getCached();
    expect($cachedBefore)->toHaveCount(0);

    $fetched = $fetcher->fetch();
    expect($fetched->pluck('name')->values()->all())->toEqual(['alpha-remote', 'beta-remote']);
    expect(CapellCore::getFromCache(CacheEnum::ExtensionPackages->value))->toBeInstanceOf(Collection::class);

    // Download filter should show both remote packages (none installed yet).
    $initialInstallNames = requirePackageActionDownloadPackageNames();
    expect($initialInstallNames)->toEqual(['alpha-remote', 'beta-remote', 'capell-app/welcome-tour']);

    $packagePath = sys_get_temp_dir() . '/capell-require-action-alpha-' . bin2hex(random_bytes(8));
    mkdir($packagePath, 0777, true);
    file_put_contents(
        $packagePath . '/composer.json',
        json_encode(['name' => 'alpha-remote'], JSON_THROW_ON_ERROR),
    );

    try {
        // Simulate package installation with Composer/path-backed package state.
        CapellCore::registerPackage('alpha-remote', path: $packagePath);

        // Stub require action to avoid real composer invocation; ensure cache cleared.
        $result = (new class extends RequirePackageAction
        {
            public function handle(string $name, ?string $token = null, ?string $provider = null, ?string $domain = null): array
            {
                CapellCore::clearExtensionCache();

                return ['package' => $name, 'status' => 'installed', 'message' => 'ok', 'output' => '', 'auth_used' => false, 'cache_cleared' => true];
            }
        })->handle('alpha-remote');

        expect($result['cache_cleared'])->toBeTrue();
        expect(CapellCore::getFromCache(CacheEnum::ExtensionPackages->value))->toBeNull();

        // After installation, download filter should exclude installed package.
        $postInstallNames = requirePackageActionDownloadPackageNames();
        expect($postInstallNames)->toEqual(['beta-remote', 'capell-app/welcome-tour'])->not()->toContain('alpha-remote');
    } finally {
        if (is_file($packagePath . '/composer.json')) {
            unlink($packagePath . '/composer.json');
        }

        if (is_dir($packagePath)) {
            rmdir($packagePath);
        }
    }
});
