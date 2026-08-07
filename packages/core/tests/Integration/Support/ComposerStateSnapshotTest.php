<?php

declare(strict_types=1);

use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The recovery path shared by every Composer-mutating operation in Capell.
 *
 * These are the tests that stop it being a path nobody exercises: the removal,
 * the Marketplace install, and the update and uninstall that will follow all
 * depend on exactly this behaviour.
 */
function composerSnapshotFilesystem(array $contents): Filesystem
{
    return new class($contents) extends Filesystem
    {
        /** @param array<string, string> $contents */
        public function __construct(public array $contents) {}

        #[Override]
        public function exists($path): bool
        {
            return array_key_exists((string) $path, $this->contents);
        }

        #[Override]
        public function get($path, $lock = false): string
        {
            return $this->contents[(string) $path];
        }

        #[Override]
        public function replace($path, $content, $mode = null): void
        {
            $this->contents[(string) $path] = (string) $content;
        }

        #[Override]
        public function delete($paths): bool
        {
            foreach ((array) $paths as $path) {
                unset($this->contents[(string) $path]);
            }

            return true;
        }
    };
}

it('captures both manifests as they were before anything ran', function (): void {
    $filesystem = composerSnapshotFilesystem([
        base_path('composer.json') => '{"require":{"vendor/before":"^1.0"}}',
        base_path('composer.lock') => '{"packages":[{"name":"vendor/before"}]}',
    ]);

    $snapshot = ComposerStateSnapshot::capture($filesystem);

    expect($snapshot->composerPath)->toBe(base_path('composer.json'))
        ->and($snapshot->lockPath)->toBe(base_path('composer.lock'))
        ->and($snapshot->composerContents)->toBe('{"require":{"vendor/before":"^1.0"}}')
        ->and($snapshot->lockContents)->toBe('{"packages":[{"name":"vendor/before"}]}');
});

it('restores both manifests over whatever the operation left behind', function (): void {
    $filesystem = composerSnapshotFilesystem([
        base_path('composer.json') => '{"require":{"vendor/before":"^1.0"}}',
        base_path('composer.lock') => '{"packages":[{"name":"vendor/before"}]}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem);

    $filesystem->contents[base_path('composer.json')] = '{"require":{"vendor/after":"^2.0"}}';
    $filesystem->contents[base_path('composer.lock')] = '{"packages":[{"name":"vendor/after"}]}';

    $snapshot->restoreFiles();

    expect($filesystem->contents[base_path('composer.json')])->toBe('{"require":{"vendor/before":"^1.0"}}')
        ->and($filesystem->contents[base_path('composer.lock')])->toBe('{"packages":[{"name":"vendor/before"}]}');
});

it('deletes a lock file that did not exist when the snapshot was taken', function (): void {
    // The absence of a lock file is part of the state being restored. Leaving a
    // lock behind that the application never had is not a restored application.
    $filesystem = composerSnapshotFilesystem([
        base_path('composer.json') => '{"require":{}}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem);

    $filesystem->contents[base_path('composer.lock')] = '{"packages":[{"name":"vendor/written-by-composer"}]}';

    $snapshot->restoreFiles();

    expect($filesystem->exists(base_path('composer.lock')))->toBeFalse();
});

it('knows when nothing on disk has moved away from the snapshot', function (): void {
    $filesystem = composerSnapshotFilesystem([
        base_path('composer.json') => '{"require":{"vendor/before":"^1.0"}}',
        base_path('composer.lock') => '{"packages":[]}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem);

    expect($snapshot->matchesDisk())->toBeTrue();

    $filesystem->contents[base_path('composer.lock')] = '{"packages":[{"name":"vendor/after"}]}';

    expect($snapshot->matchesDisk())->toBeFalse();
});

it('rebuilds the installed packages with a scriptless composer install', function (): void {
    $filesystem = composerSnapshotFilesystem([
        base_path('composer.json') => '{"require":{}}',
        base_path('composer.lock') => '{"packages":[]}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem);

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->once()->andReturnSelf();
    $process->shouldReceive('setTimeout')->with(90)->once()->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturn(0);
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        // --no-scripts is not optional: recovery must never execute a
        // third-party package's scripts as the web or queue user.
        ->with([...capellComposerArgv(), 'install', '--no-interaction', '--no-scripts'], Mockery::type('string'))
        ->once()
        ->andReturn($process);

    $snapshot->restoreInstalledPackages($factory, 90);
});

it('hands the caller environment to the recovery subprocess', function (): void {
    // The Marketplace rollback has to reach the network through the same proxy
    // and read the same Composer cache as the install it is undoing.
    $filesystem = composerSnapshotFilesystem([
        base_path('composer.json') => '{"require":{}}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem);
    $capturedEnvironment = null;

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->once()->andReturnUsing(function (array $environment) use (&$capturedEnvironment, $process): Process {
        $capturedEnvironment = $environment;

        return $process;
    });
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturn(0);
    $process->shouldReceive('isSuccessful')->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($process);

    $snapshot->restoreInstalledPackages($factory, 90, ['COMPOSER_CACHE_DIR' => '/tmp/capell-rollback-cache']);

    expect($capturedEnvironment)->toBe(['COMPOSER_CACHE_DIR' => '/tmp/capell-rollback-cache']);
});

it('restores the manifests again and withholds composer output when recovery fails', function (): void {
    // composer install rewrites composer.lock as it goes, so a run that died
    // part-way can corrupt the very file the snapshot exists to protect.
    $filesystem = composerSnapshotFilesystem([
        base_path('composer.json') => '{"require":{"vendor/before":"^1.0"}}',
        base_path('composer.lock') => '{"packages":[{"name":"vendor/before"}]}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem);

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->andReturnSelf();
    $process->shouldReceive('setTimeout')->andReturnSelf();
    $process->shouldReceive('run')->andReturnUsing(function () use ($filesystem): int {
        $filesystem->contents[base_path('composer.lock')] = '{"half-written":true}';

        return 1;
    });
    $process->shouldReceive('isSuccessful')->andReturnFalse();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($process);

    $caught = null;

    try {
        $snapshot->restoreInstalledPackages($factory, 90);
    } catch (RuntimeException $runtimeException) {
        $caught = $runtimeException;
    }

    expect($caught?->getMessage())->toBe(ComposerStateSnapshot::UNRECOVERABLE_MESSAGE)
        ->and($caught?->getMessage())->toContain('withheld because it may contain credentials')
        ->and($filesystem->contents[base_path('composer.lock')])->toBe('{"packages":[{"name":"vendor/before"}]}');
});
