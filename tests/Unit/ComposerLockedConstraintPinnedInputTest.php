<?php

declare(strict_types=1);

use Capell\Tests\Support\ComposerLockedConstraintGuard;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->repository = sys_get_temp_dir() . '/capell-lock-pin-' . bin2hex(random_bytes(8));
    mkdir($this->repository);
    $this->git = function (array $arguments): string {
        $process = new Process(['git', '-C', $this->repository, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    };
    ($this->git)(['init', '--quiet']);
    $this->writeLock = function (string $version): string {
        file_put_contents($this->repository . '/composer.lock', json_encode([
            'packages' => [['name' => 'example/package', 'version' => $version]],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        ($this->git)(['add', 'composer.lock']);
        ($this->git)(['-c', 'user.name=Lock fixture', '-c', 'user.email=lock-fixture@example.invalid', 'commit', '--quiet', '-m', $version]);

        return ($this->git)(['rev-parse', 'HEAD']);
    };
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->repository);
});

it('reads the immutable lock even after the fetched ref and working bytes change', function (): void {
    $original = ($this->writeLock)('1.0.1');
    $newer = ($this->writeLock)('1.0.2');
    ($this->git)(['update-ref', 'refs/remotes/origin/main', $newer]);
    file_put_contents($this->repository . '/composer.lock', 'foreign working bytes');

    expect(ComposerLockedConstraintGuard::committedVersions($this->repository, $original))
        ->toBe(['example/package' => '1.0.1']);
});

it('refuses a mutable ref in place of a source commit', function (): void {
    $commit = ($this->writeLock)('1.0.1');
    ($this->git)(['update-ref', 'refs/remotes/origin/main', $commit]);

    expect(fn (): array => ComposerLockedConstraintGuard::committedVersions($this->repository, 'origin/main'))
        ->toThrow(RuntimeException::class, 'immutable 40-character commit');
});

it('refuses absent or mutable release source pins', function (string $pin): void {
    $previousPin = getenv('CAPELL_RELEASE_FIXTURE_COMMIT');
    $previousMode = getenv('CAPELL_RELEASE_ENVIRONMENT');
    putenv('CAPELL_RELEASE_FIXTURE_COMMIT=' . $pin);
    putenv('CAPELL_RELEASE_ENVIRONMENT=1');

    try {
        expect(fn (): string => ComposerLockedConstraintGuard::sourceCommit('fixture'))
            ->toThrow(RuntimeException::class, 'immutable 40-character commit');
    } finally {
        putenv($previousPin === false ? 'CAPELL_RELEASE_FIXTURE_COMMIT' : 'CAPELL_RELEASE_FIXTURE_COMMIT=' . $previousPin);
        putenv($previousMode === false ? 'CAPELL_RELEASE_ENVIRONMENT' : 'CAPELL_RELEASE_ENVIRONMENT=' . $previousMode);
    }
})->with(['', 'origin/main', str_repeat('a', 39)]);
