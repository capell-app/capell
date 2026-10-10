<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\GenerateSitemapAction;
use Capell\Core\Actions\Install\InstallDeveloperToolingAction;
use Capell\Core\Actions\Install\RunArtisanCommandAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Support\Install\DeveloperToolingInstallationState;
use Capell\Core\Tests\Support\Fixtures\Autoload\InstallSupportActionReporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

it('reports artisan command output', function (): void {
    Artisan::command('capell:test-run-artisan-success', function (): int {
        $this->line('published assets');

        return 0;
    });

    $reported = [];
    $reporter = new class($reported) implements ProgressReporter
    {
        public function __construct(private array &$reported) {}

        #[Override]
        public function step(string $label): void {}

        #[Override]
        public function report(string $line): void
        {
            $this->reported[] = $line;
        }

        #[Override]
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

        #[Override]
        public function step(string $label): void {}

        #[Override]
        public function report(string $line): void {}

        #[Override]
        public function error(string $line): void
        {
            $this->reportedErrors[] = $line;
        }
    };

    expect(fn (): mixed => RunArtisanCommandAction::run('capell:test-run-artisan-fails', [], $reporter))
        ->toThrow(RuntimeException::class, "Artisan command 'capell:test-run-artisan-fails' failed with exit code 1.");

    expect($reportedErrors)->toBe(['publish failed']);
});

it('retains silent install failure output', function (): void {
    Artisan::command('test:late-install {--force}', function (): int {
        $this->error('Install failed.');

        return 17;
    });
    $reporter = new InstallSupportActionReporter;

    expect(fn (): mixed => RunArtisanCommandAction::run('test:late-install', ['--force' => true], $reporter, true))
        ->toThrow(RuntimeException::class, "Artisan command 'test:late-install' failed with exit code 17.");
    expect($reporter->lines)->toBe([['error', 'Install failed.']]);
});

it('reports sitemap success only after the generator succeeds', function (): void {
    Artisan::command('capell:xml-sitemap', fn (): int => 0);
    $reporter = new InstallSupportActionReporter;

    GenerateSitemapAction::run($reporter);

    expect($reporter->lines)->toBe([
        ['step', 'Generating XML sitemaps…'], ['report', '✓ Sitemaps generated'],
    ]);
});

it('preserves boost configuration failure and its diagnostic', function (): void {
    $directory = sys_get_temp_dir() . '/capell-boost-' . bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory . '/composer.json', json_encode(['require' => ['capell-app/core' => '*']], JSON_THROW_ON_ERROR));
    InstallDeveloperToolingAction::setComposerJsonPath($directory . '/composer.json');
    InstallDeveloperToolingAction::setBoostJsonPath($directory . '/boost.json');
    $installed = Mockery::mock(DeveloperToolingInstallationState::class);
    $installed->shouldReceive('isInstalled')->andReturnTrue();
    app()->instance(DeveloperToolingInstallationState::class, $installed);
    Artisan::command('boost:install {--guidelines} {--skills} {--mcp}', function (): int {
        $this->line('Boost setup failed.');

        return 23;
    });
    $reporter = new InstallSupportActionReporter;

    try {
        expect(fn (): mixed => InstallDeveloperToolingAction::run($reporter, true))
            ->toThrow(RuntimeException::class, "Command 'boost:install' failed with exit code 23.");
        expect($reporter->lines)->toContain(['report', 'Boost setup failed.']);
    } finally {
        InstallDeveloperToolingAction::resetComposerJsonPath();
        InstallDeveloperToolingAction::resetBoostJsonPath();
        File::deleteDirectory($directory);
    }
});
