<?php

declare(strict_types=1);

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

// Auto-discovered service providers (e.g. TagsServiceProvider) register packages into CapellCore.
// Clear that state so each test starts with only the packages it explicitly registers.
beforeEach(function (): void {
    CapellCore::clearPackages();
});

it('returns plugin list with none installed', function (): void {
    Http::fake([
        'https://plugin.capell.app/packages.json' => Http::response(['packages' => []], 200),
    ]);

    $plugins = GetPluginsAction::run();

    $nonTrustedPlugins = $plugins
        ->pluck('name')
        ->reject(fn (string $packageName): bool => TrustedCorePackages::contains($packageName))
        ->values();

    expect($plugins)
        ->toBeInstanceOf(Collection::class)
        ->and($nonTrustedPlugins->all())->toBe(['capell-app/welcome-tour']);
});
