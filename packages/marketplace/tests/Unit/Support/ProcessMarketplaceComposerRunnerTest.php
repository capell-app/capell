<?php

declare(strict_types=1);

use Capell\Marketplace\Support\ProcessMarketplaceComposerRunner;

beforeEach(function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);
});

/**
 * @return array<string, string|false>
 */
function expectedMarketplaceComposerRunnerEnvironmentForTest(string $composerHome, string $home): array
{
    return [
        'COMPOSER_HOME' => $composerHome,
        'COMPOSER_AUTH' => false,
        'COMPOSER_TOKEN' => false,
        'GIT_ASKPASS' => false,
        'GIT_TERMINAL_PROMPT' => '0',
        'GITHUB_TOKEN' => false,
        'GITHUB_AUTH_TOKEN' => false,
        'GITLAB_TOKEN' => false,
        'HOME' => $home,
        'PACKAGIST_TOKEN' => false,
        'SSH_AUTH_SOCK' => false,
    ];
}

it('resolves php from the CLI path instead of relying on the current SAPI binary', function (): void {
    $binDirectory = sys_get_temp_dir() . '/capell-marketplace-runner-' . bin2hex(random_bytes(4));

    mkdir($binDirectory, 0755, true);

    $phpPath = $binDirectory . '/php';
    file_put_contents($phpPath, "#!/bin/sh\nexit 0\n");
    chmod($phpPath, 0755);

    $previousPath = getenv('PATH');
    putenv('PATH=' . $binDirectory . PATH_SEPARATOR . ($previousPath ?: ''));

    try {
        $method = new ReflectionMethod(ProcessMarketplaceComposerRunner::class, 'phpCliBinary');

        expect($method->invoke(new ProcessMarketplaceComposerRunner))->toBe($phpPath);
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        @unlink($phpPath);
        @rmdir($binDirectory);
    }
});

it('disables composer cache for marketplace installs', function (): void {
    $method = new ReflectionMethod(ProcessMarketplaceComposerRunner::class, 'composerRequireArguments');

    expect($method->invoke(new ProcessMarketplaceComposerRunner, 'vendor/example', '^1.2'))->toBe([
        '--no-cache',
        'require',
        '--no-interaction',
        '--prefer-dist',
        '--with-all-dependencies',
        'vendor/example:^1.2',
    ]);
});

