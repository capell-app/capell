<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\BuildInstallSummaryLinesAction;
use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Actions\Install\MakeInstallProgressReporterAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Install\ConciseConsoleProgressReporter;
use Capell\Core\Support\Install\ConsoleProgressReporter;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

const PROGRESS_DETAIL_LINE = 'Detail line only the verbose log shows.';

/**
 * Runs the real install command. Every step is a no-op that logs one detail line, except the real
 * executor handles the cache step and the closing lifecycle step, so no package, migration or
 * process side effects happen.
 */
function bindProgressScenarioExecutor(): void
{
    $real = new InstallStepExecutor;

    app()->instance(InstallStepExecutor::class, new readonly class($real)
    {
        public function __construct(private InstallStepExecutor $real) {}

        public function execute(string $stepKey, InstallRunState $state): InstallRunState
        {
            $state->reporter->report(PROGRESS_DETAIL_LINE);

            if (in_array($stepKey, [InstallPlan::STEP_CLEAR_CACHES, InstallPlan::STEP_MARK_CORE_INSTALLED], true)) {
                return $this->real->execute($stepKey, $state);
            }

            return $state;
        }
    });
}

/** @return array<string, mixed> */
function progressScenarioOptions(): array
{
    $author = User::factory()->createOne();

    return [
        '--no-interaction' => true,
        '--url' => 'https://progress.example.test',
        '--site-name' => 'Progress Site',
        '--no-seed-default-data' => true,
        '--no-frontend-build' => true,
        '--package-mode' => 'custom',
        '--packages' => 'capell-app/core',
        '--user' => $author->email,
    ];
}

