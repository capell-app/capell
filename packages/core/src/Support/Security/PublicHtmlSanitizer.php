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
     * @var array<int, string>
     */
    private const array ALLOWED_TAGS = [
        'a',
        'blockquote',
        'br',
        'code',
        'dd',
        'div',
        'dl',
        'dt',
        'em',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'hr',
        'img',
        'li',
        'ol',
        'p',
        'pre',
        'section',
        'span',
        'strong',
        'table',
        'tbody',
        'td',
        'th',
        'thead',
        'tr',
        'ul',
    ];

    /**
     * @var array<int, string>
     */
    private const array BLOCKED_PUBLIC_KEYS = [
        'access_token',
        'admin_path',
        'admin_url',
        'api_key',
        'api_secret',
        'authorization',
        'bearer',
        'bearer_token',
        'client_secret',
        'credential',
        'credentials',
        'edit_url',
        'editable_regions',
        'expires',
        'field_path',
        'filament_url',
        'internal_id',
        'internal_model_id',
        'model_id',
        'model_type',
        'page_id',
        'password',
        'permission',
        'permissions',
        'private_key',
        'prompt',
        'record_key',
        'recordkey',
        'refresh_token',
        'secret',
        'secret_prompt',
        'selector',
        'signature',
        'signed_editor_url',
        'signed_url',
        'system_prompt',
        'token',
        'webhook_secret',
    ];

    /**
     * @var array<int, string>
     */
    private const array DISCARD_WITH_CONTENT = [
        'applet',
        'audio',
        'canvas',
        'embed',
        'form',
        'iframe',
        'math',
        'noscript',
        'object',
        'script',
        'style',
        'svg',
        'template',
        'video',
    ];

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

            if (in_array($tagName, self::DISCARD_WITH_CONTENT, true)) {
                $parent->removeChild($child);

                continue;
            }

            if (! in_array($tagName, self::ALLOWED_TAGS, true)) {
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
        $href = $element->getAttribute('href');

        if (! $this->isSafeAnchorUrl($href)) {
            $element->removeAttribute('href');

            return;
        }

        if (preg_match('#^https?://#i', trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === 1) {
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

    private function isSafeAnchorUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

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
        $redacted = (string) preg_replace(
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}\b/',
            'Bearer [redacted]',
            $value,
        );

        $redacted = (string) preg_replace(
            '/\b([a-z][a-z0-9+.-]*:\/\/)([^:\s\/@]+):([^@\s\/]+)@/i',
            '$1$2:[redacted]@',
            $redacted,
        );

        return (string) preg_replace(
            '/([?&](?:expires|signature|token|access_token|refresh_token)=)[^&\s<>"\']+/i',
            '$1[redacted]',
            $redacted,
        );
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

        return in_array(trim($normalizedKey, '_'), self::BLOCKED_PUBLIC_KEYS, true);
    }

    private function containsBlockedPublicValue(string $value): bool
    {
        $patterns = [
            '/\bdata-(?:capell-authoring|capell-editor|field-path|model-id|page-id)\b/i',
            '/\b(?:fieldPath|field[_-]?path|modelId|model[_-]?id|pageId|page[_-]?id)\b\s*(?:=|:)/i',
            '/\b(?:CapellFrontendAuthoring|frontend-authoring|signed-editor|signed_editor|editable_regions)\b/i',
            '~(?<![A-Za-z0-9_-])/(?:admin|authoring/regions|filament|filament-peek|livewire)(?:[/?#)"\'\s]|$)~i',
            '/\b(?:Authorization\s*[:=]\s*)?Bearer\s+(?!\[redacted\])[A-Za-z0-9._~+\/=-]{8,}\b/i',
            '/\b(?:secret|token|password|passwd|pwd|credential|private_key|api_key|access_key|client_secret|webhook_secret|signing_secret)\s*[:=]\s*(?!\[redacted\])[^"\'\s,;}{]+/i',
            '/[?&](?:expires|signature|token|access_token|refresh_token)=(?!\[redacted\])[^&\s<>"\']+/i',
        ];

        return array_any($patterns, fn ($pattern): bool => preg_match($pattern, $value) === 1);
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
