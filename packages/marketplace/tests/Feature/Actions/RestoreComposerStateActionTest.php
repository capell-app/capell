<?php

declare(strict_types=1);

use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Marketplace\Actions\RestoreComposerStateAction;
use Capell\Marketplace\Actions\SnapshotComposerStateAction;
use Capell\Marketplace\Tests\Support\InMemoryComposerFilesystem;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @param array<string, string> $contents */
function marketplaceRollbackFilesystem(array $contents): InMemoryComposerFilesystem
{
    return new InMemoryComposerFilesystem($contents);
}

it('snapshots the application composer state through the shared core class', function (): void {
    $filesystem = marketplaceRollbackFilesystem([
        base_path('composer.json') => '{"require":{"capell-app/core":"^1.0"}}',
        base_path('composer.lock') => '{"packages":[]}',
    ]);
    app()->instance(Filesystem::class, $filesystem);

    $snapshot = SnapshotComposerStateAction::run();

    expect($snapshot)->toBeInstanceOf(ComposerStateSnapshot::class)
        ->and($snapshot->composerContents)->toBe('{"require":{"capell-app/core":"^1.0"}}');
});

it('does not shell out to Composer when the operation never changed the manifests', function (): void {
    // The failure path is the worst possible moment to start a minutes-long,
    // network-dependent subprocess that would only rebuild what is already
    // there.
    $filesystem = marketplaceRollbackFilesystem([
        base_path('composer.json') => '{"require":{}}',
        base_path('composer.lock') => '{"packages":[]}',
    ]);
    app()->instance(Filesystem::class, $filesystem);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->never();
    app()->instance(ProcessFactoryInterface::class, $factory);

    $rebuilt = RestoreComposerStateAction::run(SnapshotComposerStateAction::run(), 60);

    expect($rebuilt)->toBeFalse();
});

it('restores the manifests and rebuilds vendor when the operation did change them', function (): void {
    $filesystem = marketplaceRollbackFilesystem([
        base_path('composer.json') => '{"require":{"capell-app/core":"^1.0"}}',
        base_path('composer.lock') => '{"packages":[{"name":"capell-app/core"}]}',
    ]);
    app()->instance(Filesystem::class, $filesystem);
    $snapshot = SnapshotComposerStateAction::run();

    $filesystem->contents[base_path('composer.json')] = '{"require":{"capell-app/core":"^1.0","vendor/bad":"^9.9"}}';
    $filesystem->contents[base_path('composer.lock')] = '{"packages":[{"name":"vendor/bad"}]}';

    $capturedEnvironment = null;
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->once()->andReturnUsing(function (array $environment) use (&$capturedEnvironment, $process): Process {
        $capturedEnvironment = $environment;

        return $process;
    });
    $process->shouldReceive('setTimeout')->with(60)->once()->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturn(0);
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->with([...capellComposerArgv(), 'install', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

    $rebuilt = RestoreComposerStateAction::run($snapshot, 60);

    expect($rebuilt)->toBeTrue()
        ->and($filesystem->contents[base_path('composer.json')])->toBe('{"require":{"capell-app/core":"^1.0"}}')
        ->and($filesystem->contents[base_path('composer.lock')])->toBe('{"packages":[{"name":"capell-app/core"}]}')
        // The rollback has to reach the network and the Composer cache the same
        // way the install it is undoing did.
        ->and($capturedEnvironment['COMPOSER_CACHE_DIR'] ?? null)->toBeString()
        ->and($capturedEnvironment['COMPOSER_HOME'] ?? null)->toBe(storage_path('framework/composer'));
});

it('throws rather than reporting a rollback it could not complete', function (): void {
    $filesystem = marketplaceRollbackFilesystem([
        base_path('composer.json') => '{"require":{}}',
        base_path('composer.lock') => '{"packages":[]}',
    ]);
    app()->instance(Filesystem::class, $filesystem);
    $snapshot = SnapshotComposerStateAction::run();

    $filesystem->contents[base_path('composer.lock')] = '{"packages":[{"name":"vendor/bad"}]}';

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturn(1);
    $process->shouldReceive('isSuccessful')->andReturnFalse();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

    expect(fn (): bool => RestoreComposerStateAction::run($snapshot, 60))
        ->toThrow(RuntimeException::class, ComposerStateSnapshot::UNRECOVERABLE_MESSAGE);
});
