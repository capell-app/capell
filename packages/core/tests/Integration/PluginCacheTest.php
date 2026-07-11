<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('caches installed extension names and clears on demand', function (): void {
    config()->set('capell-core.disable_cache', false);

    CapellCore::registerPackage('alpha');
    CapellCore::registerPackage('beta');
    CapellCore::forcePackageInstalled('alpha');
    CapellCore::forcePackageInstalled('beta');

    $first = CapellCore::getInstalledExtensionNames();

    expect($first)->toContain('alpha', 'beta');

    $cached = CapellCore::getFromCache(CacheEnum::ExtensionInstalledNames->value);
    expect($cached)->toBeArray()->toContain('alpha', 'beta');

    CapellCore::clearExtensionCache();
    CapellCore::forcePackageInstalled('beta', false);

    $recomputed = CapellCore::isPackageInstalled('beta');
    expect($recomputed)->toBeFalse();
});

it('reads installed extension names without cache during early provider bootstrap', function (): void {
    CapellCore::registerPackage('early-package');
    CapellCore::forcePackageInstalled('early-package');

    $application = app();
    $cache = $application->make(Factory::class);

    $application->forgetInstance('cache');
    unset($application['cache']);

    try {
        expect($application->bound('cache'))->toBeFalse()
            ->and(CapellCore::isPackageInstalled('early-package'))->toBeTrue();
    } finally {
        $application->instance('cache', $cache);
        CapellCore::clearExtensionCache();
    }
});

it('does not throw when installed package state is checked before database has booted', function (): void {
    CapellCore::registerPackage('database-not-booted-package');

    $resolver = Model::getConnectionResolver();
    Model::unsetConnectionResolver();

    try {
        expect(CapellCore::isPackageInstalled('database-not-booted-package'))->toBeFalse();
    } finally {
        Model::setConnectionResolver($resolver);
        CapellCore::clearExtensionCache();
    }
});

it('does not throw when the database cache table has not been installed yet', function (): void {
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.driver', 'database');
    config()->set('cache.stores.database.table', 'cache');

    app()->forgetInstance('cache');
    Cache::clearResolvedInstance('cache');
    Schema::dropIfExists('cache');

    CapellCore::registerPackage('missing-cache-table-package');

    try {
        expect(CapellCore::isPackageInstalled('missing-cache-table-package'))->toBeFalse();
    } finally {
        CapellCore::clearExtensionCache();
    }
});

it('keeps optional composer packages inactive when the extension ledger is missing', function (): void {
    $composerName = 'capell-app/no-ledger-package-' . bin2hex(random_bytes(4));
    $packagePath = sys_get_temp_dir() . '/capell-no-ledger-package-' . bin2hex(random_bytes(8));
    mkdir($packagePath, 0777, true);

    file_put_contents(
        $packagePath . '/composer.json',
        json_encode(['name' => $composerName], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    try {
        CapellCore::registerPackage($composerName, path: $packagePath);

        Schema::dropIfExists('capell_extensions');

        expect(CapellCore::isPackageAvailable($composerName))->toBeTrue()
            ->and(CapellCore::isPackageEnabled($composerName))->toBeFalse();
    } finally {
        if (is_file($packagePath . '/composer.json')) {
            unlink($packagePath . '/composer.json');
        }

        if (is_dir($packagePath)) {
            rmdir($packagePath);
        }
    }
});

it('memoizes extension ledger misses for repeated uninstalled package checks', function (): void {
    CapellCore::clearPackages();

    $packageNames = [
        'capell-app/uninstalled-one-' . bin2hex(random_bytes(4)),
        'capell-app/uninstalled-two-' . bin2hex(random_bytes(4)),
        'capell-app/uninstalled-three-' . bin2hex(random_bytes(4)),
    ];

    foreach ($packageNames as $packageName) {
        CapellCore::registerPackage($packageName);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach ([1, 2] as $_) {
        foreach ($packageNames as $packageName) {
            expect(CapellCore::isPackageInstalled($packageName))->toBeFalse();
        }
    }

    $perPackageLedgerQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
        $querySql = $query['query'];

        return str_contains($querySql, 'from "capell_extensions"')
            && str_contains($querySql, 'where "composer_name" =');
    });

    $batchedLedgerQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
        $querySql = $query['query'];

        return str_contains($querySql, 'from "capell_extensions"')
            && str_contains($querySql, 'where "composer_name" in');
    });

    DB::disableQueryLog();

    expect($batchedLedgerQueries)->toHaveCount(1)
        ->and($perPackageLedgerQueries)->toHaveCount(0);
});

