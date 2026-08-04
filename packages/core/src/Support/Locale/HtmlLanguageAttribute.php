<?php

declare(strict_types=1);

namespace Capell\Core\Support\Locale;

use Capell\Core\Models\Language;

final class HtmlLanguageAttribute
{
    public static function forLanguage(?Language $language = null): string
    {
        if ($language instanceof Language) {
            $tag = self::normalize($language->locale ?? '') ?: self::normalize($language->code ?? '');

            if ($tag !== '') {
                return $tag;
            }
        }

        return self::current();
    }

    public static function current(): string
    {
        return self::normalize((string) app()->getLocale()) ?: 'en';
    }

    private static function normalize(string $value): string
    {
        return str_replace('_', '-', trim($value));
    }
}
