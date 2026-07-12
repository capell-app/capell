<?php

declare(strict_types=1);

use Capell\Core\Actions\Marketplace\BuildExtensionHealthReportAction;
use Capell\Core\Actions\Marketplace\RegisterMarketplaceInstallAction;
use Capell\Core\Actions\Marketplace\SendExtensionHealthReportAction;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Models\ExtensionHealthAlert;
use Capell\Core\Models\MarketplaceInstall;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Support\Facades\Http;

it('builds extension health reports from installed extension runtime state', function (): void {
    config([
        'app.url' => 'https://example.test',
        'app.env' => 'production',
        'capell.version' => '1.x-dev',
    ]);

    CapellExtension::query()->create([
        'composer_name' => 'capell-app/blog',
        'name' => 'Blog',
        'version' => '1.2.3',
        'source' => 'marketplace',
        'status' => ExtensionStatusEnum::Enabled,
        'is_paid_marketplace_extension' => true,
        'marketplace_runtime_status' => 'allowed',
        'marketplace_runtime_allowed' => true,
        'marketplace_runtime_reason' => null,
    ]);

    CapellExtension::query()->create([
        'composer_name' => 'capell-app/forms',
        'name' => 'Forms',
        'version' => '2.0.0',
        'source' => 'local',
        'status' => ExtensionStatusEnum::Disabled,
        'is_paid_marketplace_extension' => false,
        'marketplace_runtime_status' => 'blocked',
        'marketplace_runtime_allowed' => false,
        'marketplace_runtime_reason' => 'license_expired',
    ]);

    $report = BuildExtensionHealthReportAction::run(
        source: 'heartbeat',
        instanceId: 'install-123',
        webhookUrl: 'https://hooks.example.test/heartbeat',
    );

    expect($report)->toMatchArray([
        'install_id' => 'install-123',
        'app_url' => 'https://example.test',
        'capell_version' => '1.x-dev',
        'environment' => 'production',
        'metadata' => [
            'source' => 'heartbeat',
            'webhook_url' => 'https://hooks.example.test/heartbeat',
        ],
    ])
        ->and($report['extensions'])->toHaveCount(2)
        ->and($report['extensions'][0])->toMatchArray([
            'composer_name' => 'capell-app/blog',
            'name' => 'Blog',
            'version' => '1.2.3',
            'source' => 'marketplace',
            'status' => 'enabled',
            'is_paid_marketplace_extension' => true,
            'marketplace_runtime_status' => 'allowed',
            'marketplace_runtime_allowed' => true,
        ])
        ->and($report['extensions'][1])->toMatchArray([
            'composer_name' => 'capell-app/forms',
            'status' => 'disabled',
            'marketplace_runtime_reason' => 'license_expired',
        ]);
});

it('omits extension state when runtime schema checks are unavailable', function (): void {
    app()->instance(RuntimeSchemaState::class, new class
    {
        public function hasTable(string $table): bool
        {
            throw new RuntimeException('schema connection unavailable');
        }
    });

    $report = BuildExtensionHealthReportAction::run(source: 'heartbeat');

    expect($report['extensions'])->toBe([]);
});

it('creates a separate marketplace install when an explicit new install id is supplied', function (): void {
    config([
        'app.url' => 'https://example.test',
        'app.env' => 'production',
    ]);

    $firstInstall = RegisterMarketplaceInstallAction::run('install-first');
    $secondInstall = RegisterMarketplaceInstallAction::run('install-second');

    expect(MarketplaceInstall::query()->count())->toBe(2)
        ->and($firstInstall->install_id)->toBe('install-first')
        ->and($secondInstall->install_id)->toBe('install-second');
});

it('ignores heartbeat alerts signed only by a response supplied secret', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test',
    ]);

    $secret = 'response-controlled-secret';
    $alert = signedCoreMarketplaceHealthAlert([
        'alert_id' => 'alert_self_signed',
        'severity' => 'critical',
        'category' => 'security',
        'title' => 'Self signed alert',
        'message' => 'This alert should not be trusted.',
    ], $secret);

    Http::fake([
        'https://marketplace.test/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => 'install-heartbeat',
                'signing_secret' => $secret,
                'alerts' => [$alert],
            ],
        ]),
    ]);

    SendExtensionHealthReportAction::run(source: 'heartbeat', instanceId: 'install-heartbeat');

    expect(ExtensionHealthAlert::query()->where('alert_id', 'alert_self_signed')->exists())->toBeFalse();
});

it('sends signed heartbeats records trusted alerts and marks the install as reported', function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/',
    ]);

    $secret = 'shared-heartbeat-secret';
    $alert = signedCoreMarketplaceHealthAlert([
        'alert_id' => 'trusted-runtime-alert',
        'severity' => 'warning',
        'category' => 'compatibility',
        'title' => 'Runtime notice',
        'message' => 'The extension requires attention.',
    ], $secret);

    Http::fake([
        'https://marketplace.test/instances/heartbeat' => Http::response([
            'data' => [
                'instance_id' => 'install-signed-heartbeat',
                'alerts' => [$alert, 'invalid-alert'],
            ],
        ]),
    ]);

    $response = SendExtensionHealthReportAction::run(
        source: 'heartbeat',
        instanceId: 'install-signed-heartbeat',
        signingSecret: $secret,
        webhookUrl: 'https://hooks.example.test/heartbeat',
    );

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/instances/heartbeat'
        && $request['signature_algorithm'] === 'hmac-sha256'
        && is_string($request['signature'])
        && $request['metadata']['webhook_url'] === 'https://hooks.example.test/heartbeat');

    $install = MarketplaceInstall::query()->where('install_id', 'install-signed-heartbeat')->sole();
    $storedAlert = ExtensionHealthAlert::query()->where('alert_id', 'trusted-runtime-alert')->sole();

    expect($response['instance_id'])->toBe('install-signed-heartbeat')
        ->and($install->last_reported_at)->not->toBeNull()
        ->and($storedAlert->title)->toBe('Runtime notice')
        ->and($storedAlert->source)->toBe('heartbeat');
});

it('fails loudly for invalid marketplace heartbeat responses', function (): void {
    config([
        'capell-marketplace.marketplace.base_url' => null,
    ]);

    expect(fn (): array => SendExtensionHealthReportAction::run(source: 'heartbeat'))
        ->toThrow(RuntimeException::class, 'The marketplace URL is not configured.');

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test']);

    Http::fake([
        'https://marketplace.test/instances/heartbeat' => Http::response(['message' => 'Nope'], 503),
    ]);

    expect(fn (): array => SendExtensionHealthReportAction::run(source: 'heartbeat'))
        ->toThrow(RuntimeException::class, 'HTTP status 503');

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace-json.test']);

    Http::fake([
        'https://marketplace-json.test/instances/heartbeat' => Http::response(['data' => 'not-array']),
    ]);

    expect(fn (): array => SendExtensionHealthReportAction::run(source: 'heartbeat'))
        ->toThrow(RuntimeException::class, 'expected data payload');
});

/**
 * @param  array<string, mixed>  $alert
 * @return array<string, mixed>
 */
function signedCoreMarketplaceHealthAlert(array $alert, string $secret): array
{
    $signaturePayload = $alert;
    ksort($signaturePayload);

    return [
        ...$alert,
        'signature' => 'sha256=' . hash_hmac(
            'sha256',
            json_encode($signaturePayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $secret,
        ),
    ];
}
