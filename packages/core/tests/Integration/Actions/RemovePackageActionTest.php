<?php

declare(strict_types=1);

use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\SymfonyProcessFactory;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $mockProcess = Mockery::mock(Process::class);

    $mockProcess
        ->shouldReceive('setEnv')
        ->with(Mockery::on(fn (array $environment): bool => ($environment['GIT_CONFIG_KEY_0'] ?? null) === 'safe.directory'
            && ($environment['GIT_CONFIG_VALUE_0'] ?? null) === '*'))
        ->andReturnSelf();

    $mockProcess
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();

    $mockProcess
        ->shouldReceive('run')
        ->andReturn(0);

    $mockProcess
        ->shouldReceive('getErrorOutput')
        ->andReturn('');

    $mockProcess
        ->shouldReceive('getOutput')
        ->andReturn('Package vendor/package removed');

    $mockProcess
        ->shouldReceive('isSuccessful')
        ->andReturnTrue();

    $mockFactory = Mockery::mock(ProcessFactoryInterface::class);

    $mockFactory
        ->shouldReceive('make')
        ->with(Mockery::on(fn (array|string $command): bool => $command === ['composer', 'remove', 'vendor/package', '--no-interaction', '--no-scripts']), Mockery::type('string'))
        ->andReturn($mockProcess);

    app()->instance(ProcessFactoryInterface::class, $mockFactory);
});

it('removes a package', function (): void {
    $filesystem = new class extends Filesystem
    {
        /** @var list<list<string>> */
        public array $deletedPaths = [];

        public function delete($paths): bool
        {
            $this->deletedPaths[] = array_values((array) $paths);

            return true;
        }
    };

    app()->instance(Filesystem::class, $filesystem);

    $result = RemovePackageAction::run('vendor/package');
    $deletedPaths = collect($filesystem->deletedPaths)->flatten()->all();

    expect($result)
        ->toBeArray()
        ->and($result['status'] ?? null)->toBe('removed')
        ->and($result['cache_cleared'] ?? null)->toBeTrue()
        ->and($deletedPaths)->toContain(
            base_path('bootstrap/cache/capell-package-manifests.php'),
            base_path('bootstrap/cache/capell-theme-chain.php'),
        )
        ->and($deletedPaths)->not->toContain(
            base_path('bootstrap/cache/packages.php'),
            base_path('bootstrap/cache/services.php'),
        );
});

it('builds a symfony process from the factory', function (): void {
    $factory = new SymfonyProcessFactory;

    $process = $factory->make(['composer', 'remove', 'vendor/package', '--no-interaction', '--no-scripts'], base_path());
    $commandLine = $process->getCommandLine();

    expect($process)
        ->toBeInstanceOf(Process::class)
        ->and($commandLine)->toContain('composer')
        ->and($commandLine)->toContain('remove')
        ->and($commandLine)->toContain('vendor/package')
        ->and($commandLine)->toContain('--no-scripts')
        ->and($process->getWorkingDirectory())->toBe(base_path());
});
