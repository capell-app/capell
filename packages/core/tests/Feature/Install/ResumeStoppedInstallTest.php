<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Diagnostics\Checks\InstallLifecycleCheck;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the real install command and the real InstallStepExecutor for the two steps this scenario
 * needs (the failing clear-caches step and the closing lifecycle step); every other step is a
 * no-op so no package, migration or process side effects happen.
 */
function bindResumeScenarioExecutor(ArrayObject $inputs): void
{
    $real = new InstallStepExecutor;

    app()->instance(InstallStepExecutor::class, new readonly class($real, $inputs)
    {
        public function __construct(private InstallStepExecutor $real, private ArrayObject $inputs) {}

        public function execute(string $stepKey, InstallRunState $state): InstallRunState
        {
            $this->inputs->append($state->inputData->toArray());

            if (in_array($stepKey, [InstallPlan::STEP_CLEAR_CACHES, InstallPlan::STEP_MARK_CORE_INSTALLED], true)) {
                return $this->real->execute($stepKey, $state);
            }

            return $state;
        }
    });
}

/** @return array<string, string|true> */
function artisanOptionsFromCommand(string $command): array
{
    preg_match_all("/'([^']*)'|(\\S+)/", $command, $matches);
    $tokens = array_map(static fn (string $quoted, string $bare): string => $quoted !== '' ? $quoted : $bare, $matches[1], $matches[2]);
    $options = [];

    foreach (array_slice($tokens, 3) as $token) {
        $parts = explode('=', $token, 2);
        $options[$parts[0]] = $parts[1] ?? true;
    }

    return $options;
}

beforeEach(function (): void {
    InstallRunState::completeInitialInstallation();
});

afterEach(function (): void {
    InstallRunState::completeInitialInstallation();
    InstallRunState::clearStoppedStep();
    ClearCachesAction::clearFake();
});

it('lets the doctor resume a stopped install with the options the first run used', function (): void {
    CapellExtension::query()->where('composer_name', 'capell-app/core')->delete();
    CapellCore::clearExtensionCache();
    InstallRunState::clearStoppedStep();
    $author = User::factory()->createOne();
    $inputs = new ArrayObject;
    bindResumeScenarioExecutor($inputs);
    $firstRun = [
        '--no-interaction' => true,
        '--url' => 'https://resume.example.test',
        '--site-name' => 'Resume Site',
        '--no-seed-default-data' => true,
        '--no-frontend-build' => true,
        '--package-mode' => 'custom',
        '--packages' => 'capell-app/core',
        '--user' => $author->email,
    ];
    request()->server->set('argv', ['artisan', 'capell:install', ...array_map(
        static fn (string $name, mixed $value): string => $value === true ? $name : $name . '=' . $value,
        array_keys($firstRun),
        $firstRun,
    )]);

    // 1. The install stops for real at the cache-clearing step.
    ClearCachesAction::mock()->shouldReceive('handle')->andThrow(new RuntimeException('Cache store is unreachable.'));
    $firstExit = Artisan::call('capell:install', $firstRun);

    // 2. The doctor names that step and prints the resume command.
    $check = resolve(InstallLifecycleCheck::class)->check();
    $command = str_replace('Resume the installation with: ', '', (string) $check->remediation);

    expect($firstExit)->not->toBe(0)
        ->and(InstallRunState::stoppedStep()?->key)->toBe(InstallPlan::STEP_CLEAR_CACHES)
        ->and($check->passed)->toBeFalse()
        ->and($check->message)->toContain(InstallPlan::STEP_CLEAR_CACHES)
        ->and($command)->toStartWith('php artisan capell:install')
        ->and($command)->toContain('--site-name=Resume Site');

    // 3. The printed command runs through Artisan once the fault is gone.
    ClearCachesAction::clearFake();
    ClearCachesAction::mock()->shouldReceive('handle')->andReturnNull();
    $inputs->exchangeArray([]);
    $resumeExit = Artisan::call('capell:install', artisanOptionsFromCommand($command));

    // 4. It completes, forgets the stopped step, and ran with the first run's choices.
    expect($resumeExit)->toBe(0)
        ->and(InstallRunState::stoppedStep())->toBeNull()
        ->and(ResolveCapellInstallationStateAction::run())->toBe(CapellInstallationState::Installed)
        ->and(CapellExtension::query()->where('composer_name', 'capell-app/core')->value('status'))->toBe(ExtensionStatusEnum::Enabled)
        ->and($inputs->count())->toBeGreaterThan(0)
        ->and($inputs[0]['siteUrl'])->toBe('https://resume.example.test')
        ->and($inputs[0]['siteName'])->toBe('Resume Site')
        ->and($inputs[0]['seedDefaultData'])->toBeFalse();
});