it('fails clearly when the composer home directory cannot be created', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_composer_home_file_');

    try {
        $method = new ReflectionMethod(ProcessMarketplaceComposerRunner::class, 'ensureDirectory');

        expect(fn (): mixed => $method->invoke(new ProcessMarketplaceComposerRunner, $path))
            ->toThrow(RuntimeException::class, 'Unable to create Composer home directory: ' . $path);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('blocks composer before touching an immutable release root', function (): void {
    config()->set('capell.release_root_mode', 'immutable');

    expect(fn (): mixed => (new ProcessMarketplaceComposerRunner)->require('vendor/example', '^1.2', 5))
        ->toThrow(
            RuntimeException::class,
            'Installing a Marketplace extension with Composer is blocked because CAPELL_RELEASE_ROOT_MODE is immutable',
        );
});

it('blocks composer when server-side tooling is disabled', function (): void {
    config()->set('capell.server_side_tooling', false);

    expect(fn (): mixed => (new ProcessMarketplaceComposerRunner)->require('vendor/example', '^1.2', 5))
        ->toThrow(
            RuntimeException::class,
            'Installing a Marketplace extension with Composer is blocked because CAPELL_SERVER_SIDE_TOOLING is disabled',
        );
});

it('runs composer through the resolved php binary with an isolated composer home', function (): void {
    $binDirectory = sys_get_temp_dir() . '/capell-marketplace-runner-' . bin2hex(random_bytes(4));

    mkdir($binDirectory, 0755, true);

    $phpPath = $binDirectory . '/php';
    $composerPath = $binDirectory . '/composer';

    file_put_contents($phpPath, "#!/bin/sh\nexec \"$@\"\n");
    file_put_contents($composerPath, <<<'SH'
#!/bin/sh
echo "COMPOSER_HOME=$COMPOSER_HOME"
echo "HOME=$HOME"
printf '%s\n' "$@"
echo "composer stderr" >&2
exit 7
SH);
    chmod($phpPath, 0755);
    chmod($composerPath, 0755);

    $previousPath = getenv('PATH');
    $previousHome = getenv('HOME');
    putenv('PATH=' . $binDirectory);
    putenv('HOME');

    try {
        $result = (new ProcessMarketplaceComposerRunner)->require('vendor/example', '^1.2', 5);
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
        @unlink($phpPath);
        @unlink($composerPath);
        @rmdir($binDirectory);
    }

    expect($result->exitCode)->toBe(7)
        ->and($result->output)->toContain('COMPOSER_HOME=' . storage_path('framework/composer'))
        ->and($result->output)->toContain('HOME=' . storage_path('framework/composer'))
        ->and($result->output)->toContain('--no-cache')
        ->and($result->output)->toContain('vendor/example:^1.2')
        ->and($result->errorOutput)->toContain('composer stderr')
        ->and(is_dir(storage_path('framework/composer')))->toBeTrue();
});

it('writes marketplace composer auth to an isolated composer home and redacts output', function (): void {
    $binDirectory = sys_get_temp_dir() . '/capell-marketplace-runner-' . bin2hex(random_bytes(4));

    mkdir($binDirectory, 0755, true);

    $phpPath = $binDirectory . '/php';
    $composerPath = $binDirectory . '/composer';

    file_put_contents($phpPath, "#!/bin/sh\nexec \"$@\"\n");
    file_put_contents($composerPath, <<<'SH'
#!/bin/sh
echo "COMPOSER_HOME=$COMPOSER_HOME"
test -f "$COMPOSER_HOME/auth.json" && echo "AUTH_FILE_PRESENT"
echo "token=ghp_secret_token"
exit 7
SH);
    chmod($phpPath, 0755);
    chmod($composerPath, 0755);

    $previousPath = getenv('PATH');
    $existingAuthHomes = glob(storage_path('framework/composer/marketplace-auth-*')) ?: [];
    putenv('PATH=' . $binDirectory);

    try {
        $result = (new ProcessMarketplaceComposerRunner)->requireWithComposerAuth(
            composerName: 'vendor/example',
            versionConstraint: '^1.2',
            timeoutSeconds: 5,
            composerAuth: [
                'github-oauth' => [
                    'github.com' => 'ghp_secret_token',
                ],
            ],
        );
    } finally {
        putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
        @unlink($phpPath);
        @unlink($composerPath);
        @rmdir($binDirectory);
    }

    expect($result->exitCode)->toBe(7)
        ->and($result->output)->toContain('COMPOSER_HOME=' . storage_path('framework/composer/marketplace-auth-'))
        ->and($result->output)->toContain('AUTH_FILE_PRESENT')
        ->and($result->output)->not->toContain('ghp_secret_token')
        ->and($result->output)->toContain('[redacted]')
        ->and(array_values(array_diff(glob(storage_path('framework/composer/marketplace-auth-*')) ?: [], $existingAuthHomes)))->toBe([]);
});

it('keeps the existing home directory available for git configuration', function (): void {
    $previousHome = getenv('HOME');
    putenv('HOME=/Users/example');

    try {
        $method = new ReflectionMethod(ProcessMarketplaceComposerRunner::class, 'processEnvironment');

        expect($method->invoke(new ProcessMarketplaceComposerRunner, '/tmp/capell-composer-home'))
            ->toBe(expectedMarketplaceComposerRunnerEnvironmentForTest('/tmp/capell-composer-home', '/Users/example'));
    } finally {
        putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
    }
});

it('falls back to composer home when no home directory is available', function (): void {
    $previousHome = getenv('HOME');
    putenv('HOME');

    try {
        $method = new ReflectionMethod(ProcessMarketplaceComposerRunner::class, 'processEnvironment');

        expect($method->invoke(new ProcessMarketplaceComposerRunner, '/tmp/capell-composer-home'))
            ->toBe(expectedMarketplaceComposerRunnerEnvironmentForTest('/tmp/capell-composer-home', '/tmp/capell-composer-home'));
    } finally {
        putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
    }
});

it('removes ambient marketplace composer credentials from the child process environment', function (): void {
    $environmentKeys = [
        'COMPOSER_AUTH',
        'COMPOSER_TOKEN',
        'GIT_ASKPASS',
        'GITHUB_TOKEN',
        'GITHUB_AUTH_TOKEN',
        'GITLAB_TOKEN',
        'PACKAGIST_TOKEN',
        'SSH_AUTH_SOCK',
    ];
    $previousEnvironment = [];

    foreach ($environmentKeys as $key) {
        $previousEnvironment[$key] = getenv($key);
        putenv($key . '=ambient-secret');
    }

    try {
        $method = new ReflectionMethod(ProcessMarketplaceComposerRunner::class, 'processEnvironment');

        $environment = $method->invoke(new ProcessMarketplaceComposerRunner, '/tmp/capell-composer-home');

        expect($environment)->toMatchArray([
            'COMPOSER_AUTH' => false,
            'COMPOSER_TOKEN' => false,
            'GIT_ASKPASS' => false,
            'GIT_TERMINAL_PROMPT' => '0',
            'GITHUB_TOKEN' => false,
            'GITHUB_AUTH_TOKEN' => false,
            'GITLAB_TOKEN' => false,
            'PACKAGIST_TOKEN' => false,
            'SSH_AUTH_SOCK' => false,
        ]);
    } finally {
        foreach ($previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});
