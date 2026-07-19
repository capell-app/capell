<?php

declare(strict_types=1);

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Capell\Core\Actions\ProjectBuild\ValidateProjectBuildManifestAction;
use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildManifestSignatureAction;
use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;

it('defines detached signing bytes and verifies Ed25519 signatures', function (): void {
    $keyPair = sodium_crypto_sign_keypair();
    $payload = validProjectBuildManifestPayload();
    $unsigned = ValidateProjectBuildManifestAction::run($payload);
    $payload['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
        CanonicalizeProjectBuildManifestSigningInputAction::run($unsigned),
        sodium_crypto_sign_secretkey($keyPair),
    ));
    $manifest = ValidateProjectBuildManifestAction::run($payload);

    VerifyProjectBuildManifestSignatureAction::run($manifest, sodium_crypto_sign_publickey($keyPair));

    expect(CanonicalizeProjectBuildManifestSigningInputAction::run($manifest))
        ->not->toContain($manifest->signature->value)
        ->toContain('"algorithm":"ed25519"')
        ->toContain('"keyId":"capell-build-2026-01"');
});

it('refuses tampered manifests and the wrong public key', function (string $failure): void {
    $keyPair = sodium_crypto_sign_keypair();
    $payload = validProjectBuildManifestPayload();
    $unsigned = ValidateProjectBuildManifestAction::run($payload);
    $payload['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
        CanonicalizeProjectBuildManifestSigningInputAction::run($unsigned),
        sodium_crypto_sign_secretkey($keyPair),
    ));
    $manifest = ValidateProjectBuildManifestAction::run($payload);

    if ($failure === 'tampered') {
        $tampered = $manifest->toArray();
        $tampered['routes'][0]['path'] = '/tampered';
        $manifest = ProjectBuildManifestData::from($tampered);
    }

    $publicKey = $failure === 'wrong key'
        ? sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())
        : sodium_crypto_sign_publickey($keyPair);

    expect(function () use ($manifest, $publicKey): void {
        VerifyProjectBuildManifestSignatureAction::run($manifest, $publicKey);
    })
        ->toThrow(RuntimeException::class, 'could not be verified');
})->with(['tampered', 'wrong key']);
