<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CompleteMarketplaceAccountConnectionAction;
use Capell\Marketplace\Actions\StartMarketplaceAccountConnectionAction;
use Capell\Marketplace\Enums\MarketplaceAccountConnectionSessionStatus;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Models\MarketplaceAccountConnectionSession;
use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Support\Facades\Http;

it('starts an account connection without sending a claimed domain', function (): void {
    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections' => Http::response([
            'data' => [
                'connection_session_id' => 'mcs_123',
                'approval_url' => 'https://capell.test/marketplace/connect/mcs_123',
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ], 201),
    ]);

    $approvalUrl = StartMarketplaceAccountConnectionAction::run();

    $session = expectPresent(MarketplaceAccountConnectionSession::query()->firstWhere('connection_session_id', 'mcs_123'));

    expect($approvalUrl)->toBe('https://capell.test/marketplace/connect/mcs_123')
        ->and($session)->toBeInstanceOf(MarketplaceAccountConnectionSession::class)
        ->and($session->claimed_domain)->toBe('capell-ruby.test')
        ->and($session->app_url)->toBe('http://capell-ruby.test')
        ->and($session->callback_url)->toBe('http://capell-ruby.test/admin/marketplace/connection/callback')
        ->and($session->status)->toBe(MarketplaceAccountConnectionSessionStatus::Pending)
        ->and($session->code_verifier_encrypted)->not->toBe('');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://capell.test/api/v1/marketplace/connections'
        && ! array_key_exists('claimed_domain', $request->data())
        && $request->data()['app_url'] === 'http://capell-ruby.test'
        && $request->data()['callback_url'] === 'http://capell-ruby.test/admin/marketplace/connection/callback'
        && is_string($request->data()['state'])
        && is_string($request->data()['code_challenge']));
});

it('completes an account connection and stores account identity', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1']);

    MarketplaceAccountConnectionSession::query()->create([
        'connection_session_id' => 'mcs_123',
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'claimed_domain' => 'capell-ruby.test',
        'app_url' => 'http://capell-ruby.test',
        'callback_url' => 'http://capell-ruby.test/admin/marketplace/connection/callback',
        'status' => MarketplaceAccountConnectionSessionStatus::Pending,
        'expires_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections/exchange' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000123',
                'signing_secret' => 'secret-value',
                'account_id' => 'acct_123',
                'account_name' => 'Ben Johnson',
                'account_email' => 'ben@example.com',
                'account_email_verified_at' => now()->toIso8601String(),
                'diagnostics_summary' => ['publicly_verifiable' => false],
            ],
        ]),
    ]);

    $instance = CompleteMarketplaceAccountConnectionAction::run('mcs_123', 'code_123', 'state_123');

    $session = expectPresent(MarketplaceAccountConnectionSession::query()->firstWhere('connection_session_id', 'mcs_123'));

    expect($instance)->toBeInstanceOf(MarketplaceInstance::class)
        ->and($instance->instance_id)->toBe('00000000-0000-4000-8000-000000000123')
        ->and($instance->connection_mode)->toBe(MarketplaceConnectionMode::AccountLinked)
        ->and($instance->account_email)->toBe('ben@example.com')
        ->and($instance->connection_metadata['app_url'])->toBe('http://capell-ruby.test')
        ->and($instance->connection_metadata['diagnostics_summary']['publicly_verifiable'])->toBeFalse()
        ->and($session->status)->toBe(MarketplaceAccountConnectionSessionStatus::Completed);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://capell.test/api/v1/marketplace/connections/exchange'
        && $request->data()['connection_session_id'] === 'mcs_123'
        && $request->data()['code'] === 'code_123'
        && $request->data()['state'] === 'state_123'
        && $request->data()['code_verifier'] === 'verifier_123');
});

