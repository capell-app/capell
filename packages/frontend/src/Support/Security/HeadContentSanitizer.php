<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

use DOMDocument;
use DOMElement;

final class HeadContentSanitizer
{
    public static function css(string $css): string
    {
        return (string) preg_replace('/<\/(style|script)/i', '<\/$1', $css);
    }

    public static function cssValue(?string $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $sanitized = str_replace(["\0", "\r", "\n", '<', '>', '{', '}', ';'], '', self::css($value));

        return trim($sanitized) !== '' ? $sanitized : $fallback;
    }

    public static function headSnippet(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<div id="capell-head-snippet">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $output = '';
        $container = $document->getElementById('capell-head-snippet');

        if (! $container instanceof DOMElement) {
            return '';
        }

        foreach (iterator_to_array($container->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if (! in_array($node->tagName, ['meta', 'link'], true)) {
                continue;
            }

            self::sanitizeElement($node);
            $output .= $document->saveHTML($node) ?: '';
        }

        return $output;
    }

    private static function sanitizeElement(DOMElement $element): void
    {
        $allowed = $element->tagName === 'meta'
            ? ['charset', 'content', 'http-equiv', 'name', 'property']
            : ['crossorigin', 'href', 'hreflang', 'imagesizes', 'imagesrcset', 'media', 'referrerpolicy', 'rel', 'sizes', 'type'];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->name), $allowed, true)) {
                $element->removeAttributeNode($attribute);
            }
        }

        if ($element->hasAttribute('href') && preg_match('/^\s*(?:javascript|data|vbscript):/i', $element->getAttribute('href')) === 1) {
            $element->removeAttribute('href');
        }
    }
}
