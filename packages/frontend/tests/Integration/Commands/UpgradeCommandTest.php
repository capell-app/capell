<?php

declare(strict_types=1);

use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Capell\Frontend\Console\Commands\UpgradeCommand;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

it('runs frontend upgrade command successfully', function (): void {
    $calls = [];
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    $mock = new class($calls) extends UpgradeCommand
    {
        public array $calls;

        public function __construct(array &$calls)
        {
            $this->calls = &$calls;
            parent::__construct();
        }

        public function call(mixed $command, array $arguments = []): int
        {
            $this->calls[] = [$command, $arguments];

            return 0;
        }
    };

    $mock->setLaravel(app());
    $mock->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

    $exitCode = $mock->handle();

    expect($exitCode)->toBe(0)
        ->and($calls)->toContain([
            'vendor:publish', ['--tag' => 'capell-migrations'],
        ])
        ->and($calls)->toContain([
            'migrate', [],
        ])
        ->and($calls)->toContain([
            'migrate', ['--path' => 'database/settings', '--force' => true],
        ])
        ->and($calls)->toContain([
            'vendor:publish', ['--tag' => 'capell-frontend-assets', '--force' => true],
        ])
        ->and($calls)->toContain([
            'vendor:publish', ['--tag' => 'capell-frontend-publish', '--force' => true],
        ])
        ->and(collect($filesystem->calls)->contains(
            fn (array $call): bool => $call[0] === 'fileExists'
                && str_ends_with(
                    (string) $call[1],
                    '/database/settings/2026_07_29_000001_add_scheduled_publication_invalidation_checkpoint.php',
                ),
        ))->toBeTrue();
});

it('fails before publishing assets when frontend settings migrations fail', function (): void {
    $calls = [];
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    $mock = new class($calls) extends UpgradeCommand
    {
        public array $calls;

        public function __construct(array &$calls)
        {
            $this->calls = &$calls;
            parent::__construct();
        }

        public function call(mixed $command, array $arguments = []): int
        {
            $this->calls[] = [$command, $arguments];

            if ($command === 'migrate' && $arguments === [
                '--path' => 'database/settings',
                '--force' => true,
            ]) {
                return self::FAILURE;
            }

            return self::SUCCESS;
        }
    };

    $mock->setLaravel(app());
    $mock->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

    expect($mock->handle())->toBe(Command::FAILURE)
        ->and($calls)->not->toContain([
            'vendor:publish', ['--tag' => 'capell-frontend-assets', '--force' => true],
        ])
        ->not->toContain([
            'vendor:publish', ['--tag' => 'capell-frontend-publish', '--force' => true],
        ]);
});
