<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Backup;

use Capell\Core\Actions\ProjectBuild\CanonicalizeProjectBuildManifestSigningInputAction;
use Illuminate\Contracts\Config\Repository;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** @method static array<string, mixed> run(array<string, mixed> $manifest) */
final class SignBackupManifestAction
{
    use AsObject;

    public function __construct(private readonly Repository $config) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function handle(array $manifest): array
    {
        throw_unless(function_exists('sodium_crypto_sign_detached'), RuntimeException::class, __('capell-core::backup.signing_unavailable'));

        $keyId = $this->config->get('backup.signing.key_id');
        $encodedKey = $this->config->get('backup.signing.secret_key');
        $secretKey = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;

        throw_unless(is_string($keyId) && $keyId !== '' && is_string($secretKey)
            && strlen($secretKey) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, RuntimeException::class, __('capell-core::backup.signing_key_invalid'));

        $manifest['signature'] = ['algorithm' => 'ed25519', 'keyId' => $keyId, 'value' => ''];
        $manifest['signature']['value'] = base64_encode(sodium_crypto_sign_detached(
            CanonicalizeProjectBuildManifestSigningInputAction::run($manifest),
            $secretKey,
        ));

        // Do not publish a snapshot that this host cannot verify (including mismatched keys).
        VerifyBackupManifestSignatureAction::run($manifest);

        return $manifest;
    }
}
