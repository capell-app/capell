<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunSettingsMigrationsAction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('persists a settings migration and leaves settings untouched during a dry run', function (bool $dryRun): void {
    $directory = sys_get_temp_dir() . '/capell-settings-upgrade-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory);
    $migration = '2099_01_01_000001_settings_fixture';
    File::put($directory . '/' . $migration . '.php', <<<'PHP'
        <?php
        return new class extends Spatie\LaravelSettings\Migrations\SettingsMigration {
            #[Override]
            public function up(): void {
                $this->migrator->add('upgrade_fixture.enabled', true);
            }
        };
        PHP);
    config(['settings.migrations_paths' => [$directory]]);
    // Consumers may expose this optional command; the supported settings version
    // registers its migrations with Laravel's migrator instead.
    Artisan::command('settings:migrate {--force}', fn (): int => $this->call('migrate', ['--force' => (bool) $this->option('force'), '--path' => [$directory], '--realpath' => true]));
    if (! Schema::hasTable('settings')) {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->json('payload');
            $table->timestamps();
        });
    }

    try {
        $result = RunSettingsMigrationsAction::run(dryRun: $dryRun);
        $setting = DB::table('settings')->where('group', 'upgrade_fixture')->where('name', 'enabled')->first();
        expect($result->exitCode)->toBe(0)
            ->and($setting !== null)->toBe(! $dryRun)
            ->and(DB::table('migrations')->where('migration', $migration)->exists())->toBe(! $dryRun);
        if ($dryRun) {
            expect($result->output)->toContain('[dry-run]');
        } else {
            expect(json_decode((string) $setting?->payload, true, flags: JSON_THROW_ON_ERROR))->toBeTrue();
        }
    } finally {
        File::deleteDirectory($directory);
    }
})->with(['apply' => false, 'dry run' => true]);

it('reports an unavailable optional settings command without changing stored settings', function (): void {
    $before = DB::table('migrations')->pluck('migration')->all();
    $result = RunSettingsMigrationsAction::run();
    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain('not available')
        ->and(DB::table('migrations')->pluck('migration')->all())->toEqualCanonicalizing($before);
});
