<?php

declare(strict_types=1);

use Capell\Core\Actions\DemoPackageAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    DemoPackageAction::resetProcessFactory();
});

afterEach(function (): void {
    DemoPackageAction::resetProcessFactory();
});

it('runs demo commands in a fresh artisan process', function (): void {
    Artisan::command('capell:test-demo-process', fn (): int => Command::SUCCESS);

    $capturedCommand = null;

    DemoPackageAction::setProcessFactory(function (array $command, string $cwd) use (&$capturedCommand): object {
        $capturedCommand = $command;

        return new class
        {
            public function setTimeout(?float $timeout): self
            {
                return $this;
            }

            public function run(?callable $callback = null): int
            {
                if ($callback !== null) {
                    $callback('out', 'demo output' . PHP_EOL);
                }

                return 0;
            }

            public function getOutput(): string
            {
                return 'demo output';
            }

            public function getErrorOutput(): string
            {
                return '';
            }

            public function isSuccessful(): bool
            {
                return true;
            }

            public function getExitCode(): int
            {
                return 0;
            }
        };
    });

    DemoPackageAction::run(
        new PackageData(
            name: 'TestPackage',
            type: PackageTypeEnum::Plugin,
            demoCommand: 'capell:test-demo-process',
        ),
        [
            '--languages' => ['en', 'fr'],
            '--force' => true,
            '--sites' => null,
        ],
    );

    expect($capturedCommand)->toContain('capell:test-demo-process')
        ->and($capturedCommand)->toContain('memory_limit=512M')
        ->and($capturedCommand)->toContain('--languages=en,fr')
        ->and($capturedCommand)->toContain('--force')
        ->and($capturedCommand)->toContain('--no-interaction')
        ->and($capturedCommand)->not->toContain('--sites');
});

it('throws when the demo process fails', function (): void {
    Artisan::command('capell:test-demo-fails', fn (): int => Command::SUCCESS);

    DemoPackageAction::setProcessFactory(fn (array $command, string $cwd): object => new class
    {
        public function setTimeout(?float $timeout): self
        {
            return $this;
        }

        public function run(?callable $callback = null): int
        {
            if ($callback !== null) {
                $callback('err', 'failed');
            }

            return 1;
        }

        public function getOutput(): string
        {
            return '';
        }

        public function getErrorOutput(): string
        {
            return 'failed';
        }

        public function isSuccessful(): bool
        {
            return false;
        }

        public function getExitCode(): int
        {
            return 1;
        }
    });

    expect(fn (): null => DemoPackageAction::run(
        new PackageData(
            name: 'TestPackage',
            type: PackageTypeEnum::Plugin,
            demoCommand: 'capell:test-demo-fails',
        ),
    ))->toThrow(Exception::class, "Demo command 'capell:test-demo-fails' failed with exit code 1.
Command: ")
        ->toThrow(Exception::class, 'Full output: ')
        ->toThrow(Exception::class, "Output tail:\nfailed");
});

it('streams demo process output to the progress reporter while the process runs', function (): void {
    Artisan::command('capell:test-demo-streams', fn (): int => Command::SUCCESS);

    DemoPackageAction::setProcessFactory(fn (array $command, string $cwd): object => new class
    {
        public function setTimeout(?float $timeout): self
        {
            return $this;
        }

        public function run(?callable $callback = null): int
        {
            if ($callback !== null) {
                $callback('out', "Creating demo languages\nCreating demo pages");
                $callback('out', "\nCreating demo widgets\n");
            }

            return 0;
        }

        public function getOutput(): string
        {
            return '';
        }

        public function getErrorOutput(): string
        {
            return '';
        }

        public function isSuccessful(): bool
        {
            return true;
        }

        public function getExitCode(): int
        {
            return 0;
        }
    });

    $reportedLines = [];
    $reporter = new class($reportedLines) implements ProgressReporter
    {
        public function __construct(private array &$reportedLines) {}

        public function step(string $label): void {}

        public function report(string $line): void
        {
            $this->reportedLines[] = $line;
        }

        public function error(string $line): void {}
    };

    DemoPackageAction::run(
        new PackageData(
            name: 'TestPackage',
            type: PackageTypeEnum::Plugin,
            demoCommand: 'capell:test-demo-streams',
        ),
        reporter: $reporter,
    );

    expect($reportedLines)->toBe([
        'Creating demo languages',
        'Creating demo pages',
        'Creating demo widgets',
    ]);
});
