<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Widgets;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class OpaqueWidgetReference
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return Crypt::encryptString(JsonCodec::encode($data));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $reference): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($reference), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
