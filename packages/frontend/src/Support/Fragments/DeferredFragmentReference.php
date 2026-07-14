<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Fragments;

use Illuminate\Support\Facades\Crypt;
use Throwable;

final class DeferredFragmentReference
{
    /** @param  array<string, mixed>|string  $reference */
    public static function cacheKey(array|string $reference): string
    {
        if (is_string($reference)) {
            $decodedReference = self::decode($reference);

            if ($decodedReference !== []) {
                return self::cacheKey($decodedReference);
            }

            return hash_hmac('sha256', $reference, (string) config('app.key'));
        }

        ksort($reference);

        return hash_hmac('sha256', json_encode($reference, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $reference
     */
    public static function encode(array $reference): string
    {
        return rtrim(strtr(Crypt::encryptString(json_encode($reference, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $reference): array
    {
        try {
            $encryptedReference = strtr($reference, '-_', '+/');
            $encryptedReference .= str_repeat('=', (4 - strlen($encryptedReference) % 4) % 4);

            $decoded = json_decode(Crypt::decryptString($encryptedReference), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
