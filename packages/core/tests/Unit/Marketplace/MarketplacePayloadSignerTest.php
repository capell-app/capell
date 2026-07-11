<?php

declare(strict_types=1);

use Capell\Core\Support\Marketplace\MarketplacePayloadSigner;

it('canonicalizes marketplace payloads with recursively sorted object keys', function (): void {
    $signer = new MarketplacePayloadSigner;

    expect($signer->canonicalJson([
        'zeta' => true,
        'alpha' => [
            'delta' => 4,
            'beta' => 2,
        ],
        'items' => [
            ['name' => 'second', 'id' => 2],
            ['name' => 'first', 'id' => 1],
        ],
    ]))->toBe('{"alpha":{"beta":2,"delta":4},"items":[{"id":2,"name":"second"},{"id":1,"name":"first"}],"zeta":true}');
});

it('creates prefixed marketplace signatures and signed payload metadata', function (): void {
    $signer = new MarketplacePayloadSigner;

    $payload = $signer->signedPayload([
        'event_type' => 'extension_health_report',
        'instance_id' => 'install-123',
    ], 'secret');

    expect($payload['signature_algorithm'])->toBe('hmac-sha256')
        ->and($payload['signature_nonce'])->toBeString()
        ->and($payload['signature_issued_at'])->toBeString()
        ->and($payload['signature'])->toStartWith('sha256=')
        ->and($payload['signature'])->toBe($signer->signature($payload, 'secret'))
        ->and($signer->verify($payload, 'secret'))->toBeTrue();
});

it('verifies legacy raw marketplace hashes as well as prefixed signatures', function (): void {
    $signer = new MarketplacePayloadSigner;
    $payload = [
        'alert_id' => 'trusted-runtime-alert',
        'severity' => 'warning',
        'category' => 'compatibility',
    ];

    expect($signer->verify($payload, 'secret', $signer->signature($payload, 'secret')))->toBeTrue()
        ->and($signer->verify($payload, 'secret', $signer->rawSignature($payload, 'secret')))->toBeTrue()
        ->and($signer->verify($payload, 'other-secret', $signer->rawSignature($payload, 'secret')))->toBeFalse();
});
