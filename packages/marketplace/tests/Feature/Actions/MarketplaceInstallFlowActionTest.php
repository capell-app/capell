<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CompleteMarketplaceInstallFlowAction;
use Capell\Marketplace\Actions\ResumeMarketplaceInstallFlowAction;
use Capell\Marketplace\Actions\StartMarketplaceInstallFlowAction;
use Capell\Marketplace\Data\CreateMarketplaceInstallFlowSessionData;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();

    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api/v1',
    ]);
});

it('starts a hosted marketplace install flow for a selected premium basket', function (): void {
    Http::fake([
        'https://marketplace.test/api/v1/marketplace/install-flows' => Http::response([
            'data' => [
                'flow_id' => 'mif_123',
                'contract_version' => 2,
                'approval_url' => 'https://marketplace.test/marketplace/install-flows/mif_123',
                'expires_at' => now()->addMinutes(20)->toIso8601String(),
                'quote' => [
                    'currency' => 'usd',
                    'price_cents' => 4900,
                    'extensions' => [
                        [
                            'slug' => 'premium-seo',
                            'composer_name' => 'capell-marketplace/premium-seo',
                            'name' => 'Premium SEO',
                            'price_cents' => 4900,
                        ],
                    ],
                ],
            ],
        ], 201),
    ]);

    $approvalUrl = StartMarketplaceInstallFlowAction::run(new CreateMarketplaceInstallFlowSessionData(
        selectedExtensions: [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
                'name' => 'Premium SEO',
                'kind' => 'tool',
                'price_cents' => 4900,
            ],
        ],
        installOptions: [],
        dependencySnapshot: ['dependency_composer_names' => []],
        userContext: ['user_email' => 'admin@example.com'],
        returnUrl: 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
    ));

    $session = MarketplaceInstallFlowSession::query()->where('remote_flow_id', 'mif_123')->firstOrFail();
    $quotedExtensions = $session->quoted_extensions ?? [];
    $selectedExtensions = $session->selected_extensions ?? [];

    expect($approvalUrl)->toBe('https://marketplace.test/marketplace/install-flows/mif_123')
        ->and($session)->toBeInstanceOf(MarketplaceInstallFlowSession::class)
        ->and($session->status)->toBe(MarketplaceInstallFlowSessionStatus::Redirected)
        ->and($session->contract_version)->toBe(2)
        ->and($session->quoted_price_cents)->toBe(4900)
        ->and($session->quoted_currency)->toBe('usd')
        ->and($quotedExtensions[0]['composer_name'] ?? null)->toBe('capell-marketplace/premium-seo')
        ->and($selectedExtensions[0]['composer_name'] ?? null)->toBe('capell-marketplace/premium-seo')
        ->and($session->state_hash)->toHaveLength(64)
        ->and($session->code_verifier_encrypted)->not->toBe('');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/v1/marketplace/install-flows'
        && $request->data()['app_url'] === 'http://capell-ruby.test'
        && $request->data()['return_url'] === 'http://capell-ruby.test/admin/marketplace/install-flow/callback'
        && $request->data()['selected_extensions'][0]['composer_name'] === 'capell-marketplace/premium-seo'
        && is_string($request->data()['state'])
        && is_string($request->data()['code_challenge']));
});

