<?php

declare(strict_types=1);

use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Installer\Support\InstallerDatabaseTableState;
use Capell\Installer\Support\InstallerRuntimeMemo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config(['capell-installer.installation_state_cache.host' => 'installer-provider-test']);
    InstallerDatabaseTableState::forget();
});

it('resets installer runtime memoization at long-lived worker scope boundaries', function (): void {
    $memo = resolve(InstallerRuntimeMemo::class);
    $memo->put('installation-state', false);

    app()->forgetScopedInstances();

    $nextScopeMemo = resolve(InstallerRuntimeMemo::class);

    expect($nextScopeMemo)->not->toBe($memo)
        ->and($nextScopeMemo->has('installation-state'))->toBeFalse();
});

it('falls back database-backed cache and session drivers for first web installer requests', function (): void {
    $runningInConsole = new ReflectionProperty($this->app, 'isRunningInConsole');
    $originalRunningInConsole = $runningInConsole->getValue($this->app);

    $runningInConsole->setValue($this->app, false);

    config([
        'session.driver' => 'database',
        'session.table' => 'missing_installer_sessions',
        'cache.default' => 'database',
        'cache.stores.database.table' => 'missing_installer_cache',
    ]);

    try {
        new InstallerServiceProvider($this->app)->bootingPackage();

        expect(config('session.driver'))->toBe('file')
            ->and(config('cache.default'))->toBe('file');
    } finally {
        $runningInConsole->setValue($this->app, $originalRunningInConsole);
    }
});

it('keeps database-backed drivers with one cold query and none after the persistent cache is warm', function (): void {
    Schema::create('installer_provider_sessions', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable()->index();
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });

    Schema::create('installer_provider_cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    $runningInConsole = new ReflectionProperty($this->app, 'isRunningInConsole');
    $originalRunningInConsole = $runningInConsole->getValue($this->app);

    $runningInConsole->setValue($this->app, false);

    config([
        'session.driver' => 'database',
        'session.table' => 'installer_provider_sessions',
        'cache.default' => 'database',
        'cache.stores.database.table' => 'installer_provider_cache',
    ]);

    try {
        DB::flushQueryLog();
        DB::enableQueryLog();

        new InstallerServiceProvider($this->app)->bootingPackage();

        expect(config('session.driver'))->toBe('database')
            ->and(config('cache.default'))->toBe('database')
            ->and(DB::getQueryLog())->toHaveCount(1);

        InstallerDatabaseTableState::resetRuntimeMemo();
        DB::flushQueryLog();

        new InstallerServiceProvider($this->app)->bootingPackage();

        expect(DB::getQueryLog())->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
        $runningInConsole->setValue($this->app, $originalRunningInConsole);
    }
});
