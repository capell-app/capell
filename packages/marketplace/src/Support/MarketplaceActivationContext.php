<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Facades\Crypt;

final readonly class MarketplaceActivationContext
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $signedActivation
     * @return array<string, mixed>
     */
    public static function encryptedInto(array $context, array $signedActivation): array
    {
        if ($signedActivation === []) {
            return $context;
        }

        return [
            ...$context,
            'signed_activation_encrypted' => Crypt::encryptString(JsonCodec::encode($signedActivation)),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>
     */
    public static function decryptFrom(?array $context): array
    {
        $encrypted = $context['signed_activation_encrypted'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return [];
        }

        $decoded = JsonCodec::decodeArray(Crypt::decryptString($encrypted));

        if (array_is_list($decoded)) {
            return [];
        }

        $activation = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $activation[$key] = $value;
            }
        }

        return $activation;
    }
}
