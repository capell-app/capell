<?php

declare(strict_types=1);

use Capell\Marketplace\Services\MarketplaceClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);
});

function domainProofHeartbeatResponse(array $domainProof): array
{
    return [
        'data' => [
            'instance_id' => 'instance-123',
            'checked_at' => '2026-05-07T09:30:00+00:00',
            'updates' => [],
            'advisories' => [],
            'domain_proof' => $domainProof,
        ],
    ];
}

it('serves a proof token capell.app sent in a heartbeat, and only that token', function (): void {
    $token = Str::random(64);
    Http::fake(['https://marketplace.test/api/instances/heartbeat' => Http::response(domainProofHeartbeatResponse([
        'token' => $token,
        'host' => 'localhost',
        'path' => '/.well-known/capell-domain-proof/' . $token,
        'expires_at' => now()->addMinutes(15)->toIso8601String(),
    ]))]);

    resolve(MarketplaceClient::class)->heartbeat(['instance_id' => 'instance-123']);

    $this->get('/.well-known/capell-domain-proof/' . $token)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSeeText($token);
    expect(trim((string) $this->get('/.well-known/capell-domain-proof/' . $token)->getContent()))->toBe($token);

    $this->get('/.well-known/capell-domain-proof/' . Str::random(64))->assertNotFound();
});

it('answers without a session or the admin stack, so a public fetch can read it', function (): void {
    $token = Str::random(64);
    Http::fake(['https://marketplace.test/api/instances/heartbeat' => Http::response(domainProofHeartbeatResponse([
        'token' => $token,
        'host' => 'localhost',
        'expires_at' => now()->addMinutes(15)->toIso8601String(),
    ]))]);
    resolve(MarketplaceClient::class)->heartbeat(['instance_id' => 'instance-123']);

    $response = $this->get('/.well-known/capell-domain-proof/' . $token)->assertOk();

    expect($response->baseResponse->headers->getCookies())->toBe([]);
});

it('serves a token only on the host capell.app sent it for', function (): void {
    $token = Str::random(64);
    Http::fake(['https://marketplace.test/api/instances/heartbeat' => Http::response(domainProofHeartbeatResponse([
        'token' => $token,
        'host' => 'shop.example.org',
        'expires_at' => now()->addMinutes(15)->toIso8601String(),
    ]))]);
    resolve(MarketplaceClient::class)->heartbeat(['instance_id' => 'instance-123']);

    $this->get('http://other.example.net/.well-known/capell-domain-proof/' . $token)->assertNotFound();
    $this->get('http://localhost/.well-known/capell-domain-proof/' . $token)->assertNotFound();
    $this->get('http://SHOP.example.org/.well-known/capell-domain-proof/' . $token)->assertOk();
});

it('ignores a proof token without a host', function (): void {
    $token = Str::random(64);
    Http::fake(['https://marketplace.test/api/instances/heartbeat' => Http::response(domainProofHeartbeatResponse([
        'token' => $token,
        'expires_at' => now()->addMinutes(15)->toIso8601String(),
    ]))]);
    resolve(MarketplaceClient::class)->heartbeat(['instance_id' => 'instance-123']);

    $this->get('/.well-known/capell-domain-proof/' . $token)->assertNotFound();
});

it('forgets a proof token once it expires', function (): void {
    $token = Str::random(64);
    Http::fake(['https://marketplace.test/api/instances/heartbeat' => Http::response(domainProofHeartbeatResponse([
        'token' => $token,
        'host' => 'localhost',
        'expires_at' => now()->addMinutes(2)->toIso8601String(),
    ]))]);
    resolve(MarketplaceClient::class)->heartbeat(['instance_id' => 'instance-123']);

    $this->travel(3)->minutes();

    $this->get('/.well-known/capell-domain-proof/' . $token)->assertNotFound();
});

it('ignores malformed, expired or over-long proof tokens', function (array $domainProof): void {
    Http::fake(['https://marketplace.test/api/instances/heartbeat' => Http::response(domainProofHeartbeatResponse($domainProof))]);

    $result = resolve(MarketplaceClient::class)->heartbeat(['instance_id' => 'instance-123']);
    $token = is_string($domainProof['token'] ?? null) ? $domainProof['token'] : 'missing';

    expect($result->instanceId)->toBe('instance-123');
    $this->get('/.well-known/capell-domain-proof/' . rawurlencode($token))->assertNotFound();
})->with([
    'short token' => [['host' => 'localhost', 'token' => 'abc', 'expires_at' => now()->addMinutes(5)->toIso8601String()]],
    'path characters' => [['host' => 'localhost', 'token' => str_repeat('a', 40) . '../x', 'expires_at' => now()->addMinutes(5)->toIso8601String()]],
    'already expired' => [['host' => 'localhost', 'token' => str_repeat('b', 64), 'expires_at' => now()->subMinute()->toIso8601String()]],
    'no expiry' => [['host' => 'localhost', 'token' => str_repeat('c', 64)]],
]);

it('stores a proof token from both exchanges and keeps it out of the returned data', function (string $path, string $method): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1']);
    $token = Str::random(64);
    Http::fake(['https://capell.test/api/v1/' . $path => Http::response(['data' => [
        'instance_id' => '00000000-0000-4000-8000-000000000123',
        'flow_id' => 'mif_123',
        'domain_proof' => ['token' => $token, 'host' => 'localhost', 'expires_at' => now()->addMinutes(15)->toIso8601String()],
    ]])]);

    $data = resolve(MarketplaceClient::class)->{$method}([
        'connection_session_id' => 'mcs_123',
        'flow_id' => 'mif_123',
        'code' => 'code_123',
        'state' => 'state_123',
        'code_verifier' => 'verifier_123',
    ]);

    expect($data)->not->toHaveKey('domain_proof');
    $this->get('/.well-known/capell-domain-proof/' . $token)->assertOk();
})->with([
    'account connection' => ['marketplace/connections/exchange', 'exchangeAccountConnectionCode'],
    'install flow' => ['marketplace/install-flows/exchange', 'exchangeInstallFlow'],
]);
