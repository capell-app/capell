<?php

declare(strict_types=1);

use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Tests\Support\Fakes\FakeProcessFactory;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->snapshotDirectory = sys_get_temp_dir() . '/capell-snapshot-' . bin2hex(random_bytes(8));
    new Filesystem()->ensureDirectoryExists($this->snapshotDirectory);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->snapshotDirectory);
});

/** @param array<string, string> $contents */
function composerSnapshotFilesystem(array $contents): Filesystem
{
    $files = new Filesystem;
    foreach ($contents as $path => $content) {
        $files->put(test()->snapshotDirectory . '/' . basename($path), $content);
    }

    return $files;
}

it('captures both manifests as they were before anything ran', function (): void {
    $filesystem = composerSnapshotFilesystem([
        $this->snapshotDirectory . '/composer.json' => '{"require":{"vendor/before":"^1.0"}}',
        $this->snapshotDirectory . '/composer.lock' => '{"packages":[{"name":"vendor/before"}]}',
    ]);

    $snapshot = ComposerStateSnapshot::capture($filesystem, $this->snapshotDirectory);

    expect($snapshot->composerPath)->toBe($this->snapshotDirectory . '/composer.json')
        ->and($snapshot->lockPath)->toBe($this->snapshotDirectory . '/composer.lock')
        ->and($snapshot->composerContents)->toBe('{"require":{"vendor/before":"^1.0"}}')
        ->and($snapshot->lockContents)->toBe('{"packages":[{"name":"vendor/before"}]}');
});

it('restores both manifests over whatever the operation left behind', function (): void {
    $filesystem = composerSnapshotFilesystem([
        $this->snapshotDirectory . '/composer.json' => '{"require":{"vendor/before":"^1.0"}}',
        $this->snapshotDirectory . '/composer.lock' => '{"packages":[{"name":"vendor/before"}]}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem, $this->snapshotDirectory);

    $filesystem->put($this->snapshotDirectory . '/composer.json', '{"require":{"vendor/after":"^2.0"}}');
    $filesystem->put($this->snapshotDirectory . '/composer.lock', '{"packages":[{"name":"vendor/after"}]}');

    $snapshot->restoreFiles();

    expect($filesystem->get($this->snapshotDirectory . '/composer.json'))->toBe('{"require":{"vendor/before":"^1.0"}}')
        ->and($filesystem->get($this->snapshotDirectory . '/composer.lock'))->toBe('{"packages":[{"name":"vendor/before"}]}');
});

it('deletes a lock file that did not exist when the snapshot was taken', function (): void {
    // The absence of a lock file is part of the state being restored. Leaving a
    // lock behind that the application never had is not a restored application.
    $filesystem = composerSnapshotFilesystem([
        $this->snapshotDirectory . '/composer.json' => '{"require":{}}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem, $this->snapshotDirectory);

    $filesystem->put($this->snapshotDirectory . '/composer.lock', '{"packages":[{"name":"vendor/written-by-composer"}]}');

    $snapshot->restoreFiles();

    expect($filesystem->exists($this->snapshotDirectory . '/composer.lock'))->toBeFalse();
});

it('knows when nothing on disk has moved away from the snapshot', function (): void {
    $filesystem = composerSnapshotFilesystem([
        $this->snapshotDirectory . '/composer.json' => '{"require":{"vendor/before":"^1.0"}}',
        $this->snapshotDirectory . '/composer.lock' => '{"packages":[]}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem, $this->snapshotDirectory);

    expect($snapshot->matchesDisk())->toBeTrue();

    $filesystem->put($this->snapshotDirectory . '/composer.lock', '{"packages":[{"name":"vendor/after"}]}');

    expect($snapshot->matchesDisk())->toBeFalse();
});

it('rebuilds the installed packages with a scriptless composer install', function (): void {
    $filesystem = composerSnapshotFilesystem([
        $this->snapshotDirectory . '/composer.json' => '{"require":{}}',
        $this->snapshotDirectory . '/composer.lock' => '{"packages":[]}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem, $this->snapshotDirectory);

    $factory = new FakeProcessFactory;
    $snapshot->restoreInstalledPackages($factory, 90);

    // Recovery must not execute third-party scripts under the web or queue user.
    expect($factory->commands()[0])->toContain('install', '--no-scripts')
        ->and($factory->processes[0]->getWorkingDirectory())->toBe($this->snapshotDirectory)
        ->and($factory->processes[0]->getTimeout())->toBe(90.0)
        ->and($factory->processes[0]->isSuccessful())->toBeTrue();
});

it('hands the caller environment to the recovery subprocess', function (): void {
    // The Marketplace rollback has to reach the network through the same proxy
    // and read the same Composer cache as the install it is undoing.
    $filesystem = composerSnapshotFilesystem([
        $this->snapshotDirectory . '/composer.json' => '{"require":{}}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem, $this->snapshotDirectory);
    $factory = new FakeProcessFactory;
    $snapshot->restoreInstalledPackages($factory, 90, ['COMPOSER_CACHE_DIR' => '/tmp/capell-rollback-cache']);

    expect($factory->processes[0]->getEnv())->toBe(['COMPOSER_CACHE_DIR' => '/tmp/capell-rollback-cache']);
});

it('restores the manifests again and withholds composer output when recovery fails', function (): void {
    // composer install rewrites composer.lock as it goes, so a run that died
    // part-way can corrupt the very file the snapshot exists to protect.
    $filesystem = composerSnapshotFilesystem([
        $this->snapshotDirectory . '/composer.json' => '{"require":{"vendor/before":"^1.0"}}',
        $this->snapshotDirectory . '/composer.lock' => '{"packages":[{"name":"vendor/before"}]}',
    ]);
    $snapshot = ComposerStateSnapshot::capture($filesystem, $this->snapshotDirectory);

    $path = $this->snapshotDirectory . '/composer.lock';
    $factory = new FakeProcessFactory()->push(exitCode: 1, errorOutput: 'password=PRIVATE_RECOVERY_VALUE', onRun: function () use ($filesystem, $path): void {
        $filesystem->put($path, '{"half-written":true}');
    });

    $caught = null;

    try {
        $snapshot->restoreInstalledPackages($factory, 90);
    } catch (RuntimeException $runtimeException) {
        $caught = $runtimeException;
    }

    expect($caught?->getMessage())->toBe(ComposerStateSnapshot::UNRECOVERABLE_MESSAGE)
        ->and($caught?->getMessage())->toContain('withheld because it may contain credentials')
        ->not->toContain('PRIVATE_RECOVERY_VALUE')
        ->and($filesystem->get($this->snapshotDirectory . '/composer.lock'))->toBe('{"packages":[{"name":"vendor/before"}]}');
});
