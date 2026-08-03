<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

/**
 * How the public site reacts to a visitor whose stated language preference does
 * not match the language of the page they landed on.
 *
 * The public HTML cache is keyed on host + path alone, so detection may only
 * redirect (before the cache middleware runs) or render a client-side banner
 * whose variants are baked into the cached bytes. It must never vary the
 * rendered response by request header.
 */
enum VisitorLanguageDetection: string
{
    /** Never react to Accept-Language. The default. */
    case Off = 'off';

    /** Redirect a first-time visitor to the sibling URL in their language. */
    case Redirect = 'redirect';

    /** Render a dismissible client-side suggestion instead of redirecting. */
    case Banner = 'banner';

    public static function fromValue(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::Off)
            : self::Off;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::Off->value => __('capell-frontend::form.visitor_language_detection_off'),
            self::Redirect->value => __('capell-frontend::form.visitor_language_detection_redirect'),
            self::Banner->value => __('capell-frontend::form.visitor_language_detection_banner'),
        ];
    }
}