it('checks out a premium extension through v2 hosted flow and queues the authorized install', function (): void {
    Bus::fake();

    config(['capell-marketplace.marketplace.webhook_secret' => 'fallback-secret']);

    $state = null;

    Http::fake(function ($request) use (&$state): mixed {
        $url = (string) $request->url();

        if ($url === 'https://marketplace.test/api/v1/marketplace/install-flows') {
            $state = $request->data()['state'] ?? null;

            return Http::response([
                'data' => [
                    'flow_id' => 'mif_checkout',
                    'contract_version' => 2,
                    'approval_url' => 'https://marketplace.test/marketplace/install-flows/mif_checkout',
                    'expires_at' => now()->addMinutes(20)->toIso8601String(),
                    'quote' => [
                        'currency' => 'usd',
                        'price_cents' => 4900,
                        'extensions' => [
                            [
                                'slug' => 'premium-seo',
                                'composer_name' => 'capell-marketplace/premium-seo',
                                'name' => 'Premium SEO',
                                'kind' => 'tool',
                                'price_cents' => 4900,
                            ],
                        ],
                    ],
                ],
            ], 201);
        }

        if ($url === 'https://marketplace.test/api/v1/marketplace/install-flows/exchange') {
            return Http::response([
                'data' => [
                    'flow_id' => 'mif_checkout',
                    'contract_version' => 2,
                    'can_install' => true,
                    'account' => [
                        'account_id' => 'user_123',
                        'account_name' => 'Ben Johnson',
                        'account_email' => 'ben@example.com',
                        'account_email_verified_at' => now()->toIso8601String(),
                    ],
                    'instance' => [
                        'instance_id' => '00000000-0000-4000-8000-000000000123',
                        'signing_secret' => 'new-secret',
                    ],
                    'quote' => [
                        'currency' => 'usd',
                        'price_cents' => 4900,
                        'extensions' => [
                            [
                                'slug' => 'premium-seo',
                                'composer_name' => 'capell-marketplace/premium-seo',
                                'name' => 'Premium SEO',
                                'kind' => 'tool',
                                'price_cents' => 4900,
                            ],
                        ],
                    ],
                    'entitlements' => [
                        'capell-marketplace/premium-seo' => 123,
                    ],
                    'eligibility' => [
                        [
                            'composer_name' => 'capell-marketplace/premium-seo',
                            'state' => 'authorized',
                            'can_install' => true,
                        ],
                    ],
                    'metadata' => [
                        'checkout_session_id' => 'cs_test_checkout',
                    ],
                ],
            ]);
        }

        if ($url === 'https://marketplace.test/api/v1/extensions/premium-seo') {
            return Http::response([
                'data' => [
                    'slug' => 'premium-seo',
                    'name' => 'Premium SEO',
                    'composer_name' => 'capell-marketplace/premium-seo',
                    'kind' => 'tool',
                    'price_cents' => 4900,
                    'is_paid' => true,
                    'latest_version' => '1.2.3',
                    'install_eligibility' => [
                        'state' => 'authorized',
                        'can_install' => true,
                        'can_update' => true,
                        'can_run_existing' => true,
                    ],
                ],
            ]);
        }

        if ($url === 'https://marketplace.test/api/v1/extensions/premium-seo/install-authorization') {
            return Http::response([
                'data' => [
                    'composer_name' => 'capell-marketplace/premium-seo',
                    'version_constraint' => '^1.2.3',
                    'repository_url' => null,
                    'composer_auth' => null,
                    'expires_at' => now()->addMinutes(15)->toIso8601String(),
                    'signed_activation' => [],
                    'metadata' => [
                        'entitlement_id' => 123,
                    ],
                    'install_eligibility' => [
                        'state' => 'authorized',
                        'can_install' => true,
                        'can_update' => true,
                        'can_run_existing' => true,
                    ],
                ],
            ]);
        }

        if ($url === 'https://marketplace.test/api/v1/extensions/install-intents') {
            return Http::response(['data' => ['recorded' => true]], 201);
        }

        return Http::response(['message' => 'Unexpected request', 'url' => $url], 404);
    });

    $approvalUrl = StartMarketplaceInstallFlowAction::run(new CreateMarketplaceInstallFlowSessionData(
        selectedExtensions: [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
                'name' => 'Premium SEO',
                'kind' => 'tool',
                'price_cents' => 4900,
            ],
        ],
        installOptions: [],
        dependencySnapshot: ['dependency_composer_names' => []],
        userContext: ['user_email' => 'admin@example.com'],
        returnUrl: 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
    ));

    expect($approvalUrl)->toBe('https://marketplace.test/marketplace/install-flows/mif_checkout')
        ->and($state)->toBeString();

    $returnedSession = CompleteMarketplaceInstallFlowAction::run('mif_checkout', 'code_123', (string) $state);
    $attempts = ResumeMarketplaceInstallFlowAction::run($returnedSession);

    $session = MarketplaceInstallFlowSession::query()->firstWhere('remote_flow_id', 'mif_checkout');
    $attempt = MarketplaceInstallAttempt::query()->sole();
    $instance = MarketplaceInstance::query()->where('instance_id', '00000000-0000-4000-8000-000000000123')->firstOrFail();
    assert($instance instanceof MarketplaceInstance);

    expect($attempts)->toHaveCount(1)
        ->and($session?->status)->toBe(MarketplaceInstallFlowSessionStatus::Completed)
        ->and($session?->remote_entitlement_ids)->toBe(['capell-marketplace/premium-seo' => 123])
        ->and($attempt->composer_name)->toBe('capell-marketplace/premium-seo')
        ->and($attempt->composer_command)->toBe('composer require capell-marketplace/premium-seo:^1.2.3')
        ->and($attempt->context['remote_entitlement_ids']['capell-marketplace/premium-seo'] ?? null)->toBe(123)
        ->and($instance?->account_email)->toBe('ben@example.com');
});

