<?php

declare(strict_types=1);

use Capell\Installer\Providers\InstallerServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

it('keeps database-backed cache and session drivers when their tables already exist', function (): void {
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
        new InstallerServiceProvider($this->app)->bootingPackage();

        expect(config('session.driver'))->toBe('database')
            ->and(config('cache.default'))->toBe('database');
    } finally {
        $runningInConsole->setValue($this->app, $originalRunningInConsole);
    }
});
