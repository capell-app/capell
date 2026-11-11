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
use Capell\Tests\Support\OwnedApplicationPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once dirname(__DIR__, 5) . '/tests/Support/InstallFilesystemLock.php';

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
    // The install command inspects the application's own files (for example the Filament panel
    // provider), so run against a private tree: the shared Testbench skeleton is written to by
    // other install tests in parallel workers.
    // Serialise with the install command tests that still write into the shared skeleton.
    acquireCapellInstallFilesystemLock();
    $this->skeletonBasePath = base_path();
    $this->ownedPaths = new OwnedApplicationPaths(app());
    config([
        'capell.install.welcome_routes_web_path' => $this->ownedPaths->root . '/routes/web.php',
        'capell.install.welcome_env_path' => $this->ownedPaths->root . '/.env',
    ]);
    InstallRunState::completeInitialInstallation();
});

afterEach(function (): void {
    $this->ownedPaths->restore();
    releaseCapellInstallFilesystemLock();
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

it('records the parsed password omission for stopped installs', function (string $command, bool $hasPassword): void {
    CapellExtension::query()->where('composer_name', 'capell-app/core')->delete();
    CapellCore::clearExtensionCache();
    InstallRunState::clearStoppedStep();
    bindResumeScenarioExecutor(new ArrayObject);
    $firstRun = [
        '--no-interaction' => true,
        '--url' => 'https://resume.example.test',
        '--site-name' => 'Resume Site',
        '--no-seed-default-data' => true,
        '--no-frontend-build' => true,
        '--package-mode' => 'custom',
        '--packages' => 'capell-app/core',
        '--name' => 'Resume Admin',
        '--email' => 'resume-admin@example.test',
    ];
    if (! $hasPassword) {
        unset($firstRun['--name'], $firstRun['--email']);
        $firstRun['--user'] = User::factory()->createOne()->email;
    }

    ClearCachesAction::mock()->shouldReceive('handle')->andThrow(new RuntimeException('Cache store is unreachable.'));

    $options = [...$firstRun, ...($hasPassword ? ['--password' => 'Sup3r-secret-pw'] : [])];
    if ($command === 'capell:install') {
        $exit = Artisan::call($command, $options);
        $output = Artisan::output();
    } else {
        $buffer = new BufferedOutput;
        $input = new ArrayInput(['command' => $command, ...$options]);
        $input->setInteractive(false);
        $exit = Artisan::handle($input, $buffer);
        $output = $buffer->fetch();
    }

    $check = resolve(InstallLifecycleCheck::class)->check();

    expect($exit)->not->toBe(0)
        ->and(InstallRunState::stoppedStep()?->passwordOmitted)->toBe($hasPassword)
        ->and(str_contains((string) $check->remediation, '--password=<value>'))->toBe($hasPassword)
        ->and(str_contains($output, '--password=<value>'))->toBe($hasPassword)
        ->and(str_contains((string) $check->remediation, 'never saved'))->toBe($hasPassword)
        ->and($output)->not->toContain('Sup3r-secret-pw')
        ->and((string) $check->remediation)->not->toContain('Sup3r-secret-pw')
        ->and(json_encode($check->evidence))->not->toContain('Sup3r-secret-pw')
        ->and(json_encode(InstallRunState::stoppedStep()?->toArray()))->not->toContain('Sup3r-secret-pw');
})->with([
    'Artisan call with password' => ['capell:install', true],
    'Artisan call without password' => ['capell:install', false],
    'abbreviated command with password' => ['capell:inst', true],
    'abbreviated command without password' => ['capell:inst', false],
]);

it('is not affected by a panel provider another test left in the shared skeleton', function (): void {
    // A unique name never collides with the fixtures other tests write (the preflight globs *PanelProvider.php).
    $leaked = $this->skeletonBasePath . '/app/Providers/Filament/ResumeLeakProbePanelProvider.php';
    $directoryExisted = is_dir(dirname($leaked));
    acquireCapellInstallFilesystemLock();
    File::ensureDirectoryExists(dirname($leaked));
    File::put($leaked, "<?php\n\nclass ResumeLeakProbePanelProvider {}\n");
    expect(file_exists($leaked))->toBeTrue();

    try {
        CapellExtension::query()->where('composer_name', 'capell-app/core')->delete();
        CapellCore::clearExtensionCache();
        InstallRunState::clearStoppedStep();
        $inputs = new ArrayObject;
        bindResumeScenarioExecutor($inputs);
        ClearCachesAction::mock()->shouldReceive('handle')->andThrow(new RuntimeException('Cache store is unreachable.'));

        $exit = Artisan::call('capell:install', [
            '--no-interaction' => true,
            '--url' => 'https://resume.example.test',
            '--site-name' => 'Resume Site',
            '--no-seed-default-data' => true,
            '--no-frontend-build' => true,
            '--package-mode' => 'custom',
            '--packages' => 'capell-app/core',
            '--user' => User::factory()->createOne()->email,
        ]);

        expect($exit)->not->toBe(0)
            ->and(InstallRunState::stoppedStep()?->key)->toBe(InstallPlan::STEP_CLEAR_CACHES);
    } finally {
        File::delete($leaked);

        if (! $directoryExisted) {
            @rmdir(dirname($leaked));
            @rmdir(dirname($leaked, 2));
        }

        releaseCapellInstallFilesystemLock();
    }
});
