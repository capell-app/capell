<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * How a marketplace credential travels from the authorization response to the
 * worker that will hand it to Composer.
 *
 * Encrypted at rest on the attempt because the attempt row is read by the
 * operations UI, the doctor, the diagnostic bundle and the telemetry payload,
 * and none of those should ever be able to print a registry token.
 */
final readonly class MarketplaceComposerAuthContext
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $composerAuth
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function encryptedInto(array $context, ?array $composerAuth): array
    {
        if ($composerAuth === null || $composerAuth === []) {
            return $context;
        }

        return [
            ...$context,
            'composer_auth_encrypted' => Crypt::encryptString(JsonCodec::encode($composerAuth)),
        ];
    }
}
