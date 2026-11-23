<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

use DOMDocument;
use DOMElement;

final class HeadContentSanitizer
{
    /** @var list<string> */
    private const array BLOCKED_HTTP_EQUIV = ['refresh', 'set-cookie', 'content-security-policy', 'content-security-policy-report-only'];

    /** @var list<string> */
    private const array ALLOWED_LINK_RELS = [
        'alternate', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'author', 'canonical', 'dns-prefetch',
        'icon', 'license', 'manifest', 'mask-icon', 'me', 'next', 'preconnect', 'prev', 'search', 'shortcut',
    ];

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

    /**
     * Encodes a URL for a quoted `url('...')` inside an HTML style element, or returns null
     * when it cannot be made safe. Characters that end the string, the function or the
     * element are percent-encoded, which leaves the URL's meaning unchanged.
     */
    public static function cssUrl(string $url): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        return strtr($url, [
            "'" => '%27',
            '"' => '%22',
            '(' => '%28',
            ')' => '%29',
            '\\' => '%5C',
            '<' => '%3C',
            '>' => '%3E',
            ' ' => '%20',
        ]);
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

            if (! self::isPermitted($node)) {
                continue;
            }

            self::sanitizeElement($node);
            $output .= $document->saveHTML($node) ?: '';
        }

        return $output;
    }

    /**
     * Editor head snippets are metadata only. Meta `http-equiv` values that redirect, set
     * cookies or rewrite the content security policy are refused, and a link is kept only
     * when every `rel` token names a relationship that cannot load a stylesheet or script.
     */
    private static function isPermitted(DOMElement $element): bool
    {
        if ($element->tagName === 'meta') {
            $httpEquiv = strtolower(trim((string) preg_replace('/[\s\x00-\x1f]+/', ' ', $element->getAttribute('http-equiv'))));

            return ! in_array($httpEquiv, self::BLOCKED_HTTP_EQUIV, true);
        }

        $tokens = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $tokens !== [] && array_diff($tokens, self::ALLOWED_LINK_RELS) === [];
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
