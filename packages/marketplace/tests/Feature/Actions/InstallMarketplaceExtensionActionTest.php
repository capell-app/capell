<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\InstallMarketplaceExtensionAction;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallRequestData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Jobs\SendMarketplaceInstallTelemetryJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallIntent;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Cache::flush();
    test()->actingAsAdmin();

    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.instance.id' => 'instance-123',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
        'capell-marketplace.marketplace.cache_ttl_seconds' => 300,
        'capell-marketplace.marketplace.webhook_secret' => 'test-secret',
    ]);
});

it('orchestrates a free marketplace install with selected options and queued telemetry', function (): void {
    Queue::fake();

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite' => Http::response([
            'data' => installMarketplaceExtensionActionPayload([
                'slug' => 'seo-suite',
                'name' => 'SEO Suite',
                'composer_name' => 'capell-app/seo-suite',
                'latest_version' => '2.1.0',
                'install_options' => [
                    ['key' => 'starter_content', 'type' => 'checkbox', 'label' => 'Starter content'],
                    ['key' => 'mode', 'type' => 'radio', 'label' => 'Mode'],
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/install-intents' => Http::response(['data' => ['recorded' => true]]),
    ]);

    InstallMarketplaceExtensionAction::run(
        installMarketplaceActionRequest('seo-suite', [
            'install_options' => [
                'starter_content' => true,
                'ignored' => true,
            ],
        ]),
    );

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->composer_name)->toBe('capell-app/seo-suite')
        ->and($attempt->requested_options)->toBe(['starter_content' => true])
        ->and($attempt->telemetry_status)->toBe('pending');

    Queue::assertPushed(SendMarketplaceInstallTelemetryJob::class);
    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite/install-authorization');
});

it('records blocked marketplace eligibility before requesting authorization', function (): void {
    Http::fake([
        'https://marketplace.test/api/extensions/protected-suite' => Http::response([
            'data' => installMarketplaceExtensionActionPayload([
                'slug' => 'protected-suite',
                'name' => 'Protected Suite',
                'composer_name' => 'capell-app/protected-suite',
                'price_cents' => 4900,
                'is_paid' => true,
            ]),
        ]),
        'https://marketplace.test/api/extensions/protected-suite/install-authorization' => Http::response([
            'data' => [],
        ]),
    ]);

    InstallMarketplaceExtensionAction::run(installMarketplaceActionRequest('protected-suite'));

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('account_required');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/protected-suite/install-authorization');
});

it('records purchase-required authorization responses as blocked attempts', function (): void {
    installMarketplaceExtensionActionConnectedInstance();

    Http::fake([
        'https://marketplace.test/api/extensions/paid-suite' => Http::response([
            'data' => installMarketplaceExtensionActionPayload([
                'slug' => 'paid-suite',
                'name' => 'Paid Suite',
                'composer_name' => 'capell-app/paid-suite',
                'is_paid' => true,
                'price_cents' => 9900,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
                'purchase_url' => 'https://marketplace.test/checkout/paid-suite',
            ]),
        ]),
        'https://marketplace.test/api/extensions/paid-suite/install-authorization' => Http::response([
            'message' => 'Purchase this extension before installing.',
            'data' => [
                'purchase_url' => 'https://marketplace.test/checkout/paid-suite',
            ],
        ], 402),
    ]);

    InstallMarketplaceExtensionAction::run(
        installMarketplaceActionRequest('paid-suite', ['email' => 'owner@example.test']),
    );

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('Purchase this extension before installing.');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/paid-suite/install-authorization');
});

it('records authorization failures in the marketplace install ledger', function (): void {
    installMarketplaceExtensionActionConnectedInstance();

    Http::fake([
        'https://marketplace.test/api/extensions/broken-suite' => Http::response([
            'data' => installMarketplaceExtensionActionPayload([
                'slug' => 'broken-suite',
                'name' => 'Broken Suite',
                'composer_name' => 'capell-app/broken-suite',
                'is_paid' => true,
                'price_cents' => 9900,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/broken-suite/install-authorization' => Http::response([
            'message' => 'Marketplace authorization failed.',
        ], 500),
    ]);

    InstallMarketplaceExtensionAction::run(installMarketplaceActionRequest('broken-suite'));

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->extension_slug)->toBe('broken-suite')
        ->and($attempt->composer_name)->toBe('capell-app/broken-suite')
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::AuthorizationFailed)
        ->and($attempt->failure_reason)->toBe('Marketplace authorization failed.');
});