it('replaces connection identity without requiring domain data', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1']);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000123',
        'signing_secret_encrypted' => 'old-secret',
        'connection_mode' => MarketplaceConnectionMode::AccountLinked,
        'last_heartbeat_at' => now()->subHour(),
    ]);

    MarketplaceAccountConnectionSession::query()->create([
        'connection_session_id' => 'mcs_123',
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'claimed_domain' => 'capell-ruby.test',
        'app_url' => 'http://capell-ruby.test',
        'callback_url' => 'http://capell-ruby.test/admin/marketplace/connection/callback',
        'status' => MarketplaceAccountConnectionSessionStatus::Pending,
        'expires_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections/exchange' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000123',
                'signing_secret' => 'new-secret',
                'account_id' => 'acct_123',
                'account_email' => 'ben@example.com',
                'account_email_verified_at' => now()->toIso8601String(),
            ],
        ]),
    ]);

    $instance = CompleteMarketplaceAccountConnectionAction::run('mcs_123', 'code_123', 'state_123');

    expect($instance->connection_mode)->toBe(MarketplaceConnectionMode::AccountLinked)
        ->and($instance->account_id)->toBe('acct_123')
        ->and($instance->connection_metadata['app_url'])->toBe('http://capell-ruby.test');
});

it('marks the local account session failed when marketplace cannot start the remote connection', function (): void {
    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections' => Http::response(['message' => 'Marketplace unavailable.'], 503),
    ]);

    expect(fn (): string => StartMarketplaceAccountConnectionAction::run())
        ->toThrow(RuntimeException::class, 'Marketplace unavailable.');

    $session = expectPresent(MarketplaceAccountConnectionSession::query()->first());

    expect($session)->toBeInstanceOf(MarketplaceAccountConnectionSession::class)
        ->and($session->status)->toBe(MarketplaceAccountConnectionSessionStatus::Failed)
        ->and($session->last_error)->toBe('Marketplace unavailable.');
});

it('rejects account connection approval redirects outside the configured marketplace host', function (): void {
    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections' => Http::response([
            'data' => [
                'connection_session_id' => 'mcs_123',
                'approval_url' => 'https://evil.test/marketplace/connect/mcs_123',
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ], 201),
    ]);

    expect(fn (): string => StartMarketplaceAccountConnectionAction::run())
        ->toThrow(RuntimeException::class, 'Marketplace returned an invalid approval URL.');

    expect(MarketplaceAccountConnectionSession::query()->first()?->status)
        ->toBe(MarketplaceAccountConnectionSessionStatus::Failed);
});

it('rejects account connection approval redirects with a mismatched scheme or port', function (string $approvalUrl): void {
    config([
        'app.url' => 'http://capell-ruby.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections' => Http::response([
            'data' => [
                'connection_session_id' => 'mcs_123',
                'approval_url' => $approvalUrl,
                'expires_at' => now()->addMinutes(10)->toIso8601String(),
            ],
        ], 201),
    ]);

    expect(fn (): string => StartMarketplaceAccountConnectionAction::run())
        ->toThrow(RuntimeException::class, 'Marketplace returned an invalid approval URL.');

    expect(MarketplaceAccountConnectionSession::query()->first()?->status)
        ->toBe(MarketplaceAccountConnectionSessionStatus::Failed);
})->with([
    'scheme downgrade' => ['http://capell.test/marketplace/connect/mcs_123'],
    'unexpected port' => ['https://capell.test:8443/marketplace/connect/mcs_123'],
]);

it('rejects account connections without a verified email', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1']);

    MarketplaceAccountConnectionSession::query()->create([
        'connection_session_id' => 'mcs_123',
        'state_hash' => hash('sha256', 'state_123'),
        'code_verifier_hash' => hash('sha256', 'verifier_123'),
        'code_verifier_encrypted' => 'verifier_123',
        'claimed_domain' => 'capell-ruby.test',
        'app_url' => 'http://capell-ruby.test',
        'callback_url' => 'http://capell-ruby.test/admin/marketplace/connection/callback',
        'status' => MarketplaceAccountConnectionSessionStatus::Pending,
        'expires_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        'https://capell.test/api/v1/marketplace/connections/exchange' => Http::response([
            'data' => [
                'instance_id' => '00000000-0000-4000-8000-000000000123',
                'signing_secret' => 'secret-value',
                'account_id' => 'acct_123',
                'account_email' => 'ben@example.com',
                'account_email_verified_at' => null,
            ],
        ]),
    ]);

    expect(fn (): MarketplaceInstance => CompleteMarketplaceAccountConnectionAction::run('mcs_123', 'code_123', 'state_123'))
        ->toThrow(RuntimeException::class, 'Your Capell account email must be verified before connecting Marketplace.');

    expect(MarketplaceAccountConnectionSession::query()->firstWhere('connection_session_id', 'mcs_123')?->status)
        ->toBe(MarketplaceAccountConnectionSessionStatus::Failed);
});