it('rejects install flow approval redirects outside the configured marketplace host', function (): void {
    Http::fake([
        'https://marketplace.test/api/v1/marketplace/install-flows' => Http::response([
            'data' => [
                'flow_id' => 'mif_123',
                'approval_url' => 'https://evil.test/marketplace/install-flows/mif_123',
                'expires_at' => now()->addMinutes(20)->toIso8601String(),
            ],
        ], 201),
    ]);

    expect(fn (): string => StartMarketplaceInstallFlowAction::run(new CreateMarketplaceInstallFlowSessionData(
        selectedExtensions: [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
            ],
        ],
        installOptions: [],
        dependencySnapshot: [],
        userContext: [],
        returnUrl: 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
    )))->toThrow(RuntimeException::class, 'Marketplace returned an invalid approval URL.');

    expect(MarketplaceInstallFlowSession::query()->first()?->status)
        ->toBe(MarketplaceInstallFlowSessionStatus::Failed);
});

it('rejects install flow approval redirects with a mismatched scheme or port', function (string $approvalUrl): void {
    Http::fake([
        'https://marketplace.test/api/v1/marketplace/install-flows' => Http::response([
            'data' => [
                'flow_id' => 'mif_123',
                'approval_url' => $approvalUrl,
                'expires_at' => now()->addMinutes(20)->toIso8601String(),
            ],
        ], 201),
    ]);

    expect(fn (): string => StartMarketplaceInstallFlowAction::run(new CreateMarketplaceInstallFlowSessionData(
        selectedExtensions: [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
            ],
        ],
        installOptions: [],
        dependencySnapshot: [],
        userContext: [],
        returnUrl: 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
    )))->toThrow(RuntimeException::class, 'Marketplace returned an invalid approval URL.');

    expect(MarketplaceInstallFlowSession::query()->first()?->status)
        ->toBe(MarketplaceInstallFlowSessionStatus::Failed);
})->with([
    'scheme downgrade' => ['http://marketplace.test/marketplace/install-flows/mif_123'],
    'unexpected port' => ['https://marketplace.test:8443/marketplace/install-flows/mif_123'],
]);

it('stores a concise failure reason when the remote marketplace returns a long error', function (): void {
    $remoteFailure = str_repeat('Remote marketplace schema is missing contract_version. ', 20);

    Http::fake([
        'https://marketplace.test/api/v1/marketplace/install-flows' => Http::response([
            'message' => $remoteFailure,
        ], 500),
    ]);

    expect(fn (): string => StartMarketplaceInstallFlowAction::run(new CreateMarketplaceInstallFlowSessionData(
        selectedExtensions: [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
            ],
        ],
        installOptions: [],
        dependencySnapshot: [],
        userContext: [],
        returnUrl: 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
    )))->toThrow(RuntimeException::class);

    $session = MarketplaceInstallFlowSession::query()->sole();

    expect($session->status)->toBe(MarketplaceInstallFlowSessionStatus::Failed)
        ->and($session->failure_reason)->toEndWith('...')
        ->and(mb_strlen((string) $session->failure_reason))->toBeLessThanOrEqual(243)
        ->and($session->last_error)->toContain('Remote marketplace schema is missing contract_version');
});

