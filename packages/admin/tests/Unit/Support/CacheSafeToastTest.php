<?php

declare(strict_types=1);

use Capell\Admin\Support\Toast\CacheSafeToast;

/**
 * Decode the base64url-encoded cookie payload back into an array.
 *
 * @return array<string, mixed>
 */
function decodeToastCookie(string $value): array
{
    $base64 = strtr($value, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);

    return json_decode((string) base64_decode($base64, true), true, 512, JSON_THROW_ON_ERROR);
}

it('builds a non-http-only flash cookie with the shared name', function (): void {
    $cookie = CacheSafeToast::cookie(['heading' => 'Saved', 'text' => 'Done', 'variant' => 'success']);

    expect($cookie->getName())->toBe(CacheSafeToast::COOKIE_NAME)
        ->and($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('encodes the toast payload as base64url json', function (): void {
    $cookie = CacheSafeToast::cookie([
        'heading' => 'Saved',
        'text' => 'Changes stored',
        'variant' => 'success',
        'duration' => '4000',
    ]);

    expect(decodeToastCookie((string) $cookie->getValue()))->toBe([
        'heading' => 'Saved',
        'text' => 'Changes stored',
        'variant' => 'success',
        'duration' => 4000,
    ]);
});

it('nulls out empty or non-string fields', function (): void {
    $cookie = CacheSafeToast::cookie(['heading' => '', 'text' => null, 'variant' => 'info', 'duration' => 'abc']);

    expect(decodeToastCookie((string) $cookie->getValue()))->toBe([
        'heading' => null,
        'text' => null,
        'variant' => 'info',
        'duration' => null,
    ]);
});
