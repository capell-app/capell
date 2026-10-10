<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunPublishedDatabaseMigrationsAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('applies only pending published migrations and preserves schema on a dry run', function (bool $dryRun): void {
    $original = database_path();
    $directory = sys_get_temp_dir() . '/capell-published-upgrade-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory . '/migrations');
    $pending = '2099_01_01_000001_pending';
    $ran = '2099_01_01_000002_ran';
    File::put($directory . '/migrations/' . $pending . '.php', <<<'PHP'
        <?php
        return new class extends Illuminate\Database\Migrations\Migration {
            public function up(): void {
                Illuminate\Support\Facades\Schema::create('published_upgrade_fixture', function (Illuminate\Database\Schema\Blueprint $table): void {
                    $table->id();
                });
            }
        };
        PHP);
    File::put($directory . '/migrations/' . $ran . '.php', <<<'PHP'
        <?php
        return new class extends Illuminate\Database\Migrations\Migration {
            public function up(): void {
                throw new RuntimeException('An applied migration must not run again.');
            }
        };
        PHP);
    DB::table('migrations')->insert(['migration' => $ran, 'batch' => 1]);
    app()->useDatabasePath($directory);
    try {
        $result = RunPublishedDatabaseMigrationsAction::run(dryRun: $dryRun);
        expect($result->exitCode)->toBe(0)
            ->and(Schema::hasTable('published_upgrade_fixture'))->toBe(! $dryRun)
            ->and(DB::table('migrations')->where('migration', $pending)->exists())->toBe(! $dryRun)
            ->and(DB::table('migrations')->where('migration', $ran)->count())->toBe(1);
        if ($dryRun) {
            expect($result->output)->toContain('[dry-run]');
        }
    } finally {
        app()->useDatabasePath($original);
        File::deleteDirectory($directory);
    }
})->with(['apply' => false, 'dry run' => true]);
