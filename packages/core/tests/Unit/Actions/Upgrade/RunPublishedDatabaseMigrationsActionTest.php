<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunPublishedDatabaseMigrationsAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('runs migrations published into the host application', function (): void {
    $originalDatabasePath = database_path();
    $databasePath = sys_get_temp_dir() . '/capell-published-upgrade-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($databasePath . '/migrations');
    File::put($databasePath . '/migrations/2099_01_01_000001_pending.php', '<?php');
    File::put($databasePath . '/migrations/2099_01_01_000002_ran.php', '<?php');
    DB::table('migrations')->insert(['migration' => '2099_01_01_000002_ran', 'batch' => 1]);
    app()->useDatabasePath($databasePath);
    $calls = [];
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->andReturnUsing(function (string $command, array $parameters = []) use (&$calls): int {
        $calls[] = [$command, $parameters];

        return 0;
    });
    $kernel->shouldReceive('output')->andReturn('Published migrations ran');
    $this->app->instance(Kernel::class, $kernel);

    try {
        $result = RunPublishedDatabaseMigrationsAction::run();

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain('Published migrations ran')
            ->and($calls)->toBe([['migrate', [
                '--force' => true,
                '--path' => [$databasePath . '/migrations/2099_01_01_000001_pending.php'],
                '--realpath' => true,
            ]]]);
    } finally {
        app()->useDatabasePath($originalDatabasePath);
        File::deleteDirectory($databasePath);
    }
});

it('does not invoke artisan during a dry run', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldNotReceive('call');

    $this->app->instance(Kernel::class, $kernel);

    $result = RunPublishedDatabaseMigrationsAction::run(dryRun: true);

    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain('[dry-run]');
});
