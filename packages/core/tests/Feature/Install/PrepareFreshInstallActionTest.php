<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\PrepareFreshInstallAction;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Install\CapturingFreshInstallProgressReporter;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bus\PendingDispatch;

it('wipes the database without running migrations during fresh install preparation', function (): void {
    $kernel = new class implements Kernel
    {
        public ?string $command = null;

        /** @var array<string, mixed>|null */
        public ?array $parameters = null;

        public function bootstrap(): void {}

        public function handle($input, $output = null): int
        {
            return 0;
        }

        public function call($command, array $parameters = [], $outputBuffer = null): int
        {
            $this->command = $command;
            $this->parameters = $parameters;

            return 0;
        }

        public function queue($command, array $parameters = []): PendingDispatch
        {
            throw new RuntimeException('Queue is not expected during fresh install preparation.');
        }

        public function all(): array
        {
            return [];
        }

        public function output(): string
        {
            return '';
        }

        public function terminate($input, $status): void {}
    };

    app()->instance(Kernel::class, $kernel);
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    $reporter = new CapturingFreshInstallProgressReporter;

    PrepareFreshInstallAction::run($reporter);

    expect($kernel->command)->toBe('db:wipe')
        ->and($kernel->parameters)->toBe([
            '--force' => true,
        ])
        ->and($reporter->lines)->toBe([
            'Refreshing database for fresh Capell install…',
            'Database refreshed.',
        ]);
});

it('deletes published package migrations before wiping a fresh install database', function (): void {
    $packagePath = sys_get_temp_dir() . '/capell-fresh-install-package-' . bin2hex(random_bytes(8));
    $sourceMigration = $packagePath . '/database/migrations/2026_05_10_190832_01_create_fresh_package_table.php';
    $publishedMigration = database_path('migrations/2026_05_10_190832_01_create_fresh_package_table.php');

    $kernel = new class implements Kernel
    {
        public ?string $command = null;

        public function bootstrap(): void {}

        public function handle($input, $output = null): int
        {
            return 0;
        }

        public function call($command, array $parameters = [], $outputBuffer = null): int
        {
            $this->command = $command;

            return 0;
        }

        public function queue($command, array $parameters = []): PendingDispatch
        {
            throw new RuntimeException('Queue is not expected during fresh install preparation.');
        }

        public function all(): array
        {
            return [];
        }

        public function output(): string
        {
            return '';
        }

        public function terminate($input, $status): void {}
    };

    $filesystem = new FakeMigrationFilesystem([
        'glob' => [
            $packagePath . '/database/migrations/*.php' => [$sourceMigration],
            $packagePath . '/database/migrations/*.php.stub' => [],
        ],
        'fileExists' => [
            $publishedMigration => true,
        ],
    ]);

    app()->instance(Kernel::class, $kernel);
    app()->instance(MigrationFilesystemInterface::class, $filesystem);

    CapellCore::registerPackage('vendor/fresh-package', PackageTypeEnum::Plugin, path: $packagePath, version: '1.0.0');

    $reporter = new CapturingFreshInstallProgressReporter;

    PrepareFreshInstallAction::run($reporter);

    expect($filesystem->calls)->toContain(['delete', $publishedMigration])
        ->and($kernel->command)->toBe('db:wipe')
        ->and($reporter->lines)->toContain('Published migrations cleaned: 1 deleted, 0 blocked, 0 skipped.');
});
