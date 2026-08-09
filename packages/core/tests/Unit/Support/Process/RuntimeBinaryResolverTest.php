<?php

declare(strict_types=1);

use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function (): void {
    config()->set('capell.process.php_binary');
    config()->set('capell.process.composer_binary');
    config()->set('capell-installer.php_binary');
    config()->set('capell-installer.composer_binary');
    putenv(RuntimeBinaryResolver::PHP_ENVIRONMENT_KEY);
    putenv(RuntimeBinaryResolver::COMPOSER_ENVIRONMENT_KEY);
});

afterEach(function (): void {
    putenv(RuntimeBinaryResolver::PHP_ENVIRONMENT_KEY);
    putenv(RuntimeBinaryResolver::COMPOSER_ENVIRONMENT_KEY);

    foreach (runtimeBinaryResolverFixturePaths() as $path) {
        @unlink($path);
    }
});

/**
 * A real, executable stand-in. Resolution only ever inspects the filesystem, so
 * an executable shell script is indistinguishable from a hand-installed binary.
 */
function runtimeBinaryResolverFixture(string $filename, bool $executable = true): string
{
    $path = sys_get_temp_dir() . '/capell-runtime-binary-' . bin2hex(random_bytes(6)) . '-' . $filename;
    file_put_contents($path, $executable ? "#!/bin/sh\nexit 0\n" : '<?php');

    if ($executable) {
        chmod($path, 0755);
    }

    runtimeBinaryResolverFixturePaths($path);

    return $path;
}

/**
 * @return list<string>
 */
function runtimeBinaryResolverFixturePaths(?string $register = null): array
{
    static $paths = [];

    if ($register !== null) {
        $paths[] = $register;
    }

    return $paths;
}

function runtimeBinaryResolverFinderReturningNothing(): ExecutableFinder
{
    return new class extends ExecutableFinder
    {
        public function find(string $name, ?string $default = null, array $extraDirs = []): ?string
        {
            return null;
        }
    };
}

it('prefers the capell process config over every other tier', function (): void {
    $configuredPhp = runtimeBinaryResolverFixture('php');
    $legacyPhp = runtimeBinaryResolverFixture('php');

    config()->set('capell.process.php_binary', $configuredPhp);
    config()->set('capell-installer.php_binary', $legacyPhp);
    putenv(RuntimeBinaryResolver::PHP_ENVIRONMENT_KEY . '=' . $legacyPhp);

    expect(new RuntimeBinaryResolver()->php())->toBe([$configuredPhp]);
});

it('falls back to the environment variable when no capell process config is set', function (): void {
    $environmentPhp = runtimeBinaryResolverFixture('php');
    $legacyPhp = runtimeBinaryResolverFixture('php');

    putenv(RuntimeBinaryResolver::PHP_ENVIRONMENT_KEY . '=' . $environmentPhp);
    config()->set('capell-installer.php_binary', $legacyPhp);

    expect(new RuntimeBinaryResolver()->php())->toBe([$environmentPhp]);
});

it('still honours the legacy installer php binary key', function (): void {
    $legacyPhp = runtimeBinaryResolverFixture('php');

    config()->set('capell-installer.php_binary', $legacyPhp);

    expect(new RuntimeBinaryResolver()->php())->toBe([$legacyPhp]);
});

it('still honours the legacy installer composer binary key', function (): void {
    $legacyComposer = runtimeBinaryResolverFixture('composer');

    config()->set('capell-installer.composer_binary', $legacyComposer);

    expect(new RuntimeBinaryResolver()->composer())->toBe([$legacyComposer]);
});

it('falls back to PHP_BINARY when nothing is configured and no php is on PATH', function (): void {
    // The single most valuable fallback: a host whose web user has no PATH still
    // knows the interpreter it is currently running under.
    $resolver = new RuntimeBinaryResolver(runtimeBinaryResolverFinderReturningNothing());

    expect($resolver->php())->toBe([PHP_BINARY]);
});

