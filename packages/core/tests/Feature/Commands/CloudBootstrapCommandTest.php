<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\CloudBootstrapCommand;
use Capell\Core\Models\Site;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command;

afterEach(function (): void {
    foreach ([
        'CAPELL_INSTALL_MODE',
        'CAPELL_CLOUD_REGISTRATION_URL',
        'CAPELL_REGISTRATION_TOKEN',
        'CAPELL_SITE_URL',
        'CAPELL_INSTALL_PACKAGES',
        'CAPELL_INSTALL_THEME',
        'CAPELL_ADMIN_NAME',
        'CAPELL_ADMIN_EMAIL',
    ] as $key) {
        putenv($key);
        unset($_SERVER[$key], $GLOBALS['_ENV'][$key]);

        if ($configKey = cloudBootstrapConfigKey($key)) {
            config()->set($configKey);
        }
    }
});

it('passes a securely created temporary site spec to install and removes it after failure', function (): void {
    $specPath = null;
    $specPermissions = null;
    config()->set('capell.cloud.install_packages', '');
    config()->set('capell.cloud.install_theme', 'default');
    config()->set('capell.cloud.admin_user.email', 'admin@example.com');

    $command = Mockery::mock(CloudBootstrapCommand::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $command->shouldReceive('call')->once()->with('capell:install', Mockery::on(function (array $arguments) use (&$specPath, &$specPermissions): bool {
        $specPath = $arguments['--spec'] ?? null;
        $specPermissions = is_string($specPath) ? fileperms($specPath) : false;

        return is_string($specPath) && is_file($specPath) && json_decode((string) file_get_contents($specPath), true) === ['site' => ['name' => 'Acme']];
    }))->andReturn(Command::FAILURE);

    $method = new ReflectionMethod(CloudBootstrapCommand::class, 'installCapell');

    expect(fn (): mixed => $method->invoke($command, 'https://acme.test', [
        'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret', '_site_spec' => ['site' => ['name' => 'Acme']],
    ]))->toThrow(RuntimeException::class)
        ->and($specPath)->toBeString()
        ->and($specPermissions & 0777)->toBe(0600)
        ->and(is_file((string) $specPath))->toBeFalse();
});

it('fails clearly when cloud registration configuration is missing', function (): void {
    cloudBootstrapEnv('CAPELL_INSTALL_MODE', 'cloud');

    artisanCommand('capell:cloud-bootstrap')
        ->expectsOutputToContain('CAPELL_CLOUD_REGISTRATION_URL is required')
        ->expectsOutputToContain('CAPELL_REGISTRATION_TOKEN is required')
        ->assertExitCode(Command::FAILURE);
});

it('registers an installed cloud site without rerunning the installer', function (): void {
    cloudBootstrapEnv('CAPELL_INSTALL_MODE', 'cloud');
    cloudBootstrapEnv('CAPELL_CLOUD_REGISTRATION_URL', 'https://capell.app/api/v1/cloud-instances/1/register');
    cloudBootstrapEnv('CAPELL_REGISTRATION_TOKEN', str_repeat('a', 64));
    cloudBootstrapEnv('CAPELL_SITE_URL', 'https://acme.laravel.cloud');
    cloudBootstrapEnv('CAPELL_INSTALL_PACKAGES', 'capell-app/admin,capell-app/frontend');
    cloudBootstrapEnv('CAPELL_INSTALL_THEME', 'foundation');

    Site::factory()->create();

    Http::fake([
        'https://capell.app/api/v1/cloud-instances/1/register' => Http::response([
            'data' => [
                'cloud_instance_id' => 1,
                'instance_id' => '5f36c05c-29f7-5f0d-90d0-6994540bdf4c',
            ],
        ], 200),
    ]);

    artisanCommand('capell:cloud-bootstrap')
        ->expectsOutputToContain('Capell Cloud bootstrap complete.')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode((string) $request->body(), true, 512, JSON_THROW_ON_ERROR);

        return $request->method() === 'POST'
            && $request->url() === 'https://capell.app/api/v1/cloud-instances/1/register'
            && $payload['registration_token'] === str_repeat('a', 64)
            && $payload['app_url'] === 'https://acme.laravel.cloud'
            && $payload['webhook_url'] === 'https://acme.laravel.cloud/capell/marketplace/webhook'
            && is_string($payload['instance_id'] ?? null)
            && $payload['health']['installed'] === true
            && $payload['health']['site_count'] === 1
            && $payload['health']['app_url'] === 'https://acme.laravel.cloud'
            && array_key_exists('capell_version', $payload['health'])
            && $payload['health']['install_theme'] === 'default'
            && $payload['health']['install_package_count'] === 2
            && ($payload['bootstrap_only'] ?? null) === null;
    });
});

it('uses cached cloud configuration without reading runtime environment values', function (): void {
    config()->set('capell.cloud.install_mode', 'cloud');
    config()->set('capell.cloud.registration_url', 'https://capell.app/api/v1/cloud-instances/1/register');
    config()->set('capell.cloud.registration_token', str_repeat('b', 64));
    config()->set('capell.cloud.site_url', 'https://cached.laravel.cloud');
    config()->set('capell.cloud.install_packages', 'capell-app/frontend');

    Site::factory()->create();

    Http::fake([
        'https://capell.app/api/v1/cloud-instances/1/register' => Http::response([
            'data' => [
                'cloud_instance_id' => 1,
                'instance_id' => '6f36c05c-29f7-5f0d-90d0-6994540bdf4c',
            ],
        ], 200),
    ]);

    artisanCommand('capell:cloud-bootstrap')
        ->expectsOutputToContain('Capell Cloud bootstrap complete.')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode((string) $request->body(), true, 512, JSON_THROW_ON_ERROR);

        return $request->method() === 'POST'
            && $request->url() === 'https://capell.app/api/v1/cloud-instances/1/register'
            && $payload['registration_token'] === str_repeat('b', 64)
            && $payload['app_url'] === 'https://cached.laravel.cloud'
            && $payload['health']['install_package_count'] === 1;
    });
});

