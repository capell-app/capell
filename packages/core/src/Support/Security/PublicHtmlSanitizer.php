<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use DOMDocument;
use DOMElement;
use DOMNode;

final class PublicHtmlSanitizer
{
    /**
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'section' => ['id', 'data-top-extensions-showcase'],
    ];

    /**
     * Keyed by tag name for O(1) isset() lookups on the per-node hot path.
     *
     * @var array<string, true>
     */
    private const array ALLOWED_TAGS = [
        'a' => true,
        'blockquote' => true,
        'br' => true,
        'code' => true,
        'dd' => true,
        'div' => true,
        'dl' => true,
        'dt' => true,
        'em' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'hr' => true,
        'img' => true,
        'li' => true,
        'ol' => true,
        'p' => true,
        'pre' => true,
        'section' => true,
        'span' => true,
        'strong' => true,
        'table' => true,
        'tbody' => true,
        'td' => true,
        'th' => true,
        'thead' => true,
        'tr' => true,
        'ul' => true,
    ];

    /**
     * Keyed by tag name for O(1) isset() lookups on the per-node hot path.
     *
     * @var array<string, true>
     */
    private const array DISCARD_WITH_CONTENT = [
        'applet' => true,
        'audio' => true,
        'canvas' => true,
        'embed' => true,
        'form' => true,
        'iframe' => true,
        'math' => true,
        'noscript' => true,
        'object' => true,
        'script' => true,
        'style' => true,
        'svg' => true,
        'template' => true,
        'video' => true,
    ];

    /**
     * @var array<string, true>|null
     */
    private ?array $blockedPublicKeySet = null;

    public function __construct(
        private readonly PublicOutputLeakPolicy $leakPolicy = new PublicOutputLeakPolicy,
    ) {}

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="capell-sanitize-root">' . $html . '</div></body></html>',
            LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('capell-sanitize-root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeChildren($root);

        return $this->innerHtml($root);
    }

    public function sanitizePublicValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizePublicString($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if ($this->isBlockedPublicKey($key)) {
                continue;
            }

            $sanitizedItem = $this->sanitizePublicValue($item);

            if ($sanitizedItem !== null) {
                $sanitized[$key] = $sanitizedItem;
            }
        }

        return $sanitized;
    }

    private function sanitizePublicString(string $value): ?string
    {
        $sanitized = $this->sanitize($this->redactCredentialFragments($value));

        if ($this->containsBlockedPublicValue($sanitized)) {
            return null;
        }

        return $sanitized;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tagName = strtolower($child->tagName);

            if (isset(self::DISCARD_WITH_CONTENT[$tagName])) {
                $parent->removeChild($child);

                continue;
            }

            if (! isset(self::ALLOWED_TAGS[$tagName])) {
                // Sanitize before unwrapping: hoisted children are inserted into the
                // already-captured sibling snapshot and would never be revisited.
                $this->sanitizeChildren($child);
                $this->unwrapElement($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tagName);
            $this->sanitizeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tagName): void
    {
        $allowedAttributes = self::ALLOWED_ATTRIBUTES[$tagName] ?? [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array($attribute->name, $allowedAttributes, true)) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($tagName === 'a') {
            $this->sanitizeAnchor($element);
        }

        if ($tagName === 'img') {
            $this->sanitizeImage($element);
        }

        if ($element->hasAttribute('id') && ! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,80}$/', $element->getAttribute('id'))) {
            $element->removeAttribute('id');
        }
    }

    private function sanitizeAnchor(DOMElement $element): void
    {
        $href = trim(html_entity_decode($element->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! $this->isSafeAnchorUrl($href)) {
            $element->removeAttribute('href');

            return;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            $element->setAttribute('rel', 'nofollow noopener noreferrer');
            $element->setAttribute('target', '_blank');
        }
    }

    private function sanitizeImage(DOMElement $element): void
    {
        if (! $this->isSafeImageUrl($element->getAttribute('src'))) {
            $element->parentNode?->removeChild($element);

            return;
        }

        foreach (['width', 'height'] as $dimension) {
            if (
                $element->hasAttribute($dimension)
                && ! preg_match('/^[1-9]\d{0,3}$/', $element->getAttribute($dimension))
            ) {
                $element->removeAttribute($dimension);
            }
        }
    }

    /**
     * Expects an already entity-decoded, trimmed URL.
     */
    private function isSafeAnchorUrl(string $url): bool
    {
        return $url !== ''
            && (
                str_starts_with($url, '#')
                || $this->isSafeRelativePath($url)
                || preg_match('#^https?://#i', $url) === 1
                || preg_match('#^mailto:[^\\s@]+@[^\\s@]+$#i', $url) === 1
            );
    }

    private function isSafeImageUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return preg_match('#^https?://#i', $url) === 1
            || $this->isSafeRelativePath($url);
    }

    private function isSafeRelativePath(string $url): bool
    {
        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_starts_with($url, '/\\');
    }

    private function redactCredentialFragments(string $value): string
    {
        // Cheap substring guards: most public strings carry none of these
        // fragments, and str_contains is far cheaper than the regex pass.
        $redacted = $value;

        if (str_contains($redacted, 'Bearer')) {
            $redacted = (string) preg_replace(
                '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}\b/',
                'Bearer [redacted]',
                $redacted,
            );
        }

        if (str_contains($redacted, '@') && str_contains($redacted, '://')) {
            $redacted = (string) preg_replace(
                '/\b([a-z][a-z0-9+.-]*:\/\/)([^:\s\/@]+):([^@\s\/]+)@/i',
                '$1$2:[redacted]@',
                $redacted,
            );
        }

        if (str_contains($redacted, '=')) {
            return (string) preg_replace(
                '/([?&](?:expires|signature|token|access_token|refresh_token)=)[^&\s<>"\']+/i',
                '$1[redacted]',
                $redacted,
            );
        }

        return $redacted;
    }

    private function isBlockedPublicKey(mixed $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $normalizedKey = preg_replace('/[^a-z0-9]+/', '_', strtolower($key));

        if (! is_string($normalizedKey)) {
            return true;
        }

        return isset($this->blockedPublicKeySet()[trim($normalizedKey, '_')]);
    }

    /**
     * @return array<string, true>
     */
    private function blockedPublicKeySet(): array
    {
        return $this->blockedPublicKeySet ??= array_fill_keys($this->leakPolicy->blockedPublicKeys(), true);
    }

    private function containsBlockedPublicValue(string $value): bool
    {
        return array_any($this->leakPolicy->blockedPublicValuePatterns(), fn (string $pattern): bool => preg_match($pattern, $value) === 1);
    }

    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $savedHtml = $element->ownerDocument?->saveHTML($child);
            $html .= ($savedHtml !== null && $savedHtml !== false) ? $savedHtml : '';
        }

        return $html;
    }
}
