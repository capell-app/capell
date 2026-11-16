<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Actions\ProjectBuild\VerifyProjectBuildManifestSignatureAction;
use Capell\Core\Support\Marketplace\TrustedMarketplaceIssuerKeys;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class VerifyMarketplaceIssuerSignatureAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): bool
    {
        $keyId = data_get($payload, 'signature.keyId');
        $publicKey = is_string($keyId) ? resolve(TrustedMarketplaceIssuerKeys::class)->key($keyId) : null;
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
