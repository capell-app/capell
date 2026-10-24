<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Backup;

use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildManifestSignatureAction;
use Illuminate\Contracts\Config\Repository;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/** @method static void run(array<string, mixed> $manifest) */
final class VerifyBackupManifestSignatureAction
{
    use AsObject;

    public function __construct(private readonly Repository $config) {}

    /** @param array<string, mixed> $manifest */
    public function handle(array $manifest): void
    {
        throw_unless(function_exists('sodium_crypto_sign_verify_detached'), RuntimeException::class, __('capell-core::backup.signing_unavailable'));

        $keyId = data_get($manifest, 'signature.keyId');
        $keys = $this->config->get('backup.signing.public_keys', []);
        $encodedKey = is_string($keyId) && is_array($keys) ? ($keys[$keyId] ?? null) : null;
        $publicKey = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;

        throw_unless(is_string($publicKey) && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, RuntimeException::class, __('capell-core::backup.untrusted_signature'));

        try {
            VerifyProjectBuildManifestSignatureAction::run($manifest, $publicKey);
        } catch (RuntimeException) {
            throw new RuntimeException(__('capell-core::backup.untrusted_signature'));
        }
    }
}