it('completes a returned install flow and stores verified account credentials', function (): void {
    config(['capell-marketplace.marketplace.webhook_secret' => 'fallback-secret']);

    MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_123',
        'status' => MarketplaceInstallFlowSessionStatus::Redirected,
        'selected_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
            ],
        ],
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'return_url' => 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
        'expires_at' => now()->addMinutes(20),
    ]);

    Http::fake([
        'https://marketplace.test/api/v1/marketplace/install-flows/exchange' => Http::response([
            'data' => [
                'flow_id' => 'mif_123',
                'contract_version' => 2,
                'can_install' => true,
                'account' => [
                    'account_id' => 'user_123',
                    'account_name' => 'Ben Johnson',
                    'account_email' => 'ben@example.com',
                    'account_email_verified_at' => now()->toIso8601String(),
                ],
                'instance' => [
                    'instance_id' => '00000000-0000-4000-8000-000000000123',
                    'signing_secret' => 'new-secret',
                    'access_token' => 'instance-access-token',
                ],
                'quote' => [
                    'currency' => 'usd',
                    'price_cents' => 4900,
                    'extensions' => [
                        [
                            'slug' => 'premium-seo',
                            'composer_name' => 'capell-marketplace/premium-seo',
                            'price_cents' => 4900,
                        ],
                    ],
                ],
                'entitlements' => [
                    'capell-marketplace/premium-seo' => 123,
                ],
                'eligibility' => [
                    [
                        'composer_name' => 'capell-marketplace/premium-seo',
                        'can_install' => true,
                    ],
                ],
                'metadata' => [
                    'license_key' => 'lic_secret_123',
                    'safe_reference' => 'mif_123_reference',
                ],
            ],
        ]),
    ]);

    $session = CompleteMarketplaceInstallFlowAction::run('mif_123', 'code_123', 'state_123');
    $instance = MarketplaceInstance::query()->where('instance_id', '00000000-0000-4000-8000-000000000123')->firstOrFail();
    assert($instance instanceof MarketplaceInstance);

    $rawExchangePayload = (string) DB::table('marketplace_install_flow_sessions')
        ->where('id', $session->getKey())
        ->value('last_exchange_payload');

    expect($session->status)->toBe(MarketplaceInstallFlowSessionStatus::Returned)
        ->and($session->contract_version)->toBe(2)
        ->and($session->quoted_price_cents)->toBe(4900)
        ->and($session->remote_entitlement_ids)->toBe(['capell-marketplace/premium-seo' => 123])
        ->and($session->last_exchange_payload['flow_id'])->toBe('mif_123')
        ->and(data_get($session->last_exchange_payload, 'instance.signing_secret'))->toBe('[redacted]')
        ->and(data_get($session->last_exchange_payload, 'instance.access_token'))->toBe('[redacted]')
        ->and(data_get($session->last_exchange_payload, 'metadata.license_key'))->toBe('[redacted]')
        ->and(data_get($session->last_exchange_payload, 'metadata.safe_reference'))->toBe('mif_123_reference')
        ->and($rawExchangePayload)->not->toContain('new-secret')
        ->and($rawExchangePayload)->not->toContain('instance-access-token')
        ->and($rawExchangePayload)->not->toContain('lic_secret_123')
        ->and($instance)->toBeInstanceOf(MarketplaceInstance::class)
        ->and($instance->connection_mode)->toBe(MarketplaceConnectionMode::AccountLinked)
        ->and($instance->signing_secret_encrypted)->toBe('new-secret')
        ->and($instance->account_email)->toBe('ben@example.com');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/v1/marketplace/install-flows/exchange'
        && $request->data()['flow_id'] === 'mif_123'
        && $request->data()['code'] === 'code_123'
        && $request->data()['state'] === 'state_123'
        && $request->data()['code_verifier'] === 'verifier_123');
});

it('fails closed when a v2 returned paid flow is missing an entitlement id', function (): void {
    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000123',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'user_123',
        'account_email' => 'ben@example.com',
        'account_email_verified_at' => now(),
        'signing_secret_encrypted' => 'instance-secret',
        'last_heartbeat_at' => now(),
    ]);

    $session = MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_missing_entitlement',
        'status' => MarketplaceInstallFlowSessionStatus::Returned,
        'contract_version' => 2,
        'selected_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
            ],
        ],
        'quoted_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
                'price_cents' => 4900,
            ],
        ],
        'quoted_price_cents' => 4900,
        'quoted_currency' => 'usd',
        'remote_entitlement_ids' => [],
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'return_url' => 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
        'expires_at' => now()->addMinutes(20),
        'returned_at' => now(),
    ]);

    expect(fn (): array => ResumeMarketplaceInstallFlowAction::run($session))
        ->toThrow(RuntimeException::class, 'Marketplace install flow is missing entitlement for [capell-marketplace/premium-seo].');

    $session->refresh();

    expect(MarketplaceInstallAttempt::query()->count())->toBe(0)
        ->and($session->status)->toBe(MarketplaceInstallFlowSessionStatus::Failed)
        ->and($session->failure_reason)->toBe('Marketplace install flow is missing entitlement for [capell-marketplace/premium-seo].');
});

