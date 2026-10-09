<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\CallArtisanCommandAction;
use Capell\Core\Actions\Install\GenerateSitemapAction;
use Capell\Core\Actions\Install\InstallDeveloperToolingAction;
use Capell\Core\Actions\Install\RunArtisanCommandAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\ArtisanCommandResultData;
use Capell\Core\Tests\Support\Fixtures\Autoload\InstallSupportActionReporter;
use Capell\Core\Tests\Support\Install\RecordingConsoleKernel;
use Illuminate\Support\Facades\Artisan;

it('reports artisan command output', function (): void {
    Artisan::command('capell:test-run-artisan-success', function (): int {
        $this->line('published assets');

        return 0;
    });

    $reported = [];
    $reporter = new class($reported) implements ProgressReporter
    {
        public function __construct(private array &$reported) {}

        public function step(string $label): void {}

        public function report(string $line): void
        {
            $this->reported[] = $line;
        }

        public function error(string $line): void {}
    };

    RunArtisanCommandAction::run('capell:test-run-artisan-success', [], $reporter);

    expect($reported)->toBe(['published assets']);
});

it('throws with command output when artisan command fails', function (): void {
    Artisan::command('capell:test-run-artisan-fails', function (): int {
        $this->error('publish failed');

        return 1;
    });

    $reportedErrors = [];
    $reporter = new class($reportedErrors) implements ProgressReporter
    {
        public function __construct(private array &$reportedErrors) {}

        public function step(string $label): void {}

        public function report(string $line): void {}

        public function error(string $line): void
        {
            $this->reportedErrors[] = $line;
        }
    };

    expect(fn (): mixed => RunArtisanCommandAction::run('capell:test-run-artisan-fails', [], $reporter))
        ->toThrow(RuntimeException::class, "Artisan command 'capell:test-run-artisan-fails' failed with exit code 1.");

    expect($reportedErrors)->toBe(['publish failed']);
});

it('routes install artisan calls through the shared helper and retains silent failure output', function (): void {
    $kernel = RecordingConsoleKernel::bind();
    $spy = bindFakeAction(CallArtisanCommandAction::class, new ArtisanCommandResultData(17, '', 'Install failed.'));
    $reporter = new InstallSupportActionReporter;

    expect(fn (): mixed => RunArtisanCommandAction::run('test:late-install', ['--force' => true], $reporter, true))
        ->toThrow(RuntimeException::class, "Artisan command 'test:late-install' failed with exit code 17.");

    expect($spy->args)->toBe(['test:late-install', ['--force' => true]])
        ->and($reporter->lines)->toBe([['error', 'Install failed.']])
        ->and($kernel->calls)->toBe([]);
});

it('routes sitemap generation through the shared helper', function (): void {
    $kernel = RecordingConsoleKernel::bind();
    $spy = bindFakeAction(CallArtisanCommandAction::class, new ArtisanCommandResultData(0, 'generated'));
    $reporter = new InstallSupportActionReporter;

    GenerateSitemapAction::run($reporter);

    expect($spy->args)->toBe(['capell:xml-sitemap', []])
        ->and($reporter->lines)->toBe([
            ['step', 'Generating XML sitemaps…'], ['report', '✓ Sitemaps generated'],
        ])
        ->and($kernel->calls)->toBe([]);
});

it('routes boost configuration through the shared helper and preserves its failure code', function (): void {
    $kernel = RecordingConsoleKernel::bind();
    InstallDeveloperToolingAction::resetArtisanCaller();
    $spy = bindFakeAction(CallArtisanCommandAction::class, new ArtisanCommandResultData(23, 'Boost setup failed.'));
    $reporter = new InstallSupportActionReporter;
    $action = resolve(InstallDeveloperToolingAction::class);

    expect(fn (): mixed => new ReflectionMethod($action, 'configureBoost')->invoke($action, $reporter))
        ->toThrow(RuntimeException::class, "Command 'boost:install' failed with exit code 23.");

    expect($spy->args)->toBe(['boost:install', [
        '--guidelines' => true, '--skills' => true, '--mcp' => true, '--no-interaction' => true,
    ]])->and($reporter->lines)->toContain(['report', 'Boost setup failed.'])
        ->and($kernel->calls)->toBe([]);
});

afterEach(function (): void {
    RecordingConsoleKernel::release();
});
