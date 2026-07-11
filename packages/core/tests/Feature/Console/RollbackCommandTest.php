<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Console;

use Capell\Core\Actions\Upgrade\RunUpgradeStepAction;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Models\UpgradeLogEntry;
use Capell\Core\Tests\Feature\Console\Fixtures\RollbackableStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    RollbackableStep::$rollbacks = 0;
    app()->tag([RollbackableStep::class], 'capell.upgrade-steps');

    Artisan::command('settings:migrate {--force}', fn (): int => Command::SUCCESS)->describe('stub');
    Artisan::command('optimize:clear', fn (): int => Command::SUCCESS)->describe('stub');
    Artisan::command('capell:html-cache:clear', fn (): int => Command::SUCCESS)->describe('stub');
});

it('rolls back a specified step id', function (): void {
    RunUpgradeStepAction::run(new RollbackableStep, new UpgradeContext([], [], [], false));

    artisanCommand('capell:rollback', ['--step' => 'core.rollback-cli', '--force' => true])
        ->assertSuccessful();

    expect(RollbackableStep::$rollbacks)->toBe(1);
});

it('fails clearly when the step id is unknown', function (): void {
    artisanCommand('capell:rollback', ['--step' => 'does.not.exist', '--force' => true])
        ->expectsOutputToContain('Unknown step id')
        ->assertFailed();
});

it('dry-run does not invoke rollback', function (): void {
    RunUpgradeStepAction::run(new RollbackableStep, new UpgradeContext([], [], [], false));

    artisanCommand('capell:rollback', ['--step' => 'core.rollback-cli', '--dry-run' => true, '--force' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect(RollbackableStep::$rollbacks)->toBe(0)
        ->and(UpgradeLogEntry::query()->steps()->where('status', 'rolled_back')->count())->toBe(0);
});
