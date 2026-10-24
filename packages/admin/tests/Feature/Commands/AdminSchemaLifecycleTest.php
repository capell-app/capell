<?php

declare(strict_types=1);

use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Actions\SyncDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Tests\Support\HostMigrationIsolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    // Trigger the suite schema before redirecting the host publication paths.
    expect(Schema::hasTable('admin_upload_grants'))->toBeTrue();
    $this->beforeApplicationDestroyed(HostMigrationIsolation::setUp(app()));
    $provider = expectPresent(app()->getProvider(AdminServiceProvider::class));
    expect($provider)->toBeInstanceOf(AdminServiceProvider::class);
    new ReflectionMethod($provider, 'bootPackageMigrations')->invoke($provider);
    Schema::drop('admin_upload_grants');
    DB::table('migrations')->where('migration', 'like', '%create_admin_upload_grants_table')->delete();
    SyncCapellPermissionsAction::shouldRun()->andReturnNull();
    bindFakeAction(SyncDashboardFilamentWidgetSettingsAction::class);
});

it('creates the Admin schema through the advertised lifecycle command', function (string $command): void {
    expect(Schema::hasTable('admin_upload_grants'))->toBeFalse();
    artisanCommand($command)->assertSuccessful();
    expect(Schema::hasTable('admin_upload_grants'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', 'like', '%create_admin_upload_grants_table')->pluck('migration')->all())
        ->toBe(['2026_10_24_000001_create_admin_upload_grants_table']);
})->with(['capell:admin-install', 'capell:admin-upgrade']);

it('keeps one migration identity from vendor publication through a normal upgrade', function (): void {
    CapellCore::markPackageInstalled(AdminServiceProvider::$packageName);
    artisanCommand('vendor:publish', ['--tag' => 'capell-admin-migrations'])->assertSuccessful();
    $published = glob(database_path('migrations/*create_admin_upload_grants_table.php'));
    throw_if($published === false, RuntimeException::class, 'Could not scan published Admin migrations.');

    expect($published)->toHaveCount(1);
    artisanCommand('migrate', ['--path' => database_path('migrations'), '--realpath' => true, '--force' => true])->assertSuccessful();
    expect(Schema::hasTable('admin_upload_grants'))->toBeTrue();
    DB::table('admin_upload_grants')->insert([
        'scope_hash' => str_repeat('a', 64), 'path_hash' => str_repeat('b', 64),
        'path' => 'preserved.txt', 'expires_at' => now()->addHour(),
    ]);

    artisanCommand('capell:upgrade', ['--force' => true])->assertSuccessful();
    expect(glob(database_path('migrations/*create_admin_upload_grants_table.php')))->toBe($published)
        ->and(basename($published[0]))->toBe('2026_10_24_000001_create_admin_upload_grants_table.php')
        ->and(DB::table('migrations')->where('migration', 'like', '%create_admin_upload_grants_table')->pluck('migration')->all())
        ->toBe(['2026_10_24_000001_create_admin_upload_grants_table'])
        ->and(DB::table('admin_upload_grants')->pluck('path')->all())->toBe(['preserved.txt']);
});
