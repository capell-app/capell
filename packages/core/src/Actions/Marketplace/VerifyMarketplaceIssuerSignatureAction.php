<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildManifestSignatureAction;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class VerifyMarketplaceIssuerSignatureAction
{
    use AsObject;

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): bool
    {
        $keyId = data_get($payload, 'signature.keyId');
        $keys = config('capell.extensions.marketplace_receipt_public_keys', []);
        $encodedKey = is_string($keyId) && is_array($keys) ? ($keys[$keyId] ?? null) : null;
        $publicKey = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
        if (! is_string($publicKey)) {
            return false;
        }

        try {
            VerifyProjectBuildManifestSignatureAction::run($payload, $publicKey);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
