<?php

declare(strict_types=1);

use Capell\Core\Actions\SetupPackageAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    LifecycleRecorderAction::reset();
});

afterEach(function (): void {
    LifecycleRecorderAction::reset();
});

it('runs the setup command via Artisan', function (): void {
    $invoked = false;
    Artisan::command('capell:test-setup-command', function () use (&$invoked): int {
        $invoked = true;

        return 0;
    });

    $package = new PackageData(
        name: 'TestPackage',
        type: PackageTypeEnum::Plugin,
        setupCommand: 'capell:test-setup-command',
    );

    SetupPackageAction::run($package);

    expect($invoked)->toBeTrue();
});

it('throws if the setup command does not exist', function (): void {
    $package = new PackageData(
        name: 'TestPackage',
        type: PackageTypeEnum::Plugin,
        setupCommand: 'capell:nonexistent-setup-command',
    );

    expect(fn () => SetupPackageAction::run($package))
        ->toThrow(Exception::class, "Setup command 'capell:nonexistent-setup-command' does not exist.");
});

it('does nothing if setupCommand is null', function (): void {
    $package = new PackageData(
        name: 'TestPackage',
        type: PackageTypeEnum::Plugin,
        setupCommand: null,
    );

    // Should complete without error and without calling Artisan
    SetupPackageAction::run($package);

    expect(true)->toBeTrue();
});

it('forwards artisan output to reporter when provided', function (): void {
    Artisan::command('capell:test-setup-reporter', function (): int {
        $this->line('setup done');

        return 0;
    });

    $package = new PackageData(
        name: 'TestPackage',
        type: PackageTypeEnum::Plugin,
        setupCommand: 'capell:test-setup-reporter',
    );

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

    SetupPackageAction::run($package, [], $reporter);

    expect($reported)->not->toBeEmpty();
});

it('uses a cli php executable when the configured binary points at php fpm', function (): void {
    $temporaryDirectory = storage_path('framework/testing/setup-package-action-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $commandLogPath = $temporaryDirectory . '/command.log';
    $fakePhpPath = $temporaryDirectory . '/php';
    $fakePhpFpmPath = $temporaryDirectory . '/php-fpm';
    $path = getenv('PATH');
    $originalPath = $path !== false ? $path : '';

    File::put($fakePhpPath, "#!/bin/sh\necho \"$0 $@\" > " . escapeshellarg($commandLogPath) . "\nexit 0\n");
    File::put($fakePhpFpmPath, "#!/bin/sh\necho \"Usage: php-fpm\" >&2\nexit 64\n");
    chmod($fakePhpPath, 0755);
    chmod($fakePhpFpmPath, 0755);

    putenv('PATH=' . $temporaryDirectory . PATH_SEPARATOR . $originalPath);
    config(['capell-installer.php_binary' => $fakePhpFpmPath]);

    try {
        Artisan::command('capell:admin-setup', fn (): int => 0);

        $package = new PackageData(
            name: 'capell-app/admin',
            type: PackageTypeEnum::Plugin,
            setupCommand: 'capell:admin-setup',
        );

        SetupPackageAction::run($package, ['assets' => ['resources/css/app.css', 'resources/js/app.js']]);

        expect(File::get($commandLogPath))
            ->toContain($fakePhpPath)
            ->toContain('artisan capell:admin-setup')
            ->toContain('--no-interaction')
            ->toContain('--assets=resources/css/app.css')
            ->toContain('--assets=resources/js/app.js');
    } finally {
        putenv('PATH=' . $originalPath);

        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.php_binary' => 'php']);
    }
});

it('prefers setup lifecycle actions over the admin setup fresh process adapter', function (): void {
    $package = new PackageData(
        name: 'capell-app/admin',
        type: PackageTypeEnum::Plugin,
        setupCommand: 'capell:admin-setup',
        setupAction: LifecycleRecorderAction::class,
    );

    SetupPackageAction::run($package, ['assets' => ['resources/css/app.css']], null, false);

    expect(LifecycleRecorderAction::$calls)->toBe([
        [
            'package' => 'capell-app/admin',
            'arguments' => ['assets' => ['resources/css/app.css']],
        ],
    ]);
});

it('rejects legacy admin setup commands in web mode instead of using the fresh process adapter', function (): void {
    $package = new PackageData(
        name: 'capell-app/admin',
        type: PackageTypeEnum::Plugin,
        setupCommand: 'capell:admin-setup',
    );

    expect(fn () => SetupPackageAction::run($package, [], null, false))
        ->toThrow(RuntimeException::class, 'web-triggered package lifecycle work must use a lifecycle Action');
});

it('streams admin setup process output to the progress reporter', function (): void {
    $temporaryDirectory = storage_path('framework/testing/setup-package-action-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $fakePhpPath = $temporaryDirectory . '/php';
    File::put($fakePhpPath, "#!/bin/sh\necho \"Admin setup: creating layouts\"\necho \"Admin setup: syncing dashboard Filament widget settings\" >&2\nexit 0\n");
    chmod($fakePhpPath, 0755);
    config(['capell-installer.php_binary' => $fakePhpPath]);

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

    try {
        Artisan::command('capell:admin-setup', fn (): int => 0);

        $package = new PackageData(
            name: 'capell-app/admin',
            type: PackageTypeEnum::Plugin,
            setupCommand: 'capell:admin-setup',
        );

        SetupPackageAction::run($package, [], $reporter);

        expect($reportedLines)
            ->toContain('Admin setup: creating layouts')
            ->toContain('Admin setup: syncing dashboard Filament widget settings');
    } finally {
        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.php_binary' => 'php']);
    }
});

it('throws setup command failure details without reporting them twice', function (): void {
    $temporaryDirectory = storage_path('framework/testing/setup-package-action-' . uniqid());
    File::makeDirectory($temporaryDirectory, 0755, true);

    $fakePhpPath = $temporaryDirectory . '/php';
    File::put($fakePhpPath, "#!/bin/sh\necho \"database column missing\" >&2\nexit 2\n");
    chmod($fakePhpPath, 0755);
    config(['capell-installer.php_binary' => $fakePhpPath]);

    $reportedLines = [];
    $reportedErrors = [];
    $reporter = new class($reportedLines, $reportedErrors) implements ProgressReporter
    {
        public function __construct(private array &$reportedLines, private array &$reportedErrors) {}

        public function step(string $label): void {}

        public function report(string $line): void
        {
            $this->reportedLines[] = $line;
        }

        public function error(string $line): void
        {
            $this->reportedErrors[] = $line;
        }
    };

    try {
        Artisan::command('capell:admin-setup', fn (): int => 0);

        $package = new PackageData(
            name: 'capell-app/admin',
            type: PackageTypeEnum::Plugin,
            setupCommand: 'capell:admin-setup',
        );

        expect(fn () => SetupPackageAction::run($package, [], $reporter))
            ->toThrow(Exception::class, 'Output: database column missing');

        expect($reportedLines)
            ->toBe(['database column missing'])
            ->and($reportedErrors)->toBeEmpty();
    } finally {
        File::deleteDirectory($temporaryDirectory);
        config(['capell-installer.php_binary' => 'php']);
    }
});
