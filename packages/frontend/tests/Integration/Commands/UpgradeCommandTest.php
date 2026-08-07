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

    $exitCode = makeFrontendUpgradeCommand($calls)->handle();

    expect($exitCode)->toBe(0)
        ->and($calls)->toContain([
            'vendor:publish', ['--tag' => 'capell-migrations'],
        ])
        ->and($calls)->toContain([
            'migrate', ['--force' => true],
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

    expect(makeFrontendUpgradeCommand(
        $calls,
        settingsMigrationExitCode: Command::FAILURE,
    )->handle())->toBe(Command::FAILURE)
        ->and($calls)->not->toContain([
            'vendor:publish', ['--tag' => 'capell-frontend-assets', '--force' => true],
        ])
        ->not->toContain([
            'vendor:publish', ['--tag' => 'capell-frontend-publish', '--force' => true],
        ]);
});

it('fails before schema and settings migrations when core migration publishing fails', function (): void {
    $calls = [];
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    expect(makeFrontendUpgradeCommand(
        $calls,
        migrationPublishExitCode: Command::FAILURE,
    )->handle())->toBe(Command::FAILURE)
        ->and($calls)->toBe([
            ['vendor:publish', ['--tag' => 'capell-migrations']],
        ])
        ->and($filesystem->calls)->toBe([]);
});

it('fails before settings publication when core schema migrations fail', function (): void {
    $calls = [];
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    expect(makeFrontendUpgradeCommand(
        $calls,
        schemaMigrationExitCode: Command::FAILURE,
    )->handle())->toBe(Command::FAILURE)
        ->and($calls)->toBe([
            ['vendor:publish', ['--tag' => 'capell-migrations']],
            ['migrate', ['--force' => true]],
        ])
        ->and($filesystem->calls)->toBe([]);
});

function makeFrontendUpgradeCommand(
    array &$calls,
    int $migrationPublishExitCode = Command::SUCCESS,
    int $schemaMigrationExitCode = Command::SUCCESS,
    int $settingsMigrationExitCode = Command::SUCCESS,
): UpgradeCommand {
    $command = new class($calls, $migrationPublishExitCode, $schemaMigrationExitCode, $settingsMigrationExitCode) extends UpgradeCommand
    {
        public function __construct(
            public array &$calls,
            private readonly int $migrationPublishExitCode,
            private readonly int $schemaMigrationExitCode,
            private readonly int $settingsMigrationExitCode,
        ) {
            parent::__construct();
        }

        public function call(mixed $command, array $arguments = []): int
        {
            $this->calls[] = [$command, $arguments];

            if ($command === 'vendor:publish' && $arguments === ['--tag' => 'capell-migrations']) {
                return $this->migrationPublishExitCode;
            }

            if ($command === 'migrate' && $arguments === ['--force' => true]) {
                return $this->schemaMigrationExitCode;
            }

            if ($command === 'migrate' && $arguments === [
                '--path' => 'database/settings',
                '--force' => true,
            ]) {
                return $this->settingsMigrationExitCode;
            }

            return self::SUCCESS;
        }
    };

    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

    return $command;
}
