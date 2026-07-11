<?php

declare(strict_types=1);

use Capell\Core\Actions\RequirePackageAction;

beforeEach(function (): void {
    RequirePackageAction::resetProcessFactory();
    putenv('COMPOSER_AUTH');
    putenv('GITHUB_TOKEN');
    putenv('GITLAB_TOKEN');
    putenv('BITBUCKET_TOKEN');
});

afterEach(function (): void {
    RequirePackageAction::resetProcessFactory();
    putenv('COMPOSER_AUTH');
    putenv('GITHUB_TOKEN');
    putenv('GITLAB_TOKEN');
    putenv('BITBUCKET_TOKEN');
});

it('requires a package', function (): void {
    $mockProcess = new class
    {
        public function setTimeout(?float $timeout): self
        {
            return $this;
        }

        public function run(): int
        {
            return 0;
        }

        public function isSuccessful(): bool
        {
            return true;
        }

        public function getOutput(): string
        {
            return json_encode(['status' => 'installed'], JSON_THROW_ON_ERROR);
        }

        public function getErrorOutput(): string
        {
            return '';
        }
    };
    RequirePackageAction::setProcessFactory(fn (array $args, string $cwd, ?array $env): object => $mockProcess);

    $result = RequirePackageAction::run('vendor/package', '');

    expect($result)
        ->toBeArray()
        ->and($result['status'] ?? null)->toBe('installed');
});

it('handles require failures', function (): void {
    $mockProcess = new class
    {
        public function setTimeout(?float $timeout): self
        {
            return $this;
        }

        public function run(): int
        {
            return 1;
        }

        public function isSuccessful(): bool
        {
            return false;
        }

        public function getErrorOutput(): string
        {
            return 'Composer error';
        }

        public function getOutput(): string
        {
            return '';
        }
    };
    RequirePackageAction::setProcessFactory(fn (array $args, string $cwd, ?array $env): object => $mockProcess);

    expect(fn (): mixed => RequirePackageAction::run('invalid/package', ''))
        ->toThrow(RuntimeException::class, 'Failed to install package');
});

it('rejects wildcard composer constraints outside local environments before shelling out', function (): void {
    config(['app.env' => 'production']);

    $called = false;
    RequirePackageAction::setProcessFactory(function () use (&$called): never {
        $called = true;

        throw new RuntimeException('Composer should not run.');
    });

    expect(fn (): mixed => RequirePackageAction::run('vendor/private-package:*'))
        ->toThrow(RuntimeException::class, "Wildcard version constraint '*' for package 'vendor/private-package' is not allowed in 'production' environment.")
        ->and($called)->toBeFalse();
});

it('builds composer auth for explicit private package providers', function (string $provider, ?string $domain, string $expectedAuthKey, string $expectedHost): void {
    $capturedEnvironment = null;
    $mockProcess = requirePackageProcess(successful: true, output: 'Package installed');

    RequirePackageAction::setProcessFactory(function (array $args, string $cwd, ?array $env) use (&$capturedEnvironment, $mockProcess): object {
        expect($args)->toBe(['composer', 'require', 'vendor/private-package:^1.2'])
            ->and($cwd)->toBe(base_path());

        $capturedEnvironment = $env;

        return $mockProcess;
    });

    $result = RequirePackageAction::run('vendor/private-package:^1.2', 'secret-token', $provider, $domain);
    $auth = json_decode((string) ($capturedEnvironment['COMPOSER_AUTH'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($result['auth_used'])->toBeTrue()
        ->and($auth)->toHaveKey($expectedAuthKey)
        ->and($auth[$expectedAuthKey])->toHaveKey($expectedHost);
})->with([
    'github default host' => ['github', null, 'github-oauth', 'github.com'],
    'gitlab custom host' => ['gitlab', 'gitlab.company.test', 'gitlab-token', 'gitlab.company.test'],
    'bitbucket default host' => ['bitbucket', null, 'bitbucket-oauth', 'bitbucket.org'],
    'custom http basic host' => ['custom-http-basic', 'packages.example.test', 'http-basic', 'packages.example.test'],
]);

it('uses available token environment variables without overwriting an existing composer auth payload', function (): void {
    putenv('GITHUB_TOKEN=github-token');
    putenv('GITLAB_TOKEN=gitlab-token');
    putenv('BITBUCKET_TOKEN=bitbucket-token');

    $capturedEnvironment = null;
    RequirePackageAction::setProcessFactory(function (array $args, string $cwd, ?array $env) use (&$capturedEnvironment): object {
        $capturedEnvironment = $env;

        return requirePackageProcess(successful: true, output: 'Package installed');
    });

    RequirePackageAction::run('vendor/env-package:^1.0');

    $auth = json_decode((string) ($capturedEnvironment['COMPOSER_AUTH'] ?? ''), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($auth['github-oauth']['github.com'])->toBe('github-token')
        ->and($auth['gitlab-token']['gitlab.com'])->toBe('gitlab-token')
        ->and($auth['bitbucket-oauth']['bitbucket.org'])->toBe('bitbucket-token');

    putenv('COMPOSER_AUTH={"github-oauth":{"github.com":"existing-token"}}');
    $capturedEnvironment = 'not-called';

    RequirePackageAction::run('vendor/existing-auth-package:^1.0');

    expect($capturedEnvironment)->toBeNull();
});

it('reports authentication failures with operator guidance', function (): void {
    RequirePackageAction::setProcessFactory(fn (): object => requirePackageProcess(
        successful: false,
        output: '',
        errorOutput: 'HTTP 403 authentication failed',
    ));

    expect(fn (): mixed => RequirePackageAction::run('vendor/private-package:^1.0'))
        ->toThrow(RuntimeException::class, "Failed to install private package 'vendor/private-package:^1.0': authentication required or invalid credentials.");
});

function requirePackageProcess(bool $successful, string $output = '', string $errorOutput = ''): object
{
    return new readonly class($successful, $output, $errorOutput)
    {
        public function __construct(
            private bool $successful,
            private string $output,
            private string $errorOutput,
        ) {}

        public function setTimeout(?float $timeout): self
        {
            expect($timeout)->toEqual(300);

            return $this;
        }

        public function run(): int
        {
            return $this->successful ? 0 : 1;
        }

        public function isSuccessful(): bool
        {
            return $this->successful;
        }

        public function getOutput(): string
        {
            return $this->output;
        }

        public function getErrorOutput(): string
        {
            return $this->errorOutput;
        }
    };
}
