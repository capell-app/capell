<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunArtisanCommandAction;
use Capell\Core\Contracts\ProgressReporter;
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