it('memoizes a missing extension ledger table during repeated package checks', function (): void {
    CapellCore::clearPackages();
    Schema::dropIfExists('capell_extensions');

    $packageNames = [
        'capell-app/missing-ledger-one-' . bin2hex(random_bytes(4)),
        'capell-app/missing-ledger-two-' . bin2hex(random_bytes(4)),
        'capell-app/missing-ledger-three-' . bin2hex(random_bytes(4)),
    ];

    foreach ($packageNames as $packageName) {
        CapellCore::registerPackage($packageName);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach ([1, 2] as $_) {
        foreach ($packageNames as $packageName) {
            expect(CapellCore::isPackageInstalled($packageName))->toBeFalse();
        }
    }

    $extensionLedgerSchemaQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
        $querySql = $query['query'];

        return str_contains($querySql, 'capell_extensions');
    });

    DB::disableQueryLog();

    expect($extensionLedgerSchemaQueries)->toHaveCount(1);
});

it('refreshes extension ledger table availability for lifecycle writes after an early missing table check', function (): void {
    $packageName = 'capell-app/late-ledger-package-' . bin2hex(random_bytes(4));

    CapellCore::clearPackages();
    CapellCore::registerPackage($packageName);
    Schema::dropIfExists('capell_extensions');

    expect(CapellCore::isPackageInstalled($packageName))->toBeFalse();

    createCapellExtensionsTableForPluginCacheTest();

    CapellCore::markPackageInstalled($packageName);

    expect(CapellExtension::query()->where('composer_name', $packageName)->exists())->toBeTrue()
        ->and(CapellCore::isPackageInstalled($packageName))->toBeTrue();
});

it('does not keep stale extension misses after an early package check', function (): void {
    $packageName = 'capell-app/stale-extension-miss-' . bin2hex(random_bytes(4));

    CapellCore::clearPackages();
    CapellCore::registerPackage($packageName);
    Schema::dropIfExists('capell_extensions');

    expect(CapellCore::isPackageInstalled($packageName))->toBeFalse();

    createCapellExtensionsTableForPluginCacheTest();
    resolve(RuntimeSchemaState::class)->forgetTable('capell_extensions');

    CapellExtension::query()->create([
        'composer_name' => $packageName,
        'status' => ExtensionStatusEnum::Enabled,
        'marketplace_runtime_allowed' => true,
    ]);

    expect(CapellCore::isPackageInstalled($packageName))->toBeTrue();
});

it('does not enable publishing studio runtime from composer availability alone', function (): void {
    $packagePath = storage_path('framework/testing/publishing-studio');

    File::ensureDirectoryExists($packagePath);
    File::put($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/publishing-studio',
        'type' => 'library',
    ], JSON_THROW_ON_ERROR));

    try {
        CapellCore::registerPackage('capell-app/publishing-studio', path: $packagePath);
        CapellCore::forcePackageInstalled('capell-app/publishing-studio', false);

        expect(CapellCore::isPackageAvailable('capell-app/publishing-studio'))->toBeTrue()
            ->and(CapellCore::isPackageEnabled('capell-app/publishing-studio'))->toBeFalse();
    } finally {
        File::deleteDirectory($packagePath);
    }
});

function createCapellExtensionsTableForPluginCacheTest(): void
{
    Schema::create('capell_extensions', function (Blueprint $table): void {
        $table->id();
        $table->string('composer_name')->unique();
        $table->string('name')->nullable();
        $table->text('description')->nullable();
        $table->string('version')->nullable();
        $table->string('source')->nullable();
        $table->string('status')->default('enabled')->index();
        $table->timestamp('enabled_at')->nullable();
        $table->timestamp('disabled_at')->nullable();
        $table->timestamp('failed_at')->nullable();
        $table->timestamp('installed_at')->nullable();
        $table->json('metadata')->nullable();
        $table->boolean('is_paid_marketplace_extension')->default(false);
        $table->string('marketplace_runtime_status')->nullable()->index();
        $table->boolean('marketplace_runtime_allowed')->default(true);
        $table->json('marketplace_signed_activation')->nullable();
        $table->timestamp('marketplace_activation_checked_at')->nullable();
        $table->text('marketplace_runtime_reason')->nullable();
        $table->timestamps();
    });
}
