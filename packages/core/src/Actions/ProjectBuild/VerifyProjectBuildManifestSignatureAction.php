<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ProjectBuild;

use Capell\Core\Data\ProjectBuild\ProjectBuildManifestData;
use Capell\Core\Support\ProjectBuild\ProjectBuildManifestConstraints;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** @method static void run(array<string, mixed>|ProjectBuildManifestData $manifest, string $publicKey) */
final class VerifyProjectBuildManifestSignatureAction
{
    use AsObject;

    /** @param array<string, mixed>|ProjectBuildManifestData $manifest */
    public function handle(array|ProjectBuildManifestData $manifest, string $publicKey): void
    {
        throw_unless(function_exists('sodium_crypto_sign_verify_detached'), RuntimeException::class, 'Ed25519 signature verification requires the Sodium PHP extension.');
        throw_unless(strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, RuntimeException::class, 'The project build manifest public key is invalid.');

        $payload = $manifest instanceof ProjectBuildManifestData ? $manifest->toArray() : $manifest;
        $algorithm = data_get($payload, 'signature.algorithm');
        $keyId = data_get($payload, 'signature.keyId');
        $encodedSignature = data_get($payload, 'signature.value');
        throw_unless($algorithm === 'ed25519' && is_string($keyId) && $keyId !== '', RuntimeException::class, 'The project build manifest signature metadata is invalid.');
        $signature = is_string($encodedSignature) ? base64_decode($encodedSignature, true) : false;
        throw_unless(is_string($signature) && strlen($signature) === ProjectBuildManifestConstraints::ED25519_SIGNATURE_BYTES, RuntimeException::class, 'The project build manifest signature is invalid.');
        throw_unless(sodium_crypto_sign_verify_detached(
            $signature,
            CanonicalizeProjectBuildManifestSigningInputAction::run($payload),
            $publicKey,
        ), RuntimeException::class, 'The project build manifest signature could not be verified.');
    }
}
