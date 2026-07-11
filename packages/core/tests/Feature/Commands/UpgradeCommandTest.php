<?php

declare(strict_types=1);

use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Models\UpgradeRunEvent;
use Capell\Core\Tests\Feature\Commands\Fixtures\TestUpgradeCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Artisan::registerCommand(new TestUpgradeCommand);

    $fakeSettingsMigrate = new class extends Command
    {
        protected $signature = 'settings:migrate {--force}';

        public function handle(): int
        {
            return 0;
        }
    };

    Artisan::registerCommand($fakeSettingsMigrate);
});

it('runs upgrade command successfully', function (): void {
    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/upgrade-package'),
    );

    artisanCommand('capell:upgrade', [
        '--no-clear-cache' => true,
    ])->assertExitCode(0);

    $run = expectPresent(UpgradeRun::query()->latest('id')->first());

    expect($run)->toBeInstanceOf(UpgradeRun::class)
        ->and($run->status)->toBe(UpgradeRunStatus::Succeeded)
        ->and($run->options['no_clear_cache'] ?? null)->toBeTrue()
        ->and($run->events()->where('message', 'Capell upgrade completed successfully.')->exists())->toBeTrue();
});

it('keeps the cli command compatible before durable run tables exist', function (): void {
    Schema::dropIfExists('capell_upgrade_run_events');
    Schema::dropIfExists('capell_upgrade_runs');

    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/upgrade-package'),
    );

    artisanCommand('capell:upgrade', [
        '--no-clear-cache' => true,
    ])->assertExitCode(0);
});

it('skips packages without upgrade command', function (): void {
    CapellCore::registerPackage(
        name: 'test-no-upgrade',
    );

    artisanCommand('capell:upgrade', [
        '--no-clear-cache' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutput('Upgrade complete!');
});

it('handles empty upgrade command strings', function (): void {
    CapellCore::registerPackage(
        name: 'test-empty',
    );

    artisanCommand('capell:upgrade', [
        '--no-clear-cache' => true,
    ])->assertExitCode(0);
});

it('processes multiple packages in sequence', function (): void {
    CapellCore::registerPackage(
        name: 'package-one',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/upgrade-package'),
    );

    CapellCore::registerPackage(
        name: 'package-two',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/upgrade-package'),
    );

    artisanCommand('capell:upgrade', [
        '--no-clear-cache' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutput('Upgrade complete!');
});

it('outputs package upgrade progress', function (): void {
    CapellCore::registerPackage(
        name: 'test-package',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/upgrade-package'),
    );

    artisanCommand('capell:upgrade', [
        '--no-clear-cache' => true,
    ])
        ->expectsOutput('  Running test-package (test:upgrade)')
        ->assertExitCode(0);

    expect(UpgradeRunEvent::query()
        ->where('level', UpgradeRunEventLevel::Warning)
        ->where('message', 'like', '%legacy manifest upgrade command%')
        ->exists())->toBeTrue();
});

it('bypasses cache clearing with no-clear-cache option', function (): void {
    CapellCore::registerPackage(
        name: 'test',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/upgrade-package'),
    );

    artisanCommand('capell:upgrade', [
        '--no-clear-cache' => true,
    ])
        ->doesntExpectOutput('Clearing caches...')
        ->assertExitCode(0);
});

it('fails the upgrade when a selected cache clear command fails', function (): void {
    Artisan::registerCommand(new class extends Command
    {
        protected $signature = 'config:clear';

        public function handle(): int
        {
            return 1;
        }
    });

    artisanCommand('capell:upgrade', [
        '--skip-migrations' => true,
        '--skip-steps' => true,
        '--force' => true,
        '--caches' => 'config',
    ])
        ->expectsOutputToContain('config:clear failed with exit code 1.')
        ->assertExitCode(1);
});