it('never resolves php-fpm as the cli php binary', function (): void {
    $phpFpm = runtimeBinaryResolverFixture('php-fpm');

    config()->set('capell.process.php_binary', $phpFpm);

    // php-fpm cannot run a script, so the resolver must keep looking rather than
    // hand a caller a binary that fails at the first subprocess. With nothing
    // else configured, the next tier that answers is PHP_BINARY — the process
    // running this test, which is a CLI binary by definition.
    $resolver = new RuntimeBinaryResolver;

    expect($resolver->php())->toBe([PHP_BINARY])
        ->and($resolver->misconfiguredPhpBinary())->toBe([
            'binary' => $phpFpm,
            'reason' => RuntimeBinaryResolver::REASON_NOT_CLI,
        ]);
});

it('invokes a composer phar through the resolved php binary', function (): void {
    $php = runtimeBinaryResolverFixture('php');
    $phar = runtimeBinaryResolverFixture('composer.phar', executable: false);

    config()->set('capell.process.php_binary', $php);
    config()->set('capell.process.composer_binary', $phar);

    expect(new RuntimeBinaryResolver()->composer())->toBe([$php, $phar]);
});

it('leaves a wrapper script alone rather than prefixing php', function (): void {
    // A shell wrapper or a Windows .bat already knows how to start Composer.
    // Prefixing php would hand PHP a file it cannot parse.
    $wrapper = runtimeBinaryResolverFixture('composer.bat');

    config()->set('capell.process.composer_binary', $wrapper);

    expect(new RuntimeBinaryResolver()->composer())->toBe([$wrapper]);
});

it('reports an unresolvable composer instead of throwing when asked for a nullable answer', function (): void {
    $resolver = new RuntimeBinaryResolver(runtimeBinaryResolverFinderReturningNothing());

    expect($resolver->composerOrNull())->toBeNull();
});

it('throws a directed message when composer cannot be resolved', function (): void {
    $resolver = new RuntimeBinaryResolver(runtimeBinaryResolverFinderReturningNothing());

    expect(fn (): array => $resolver->composer())
        ->toThrow(RuntimeException::class, RuntimeBinaryResolver::COMPOSER_CONFIG_KEY);
});

it('names a configured php binary that cannot be resolved while still resolving one', function (): void {
    config()->set('capell.process.php_binary', '/nonexistent/capell/php');

    $resolver = new RuntimeBinaryResolver;

    expect($resolver->misconfiguredPhpBinary())
        ->toBe(['binary' => '/nonexistent/capell/php', 'reason' => RuntimeBinaryResolver::REASON_UNRESOLVABLE])
        ->and($resolver->phpOrNull())->not->toBeNull();
});

it('names a configured php binary that points at php-fpm', function (): void {
    $phpFpm = runtimeBinaryResolverFixture('php-fpm');

    config()->set('capell.process.php_binary', $phpFpm);

    expect(new RuntimeBinaryResolver()->misconfiguredPhpBinary())
        ->toBe(['binary' => $phpFpm, 'reason' => RuntimeBinaryResolver::REASON_NOT_CLI]);
});

it('reports no misconfiguration when the legacy installer keys resolve', function (): void {
    config()->set('capell-installer.php_binary', runtimeBinaryResolverFixture('php'));
    config()->set('capell-installer.composer_binary', runtimeBinaryResolverFixture('composer'));

    $resolver = new RuntimeBinaryResolver;

    expect($resolver->misconfiguredPhpBinary())->toBeNull()
        ->and($resolver->misconfiguredComposerBinary())->toBeNull();
});

it('falls back past a configured path that does not exist, and says so', function (): void {
    $pathDirectory = sys_get_temp_dir() . '/capell-runtime-binary-path-' . bin2hex(random_bytes(6));
    mkdir($pathDirectory, 0755, true);
    $onPath = $pathDirectory . '/composer';
    file_put_contents($onPath, "#!/bin/sh\nexit 0\n");
    chmod($onPath, 0755);

    $previousPath = getenv('PATH');
    putenv('PATH=' . $pathDirectory);

    config()->set('capell.process.composer_binary', '/nonexistent/capell/composer');

    try {
        $resolver = new RuntimeBinaryResolver;

        // Falling back is deliberate — an install that can complete should
        // complete — but the operator still has to learn their path is wrong.
        expect($resolver->composerOrNull())->toBe([$onPath])
            ->and($resolver->misconfiguredComposerBinary())->toBe([
                'binary' => '/nonexistent/capell/composer',
                'reason' => RuntimeBinaryResolver::REASON_UNRESOLVABLE,
            ]);
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        @unlink($onPath);
        @rmdir($pathDirectory);
    }
});
