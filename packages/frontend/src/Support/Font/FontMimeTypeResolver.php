<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Font;

use Capell\Frontend\Contracts\FontMimeTypeResolverInterface;

class FontMimeTypeResolver implements FontMimeTypeResolverInterface
{
    public static function resolve(string $ext): string
    {
        return match (strtolower($ext)) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => 'application/octet-stream',
        };
    }

    public function getFontFileType(string $font): string
    {
        $path = parse_url($font, PHP_URL_PATH) ?? $font;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'woff2' => 'woff2',
            'woff' => 'woff',
            'ttf' => 'truetype',
            'eot' => 'embedded-opentype',
            'otf' => 'opentype',
            'svg' => 'svg',
            default => 'application/octet-stream',
        };
    }
}