function runProgressScenario(array $options, int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
{
    request()->server->set('argv', ['artisan', 'capell:install', ...array_map(
        static fn (string $name, mixed $value): string => $value === true ? $name : $name . '=' . $value,
        array_keys($options),
        $options,
    )]);
    $output = new BufferedOutput($verbosity);
    $exit = Artisan::call('capell:install', $options, $output);

    return [$exit, $output->fetch()];
}

beforeEach(function (): void {
    InstallRunState::completeInitialInstallation();
    CapellExtension::query()->where('composer_name', 'capell-app/core')->delete();
    CapellCore::clearExtensionCache();
    InstallRunState::clearStoppedStep();
    bindProgressScenarioExecutor();
    // The Composer dry-run needs the network and the host project's lock file.
    app()->instance(ProcessFactoryInterface::class, new class implements ProcessFactoryInterface
    {
        public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
        {
            return new Process([PHP_BINARY, '--version']);
        }
    });
});

afterEach(function (): void {
    InstallRunState::completeInitialInstallation();
    InstallRunState::clearStoppedStep();
    ClearCachesAction::clearFake();
});

it('ends with a short summary of what was installed, the addresses and the next steps', function (): void {
    [$exit, $output] = runProgressScenario(progressScenarioOptions());

    $summary = substr((string) $output, (int) strpos((string) $output, (string) __('capell-core::install.handoff.completed_title')));

    expect($exit)->toBe(0)
        ->and($output)->toContain((string) __('capell-core::install.handoff.completed_title'))
        ->and($summary)->toContain('capell-app/theme-foundation')
        ->toContain('https://progress.example.test/')
        ->toContain((string) __('capell-core::install.handoff.summary.admin'))
        ->toContain((string) __('capell-core::install.handoff.title'))
        ->and(count(explode("\n", trim($summary))))->toBeLessThanOrEqual(BuildInstallSummaryLinesAction::MAX_LINES)
        ->and($output)->not->toContain(PROGRESS_DETAIL_LINE);
});

it('shows one plain line per step and no escape codes when the terminal cannot draw a progress bar', function (): void {
    [$exit, $output] = runProgressScenario(progressScenarioOptions());

    $firstStep = (string) __('capell-core::install.progress.step', ['position' => 1, 'total' => 'TOTAL', 'label' => 'LABEL']);
    $pattern = '/' . str_replace(['TOTAL', 'LABEL'], ['\\d+', '.+'], preg_quote($firstStep, '/')) . '/';

    expect($exit)->toBe(0)
        ->and($output)->not->toContain("\e")
        ->and($output)->toMatch($pattern);
});

it('keeps the detailed install log and the full handoff with -v', function (): void {
    [$exit, $output] = runProgressScenario(progressScenarioOptions(), OutputInterface::VERBOSITY_VERBOSE);

    expect($exit)->toBe(0)
        ->and($output)->toContain(PROGRESS_DETAIL_LINE)
        ->and($output)->toContain('Public URL')
        ->and($output)->not->toContain((string) __('capell-core::install.handoff.summary.full_log'));
});

it('still names the stopped step and prints the resume command when a step fails', function (): void {
    ClearCachesAction::mock()->shouldReceive('handle')->andThrow(new RuntimeException('Cache store is unreachable.'));

    [$exit, $output] = runProgressScenario(progressScenarioOptions());
    $stopped = InstallRunState::stoppedStep();

    expect($exit)->not->toBe(0)
        ->and($stopped)->not->toBeNull()
        ->and($stopped?->key)->toBe(InstallPlan::STEP_CLEAR_CACHES)
        ->and($output)->toContain(InstallPlan::STEP_CLEAR_CACHES)
        ->toContain((string) __('capell-core::install.recovery.failed', ['step' => InstallPlan::STEP_CLEAR_CACHES]))
        ->toContain((string) __('capell-core::health.install_lifecycle.stopped_remediation', ['command' => (string) $stopped?->resumeCommand]))
        ->toContain('Cache store is unreachable.')
        // The log kept back during the run is shown for the failing step so the cause is visible.
        ->toContain(PROGRESS_DETAIL_LINE)
        ->toContain((string) __('capell-core::install.progress.last_output'));
});

it('draws a progress bar through the steps on a terminal that supports one, then closes it', function (): void {
    $buffer = new BufferedOutput(decorated: true);
    $command = new class extends Command {};
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    $reporter = new ConciseConsoleProgressReporter($command, useProgressBar: true);

    $reporter->advance(1, 3, 'First step');
    $reporter->report('hidden detail');
    $reporter->advance(2, 3, 'Second step');
    $reporter->advance(3, 3, 'Third step');
    $reporter->finish();

    $output = $buffer->fetch();

    expect($output)->toContain((string) __('capell-core::install.progress.label'))
        ->toContain('First step')
        ->toContain('Third step')
        ->not->toContain('hidden detail');
});

it('prints plain lines instead of a bar when the reporter is told not to draw one', function (): void {
    $buffer = new BufferedOutput(decorated: true);
    $command = new class extends Command {};
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    $reporter = new ConciseConsoleProgressReporter($command, useProgressBar: false);

    $reporter->advance(1, 2, 'First step');
    $reporter->advance(2, 2, 'Second step');
    $reporter->finish();

    expect($buffer->fetch())->toContain((string) __('capell-core::install.progress.step', ['position' => 1, 'total' => 2, 'label' => 'First step']))
        ->toContain((string) __('capell-core::install.progress.step', ['position' => 2, 'total' => 2, 'label' => 'Second step']))
        ->not->toContain("\e[?25l");
});

function progressCommand(BufferedOutput $buffer): Command
{
    $command = new class extends Command {};
    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return $command;
}

it('gives the cursor back after the bar finishes or is abandoned, even after a nested Artisan call', function (): void {
    foreach (['finish', 'abandon'] as $ending) {
        $buffer = new BufferedOutput(decorated: true);
        $reporter = new ConciseConsoleProgressReporter(progressCommand($buffer), useProgressBar: true);

        $reporter->advance(1, 2, 'First step');
        // A nested command re-binds Prompts to its own buffered output.
        Artisan::call('list', [], new BufferedOutput);
        $reporter->{$ending}();

        expect($buffer->fetch())->toContain("\e[?25h");
    }
});

it('prints a non-fatal error raised during the bar after the bar closes instead of losing it', function (): void {
    $buffer = new BufferedOutput(decorated: true);
    $reporter = new ConciseConsoleProgressReporter(progressCommand($buffer), useProgressBar: true);

    $reporter->advance(1, 2, 'First step');
    $reporter->error('Manual change needed.');
    $reporter->advance(2, 2, 'Second step');

    expect($buffer->fetch())->not->toContain('Manual change needed.');

    $reporter->finish();

    expect($buffer->fetch())->toContain('Manual change needed.');
});

it('chooses plain step lines for decorated output when the run is not interactive', function (): void {
    $buffer = new BufferedOutput(decorated: true);
    $command = progressCommand($buffer);
    $reporter = MakeInstallProgressReporterAction::run($command, interactive: false);
    $reporter->advance(1, 2, 'First step');

    expect($reporter)->toBeInstanceOf(ConciseConsoleProgressReporter::class)
        ->and($buffer->fetch())->not->toContain("\e[?25l");
});

it('keeps the detailed reporter for verbose output', function (): void {
    $command = progressCommand(new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE));

    expect(MakeInstallProgressReporterAction::run($command, interactive: true))->toBeInstanceOf(ConsoleProgressReporter::class);
});
