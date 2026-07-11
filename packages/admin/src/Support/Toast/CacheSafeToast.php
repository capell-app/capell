<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Toast;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class CacheSafeToast
{
    public const string COOKIE_NAME = 'capell_flash_toast';

    /**
     * @param  array{heading?: mixed, text?: mixed, variant?: mixed, duration?: mixed}  $toast
     */
    public static function cookie(array $toast): Cookie
    {
        return Cookie::create(
            name: self::COOKIE_NAME,
            value: self::encode($toast),
            expire: now()->addMinutes(5),
            path: '/',
            domain: null,
            secure: config('session.secure') === true,
            httpOnly: false,
            raw: false,
            sameSite: 'lax',
        );
    }

    /**
     * @param  array{heading?: mixed, text?: mixed, variant?: mixed, duration?: mixed}  $toast
     */
    private static function encode(array $toast): string
    {
        $payload = [
            'heading' => self::nullableString(Arr::get($toast, 'heading')),
            'text' => self::nullableString(Arr::get($toast, 'text')),
            'variant' => self::nullableString(Arr::get($toast, 'variant')),
            'duration' => is_numeric(Arr::get($toast, 'duration')) ? (int) Arr::get($toast, 'duration') : null,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit($value, 500, '') : null;
    }
}