it('marks a returned install flow failed when account credentials are not verified', function (): void {
    config(['capell-marketplace.marketplace.webhook_secret' => 'fallback-secret']);

    MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_unverified',
        'status' => MarketplaceInstallFlowSessionStatus::Redirected,
        'selected_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
            ],
        ],
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'return_url' => 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
        'expires_at' => now()->addMinutes(20),
    ]);

    Http::fake([
        'https://marketplace.test/api/v1/marketplace/install-flows/exchange' => Http::response([
            'data' => [
                'flow_id' => 'mif_unverified',
                'can_install' => true,
                'account' => [
                    'account_id' => 'user_123',
                    'account_name' => 'Ben Johnson',
                    'account_email' => 'ben@example.com',
                    'account_email_verified_at' => null,
                ],
                'instance' => [
                    'instance_id' => '00000000-0000-4000-8000-000000000123',
                    'signing_secret' => 'new-secret',
                ],
            ],
        ]),
    ]);

    expect(fn (): MarketplaceInstallFlowSession => CompleteMarketplaceInstallFlowAction::run('mif_unverified', 'code_123', 'state_123'))
        ->toThrow(RuntimeException::class, 'Your Capell account email must be verified before installing Marketplace packages.');

    $session = MarketplaceInstallFlowSession::query()->firstWhere('remote_flow_id', 'mif_unverified');

    expect($session?->status)->toBe(MarketplaceInstallFlowSessionStatus::Failed)
        ->and($session?->last_error)->toBe('Your Capell account email must be verified before installing Marketplace packages.');
});

it('resumes a returned flow using the listing composer name when authorization omits it', function (): void {
    Bus::fake();

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000123',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'account_id' => 'user_123',
        'account_email' => 'ben@example.com',
        'account_email_verified_at' => now(),
        'signing_secret_encrypted' => 'instance-secret',
        'last_heartbeat_at' => now(),
    ]);

    $session = MarketplaceInstallFlowSession::query()->create([
        'remote_flow_id' => 'mif_resume',
        'status' => MarketplaceInstallFlowSessionStatus::Returned,
        'selected_extensions' => [
            [
                'slug' => 'premium-seo',
                'composer_name' => 'capell-marketplace/premium-seo',
            ],
        ],
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'return_url' => 'http://capell-ruby.test/admin/marketplace/install-flow/callback',
        'expires_at' => now()->addMinutes(20),
        'returned_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/v1/extensions/premium-seo' => Http::response([
            'data' => [
                'slug' => 'premium-seo',
                'name' => 'Premium SEO',
                'composer_name' => 'capell-marketplace/premium-seo',
                'kind' => 'tool',
                'price_cents' => 4900,
                'is_paid' => true,
                'latest_version' => '1.2.3',
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                    'can_update' => true,
                    'can_run_existing' => true,
                ],
            ],
        ]),
        'https://marketplace.test/api/v1/extensions/premium-seo/install-authorization' => Http::response([
            'data' => [
                'composer_name' => '',
                'version_constraint' => '^1.2.3',
                'repository_url' => null,
                'composer_auth' => null,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'signed_activation' => [],
                'metadata' => [],
                'install_eligibility' => [
                    'state' => 'authorized',
                    'can_install' => true,
                    'can_update' => true,
                    'can_run_existing' => true,
                ],
            ],
        ]),
        'https://marketplace.test/api/v1/extensions/install-intents' => Http::response(['data' => ['recorded' => true]], 201),
    ]);

    $attempts = ResumeMarketplaceInstallFlowAction::run($session);
    $attempt = MarketplaceInstallAttempt::query()->firstOrFail();

    expect($attempts)->toHaveCount(1)
        ->and($attempt)->toBeInstanceOf(MarketplaceInstallAttempt::class)
        ->and($attempt->composer_name)->toBe('capell-marketplace/premium-seo')
        ->and($attempt->composer_command)->toBe('composer require capell-marketplace/premium-seo:^1.2.3');

    $session->refresh();

    expect($session->status)->toBe(MarketplaceInstallFlowSessionStatus::Completed);
});
