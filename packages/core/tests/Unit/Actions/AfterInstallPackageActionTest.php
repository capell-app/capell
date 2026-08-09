<?php

declare(strict_types=1);

use Capell\Core\Actions\AfterInstallPackageAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

it('runs after-install command and forwards output to reporter', function (): void {
    Artisan::command('capell:test-after-command', function (): int {
        $this->line('after-install ran');

        return 0;
    });

    $package = new PackageData(
        name: 'capell-app/frontend',
        type: PackageTypeEnum::Plugin,
        afterInstallCommand: 'capell:test-after-command',
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

    AfterInstallPackageAction::run($package, [], $reporter);

    expect($reported)->not->toBeEmpty();
})->group('core', 'unit');

it('runs after-install command without reporter', function (): void {
    $invoked = false;
    Artisan::command('capell:test-after-command-no-reporter', function () use (&$invoked): int {
        $invoked = true;

        return 0;
    });

    $package = new PackageData(
        name: 'capell-app/frontend',
        type: PackageTypeEnum::Plugin,
        afterInstallCommand: 'capell:test-after-command-no-reporter',
    );

    AfterInstallPackageAction::run($package);

    expect($invoked)->toBeTrue();
})->group('core', 'unit');

it('throws when after-install command is missing', function (): void {
    $probeProcess = Mockery::mock(Process::class);
    $probeProcess->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $probeProcess->shouldReceive('run')->once()->with()->andReturn(0);
    $probeProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $probeProcess->shouldReceive('getOutput')->once()->andReturn("capell:some-other-command  A registered command\n");

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->withArgs(fn (array $command, string $workingDirectory): bool => $command === [
            PHP_BINARY,
            base_path('artisan'),
            'list',
            '--raw',
            '--no-interaction',
        ] && $workingDirectory === base_path())
        ->andReturn($probeProcess);

    app()->instance(ProcessFactoryInterface::class, $factory);
    config()->set(RuntimeBinaryResolver::PHP_CONFIG_KEY, PHP_BINARY);

    $package = new PackageData(
        name: 'capell-app/frontend',
        type: PackageTypeEnum::Plugin,
        afterInstallCommand: 'capell:missing-command',
    );

    AfterInstallPackageAction::run($package);
})->throws(RuntimeException::class, "After Install command 'capell:missing-command' does not exist.")->group('core', 'unit');