it('fails installed cloud registration when capell rejects the phone home request', function (): void {
    cloudBootstrapEnv('CAPELL_INSTALL_MODE', 'cloud');
    cloudBootstrapEnv('CAPELL_CLOUD_REGISTRATION_URL', 'https://capell.app/api/v1/cloud-instances/1/register');
    cloudBootstrapEnv('CAPELL_REGISTRATION_TOKEN', str_repeat('a', 64));
    cloudBootstrapEnv('CAPELL_SITE_URL', 'https://acme.laravel.cloud');

    Site::factory()->create();

    Http::fake([
        'https://capell.app/api/v1/cloud-instances/1/register' => Http::response([
            'message' => 'The registration token is invalid.',
        ], 422),
    ]);

    artisanCommand('capell:cloud-bootstrap')
        ->expectsOutputToContain('Capell Cloud bootstrap failed while contacting Capell.')
        ->assertExitCode(Command::FAILURE);
});

it('defers install when site URL is not yet assigned and instance is not installed', function (): void {
    cloudBootstrapEnv('CAPELL_INSTALL_MODE', 'cloud');
    cloudBootstrapEnv('CAPELL_CLOUD_REGISTRATION_URL', 'https://capell.app/api/v1/cloud-instances/1/register');
    cloudBootstrapEnv('CAPELL_REGISTRATION_TOKEN', str_repeat('a', 64));

    Http::fake();

    artisanCommand('capell:cloud-bootstrap')
        ->expectsOutputToContain('Capell Cloud site URL not assigned yet; install deferred until CAPELL_SITE_URL is set.')
        ->assertExitCode(Command::SUCCESS);

    Http::assertNothingSent();
});

it('skips registration when installed but site URL is empty', function (): void {
    cloudBootstrapEnv('CAPELL_INSTALL_MODE', 'cloud');
    cloudBootstrapEnv('CAPELL_CLOUD_REGISTRATION_URL', 'https://capell.app/api/v1/cloud-instances/1/register');
    cloudBootstrapEnv('CAPELL_REGISTRATION_TOKEN', str_repeat('a', 64));

    Site::factory()->create();

    Http::fake();

    artisanCommand('capell:cloud-bootstrap')
        ->assertExitCode(Command::SUCCESS);

    Http::assertNothingSent();
});

it('never uses config app.url as the site URL', function (): void {
    cloudBootstrapEnv('CAPELL_INSTALL_MODE', 'cloud');
    cloudBootstrapEnv('CAPELL_CLOUD_REGISTRATION_URL', 'https://capell.app/api/v1/cloud-instances/1/register');
    cloudBootstrapEnv('CAPELL_REGISTRATION_TOKEN', str_repeat('a', 64));

    config(['app.url' => 'https://control-plane.capell.app']);

    Site::factory()->create();

    Http::fake();

    artisanCommand('capell:cloud-bootstrap')
        ->assertExitCode(Command::SUCCESS);

    Http::assertNothingSent();
});

it('fails first install when bootstrap credentials are unavailable', function (): void {
    cloudBootstrapEnv('CAPELL_INSTALL_MODE', 'cloud');
    cloudBootstrapEnv('CAPELL_CLOUD_REGISTRATION_URL', 'https://capell.app/api/v1/cloud-instances/1/register');
    cloudBootstrapEnv('CAPELL_REGISTRATION_TOKEN', str_repeat('a', 64));
    cloudBootstrapEnv('CAPELL_SITE_URL', 'https://acme.laravel.cloud');

    Http::fake([
        'https://capell.app/api/v1/cloud-instances/1/register' => Http::response([
            'data' => [
                'admin_bootstrap' => null,
            ],
        ], 200),
    ]);

    artisanCommand('capell:cloud-bootstrap')
        ->expectsOutputToContain('Capell Cloud bootstrap credentials were not available.')
        ->assertExitCode(Command::FAILURE);

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode((string) $request->body(), true, 512, JSON_THROW_ON_ERROR);

        return $request->method() === 'POST'
            && $request->url() === 'https://capell.app/api/v1/cloud-instances/1/register'
            && $payload['bootstrap_only'] === true;
    });
});

function cloudBootstrapEnv(string $key, string $value): void
{
    putenv($key . '=' . $value);
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;

    if ($configKey = cloudBootstrapConfigKey($key)) {
        config()->set($configKey, $value);
    }
}

function cloudBootstrapConfigKey(string $environmentKey): ?string
{
    return match ($environmentKey) {
        'CAPELL_INSTALL_MODE' => 'capell.cloud.install_mode',
        'CAPELL_CLOUD_REGISTRATION_URL' => 'capell.cloud.registration_url',
        'CAPELL_REGISTRATION_TOKEN' => 'capell.cloud.registration_token',
        'CAPELL_SITE_URL' => 'capell.cloud.site_url',
        'CAPELL_INSTALL_PACKAGES' => 'capell.cloud.install_packages',
        'CAPELL_INSTALL_THEME' => 'capell.cloud.install_theme',
        'CAPELL_ADMIN_NAME' => 'capell.cloud.admin_user.name',
        'CAPELL_ADMIN_EMAIL' => 'capell.cloud.admin_user.email',
        default => null,
    };
}