it('records authorization-blocked attempts and returns account connection redirects', function (): void {
    config(['capell-marketplace.marketplace.web_url' => 'https://capell.test']);
    installMarketplaceExtensionActionConnectedInstance();

    Http::fake([
        'https://marketplace.test/api/extensions/auth-blocked-suite' => Http::response([
            'data' => installMarketplaceExtensionActionPayload([
                'slug' => 'auth-blocked-suite',
                'name' => 'Auth Blocked Suite',
                'composer_name' => 'capell-app/auth-blocked-suite',
                'is_paid' => true,
                'price_cents' => 9900,
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                ],
            ]),
        ]),
        'https://marketplace.test/api/extensions/auth-blocked-suite/install-authorization' => Http::response([
            'data' => [
                'composer_name' => 'capell-app/auth-blocked-suite',
                'version_constraint' => '^1.0',
                'install_eligibility' => [
                    'state' => 'blocked',
                    'block_reason' => 'account_required',
                ],
            ],
        ]),
        'https://marketplace.test/api/marketplace/connections' => Http::response([
            'message' => 'Marketplace unavailable.',
        ], 503),
    ]);

    $redirectUrl = InstallMarketplaceExtensionAction::run(
        installMarketplaceActionRequest('auth-blocked-suite', ['_redirect_account_actions' => true]),
    );

    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($redirectUrl)->toBe('https://capell.test/login')
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('account_required');
});

it('records theme install intents during marketplace install orchestration', function (): void {
    Queue::fake();

    Http::fake([
        'https://marketplace.test/api/extensions/theme-suite' => Http::response([
            'data' => installMarketplaceExtensionActionPayload([
                'slug' => 'theme-suite',
                'name' => 'Theme Suite',
                'composer_name' => 'capell-app/theme-suite',
                'kind' => 'theme',
                'latest_version' => '3.2.1',
                'description' => 'Theme install coverage.',
            ]),
        ]),
    ]);

    InstallMarketplaceExtensionAction::run(installMarketplaceActionRequest('theme-suite'));

    $intent = MarketplaceInstallIntent::query()->sole();
    $attempt = MarketplaceInstallAttempt::query()->sole();

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($intent->extension_slug)->toBe('theme-suite')
        ->and($intent->composer_name)->toBe('capell-app/theme-suite')
        ->and($intent->status)->toBe(MarketplaceInstallIntentStatus::Pending)
        ->and($intent->version_constraint)->toBe('^3.2.1');
});

it('blocks duplicate active queue attempts for the same composer package', function (): void {
    Queue::fake();

    Http::fake([
        'https://marketplace.test/api/extensions/duplicate-suite' => Http::response([
            'data' => installMarketplaceExtensionActionPayload([
                'slug' => 'duplicate-suite',
                'name' => 'Duplicate Suite',
                'composer_name' => 'capell-app/duplicate-suite',
            ]),
        ]),
    ]);

    InstallMarketplaceExtensionAction::run(installMarketplaceActionRequest('duplicate-suite'));

    expect(fn (): ?string => InstallMarketplaceExtensionAction::run(installMarketplaceActionRequest('duplicate-suite')))
        ->toThrow(ValidationException::class);

    expect(MarketplaceInstallAttempt::query()->count())->toBe(1);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function installMarketplaceExtensionActionPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'slug' => 'example-suite',
        'name' => 'Example Suite',
        'composer_name' => 'capell-app/example-suite',
        'kind' => 'tool',
        'description' => 'Example marketplace extension.',
        'price_cents' => 0,
        'is_paid' => false,
        'latest_version' => '1.0.0',
        'install_options' => [],
    ], $overrides);
}

function installMarketplaceExtensionActionConnectedInstance(): MarketplaceInstance
{
    return MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'test-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'acct_123',
        'account_email_verified_at' => now(),
        'last_heartbeat_at' => now(),
    ]);
}

/** @param array<string, mixed> $options */
function installMarketplaceActionRequest(string $slug, array $options = []): MarketplaceInstallRequestData
{
    return MarketplaceInstallRequestData::make(
        extensionSlug: $slug,
        options: $options,
        actor: MarketplaceInstallActorData::system('marketplace-action-test'),
        betaAcknowledged: data_get($options, 'install_options.beta_acknowledged') === true,
        source: MarketplaceInstallSource::Programmatic,
    );
}
