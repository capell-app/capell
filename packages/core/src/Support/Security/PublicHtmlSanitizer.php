<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Contracts\Security\SanitizesPublicHtml;
use Capell\Core\Enums\HtmlSanitizerProfile;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Override;

/**
 * Sanitises HTML for public output under a named HtmlSanitizerProfile. Strict is
 * the default; URL attributes are validated here whatever the profile allows.
 */
final class PublicHtmlSanitizer implements SanitizesPublicHtml
{
    /** @var array<string, true> */
    private const array NUMERIC_ATTRIBUTES = [
        'colspan' => true,
        'height' => true,
        'rowspan' => true,
        'start' => true,
        'width' => true,
    ];

    /**
     * Applied to every embedded frame: the embed keeps its own origin and can play
     * media, but cannot navigate the page or open forms and modals on it.
     */
    /**
     * Ids must be lower-case slugs, the shape a table of contents produces, so they
     * cannot shadow camel-case page globals such as window.CapellPrivacy or window.Livewire.
     */
    private const string IDENTIFIER_PATTERN = '/^[a-z][a-z0-9-]{0,80}$/';

    /**
     * Positioning utilities let content cover the page (a fake login overlay), so a
     * class granted by a profile can never pin, layer or offset an element.
     */
    private const string POSITIONING_UTILITY = '/^(?:fixed|absolute|sticky|inset|top|right|bottom|left|start|end|z)(?:-|$)/';

    /** Consent category third-party frames wait for before they load. */
    private const string EMBED_CONSENT = 'marketing';

    private const string EMBED_SANDBOX = 'allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox';

    /**
     * @var array<string, true>|null
     */
    private ?array $blockedPublicKeySet = null;

    public function __construct(
        private readonly PublicOutputLeakPolicy $leakPolicy = new PublicOutputLeakPolicy,
        private ?HtmlSanitizerProfileRegistry $profiles = null,
    ) {}

    #[Override]
    public function sanitize(string $html, HtmlSanitizerProfile $profile = HtmlSanitizerProfile::Strict): string
    {
        if (trim($html) === '') {
            return '';
        }

        if ($profile === HtmlSanitizerProfile::SvgUpload) {
            return (new SvgUploadSanitizer)->sanitizeOrEmpty($html);
        }

        $policy = $this->profiles()->policy($profile);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // Parse the fragment straight into <body>. Wrapping it in a <div> let an unbalanced closing tag
        // (a stray </div>) close the wrapper early, silently dropping everything after it.
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('body')->item(0);

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeChildren($root, $policy);

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

    private function profiles(): HtmlSanitizerProfileRegistry
    {
        if ($this->profiles instanceof HtmlSanitizerProfileRegistry) {
            return $this->profiles;
        }

        $container = Container::getInstance();

        return $this->profiles = $container->bound(HtmlSanitizerProfileRegistry::class)
            ? $container->make(HtmlSanitizerProfileRegistry::class)
            : new HtmlSanitizerProfileRegistry;
    }

    private function sanitizeChildren(DOMNode $parent, HtmlSanitizerPolicy $policy): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                // Keep plain text only. Comments, processing instructions and
                // CDATA serialise back verbatim, and browsers end a comment at
                // `<!-->`, `<!--->` or `--!>` where libxml does not, so markup
                // hidden inside one would reach the page unsanitised.
                if ($child::class !== DOMText::class) {
                    $parent->removeChild($child);
                }

                continue;
            }

            $tagName = strtolower($child->tagName);

            if ($policy->discardsWithContent($tagName)) {
                $parent->removeChild($child);

                continue;
            }

            if (! $policy->allowsElement($tagName)) {
                // Sanitize before unwrapping: hoisted children are inserted into the
                // already-captured sibling snapshot and would never be revisited.
                $this->sanitizeChildren($child, $policy);
                $this->unwrapElement($child);

                continue;
            }

            if (! $this->sanitizeAttributes($child, $tagName, $policy)) {
                continue;
            }

            $this->sanitizeChildren($child, $policy);
        }
    }

    /**
     * Returns whether the element's children still need sanitising: false when the
     * element was removed or, like a frame, may not keep any children.
     */
    private function sanitizeAttributes(DOMElement $element, string $tagName, HtmlSanitizerPolicy $policy): bool
    {
        $allowedAttributes = $policy->attributesFor($tagName);

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = $attribute->name;
            $allowed = in_array($name, $allowedAttributes, true)
                || ($name === 'id' && $policy->allowsIdentifierOn($tagName))
                || ($name === 'class' && $policy->allowsClasses);

            if (! $allowed) {
                $element->removeAttribute($name);
            }
        }

        if ($element->hasAttribute('id') && preg_match(self::IDENTIFIER_PATTERN, $element->getAttribute('id')) !== 1) {
            $element->removeAttribute('id');
        }

        if ($element->hasAttribute('class')) {
            $this->sanitizeClasses($element);
        }

        foreach (array_keys(self::NUMERIC_ATTRIBUTES) as $numeric) {
            if ($element->hasAttribute($numeric) && preg_match('/^[1-9]\d{0,3}$/', $element->getAttribute($numeric)) !== 1) {
                $element->removeAttribute($numeric);
            }
        }

        if ($element->hasAttribute('scope') && ! in_array($element->getAttribute('scope'), ['col', 'colgroup', 'row', 'rowgroup'], true)) {
            $element->removeAttribute('scope');
        }

        if ($tagName === 'a') {
            $this->sanitizeAnchor($element, $policy);
        }

        if ($tagName === 'img') {
            return $this->sanitizeImage($element);
        }

        if ($tagName === 'iframe') {
            // Removed, or kept without any children of its own.
            $this->sanitizeEmbed($element, $policy);

            return false;
        }

        return true;
    }

    private function sanitizeClasses(DOMElement $element): void
    {
        // Utility class names only: no characters that could close the attribute or form CSS escapes.
        $classes = array_filter(
            preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [],
            fn (string $class): bool => preg_match('/^[A-Za-z0-9_:\/.\-\[\]%#]{1,80}$/', $class) === 1 && ! $this->isPositioningClass($class),
        );

        if ($classes === []) {
            $element->removeAttribute('class');

            return;
        }

        $element->setAttribute('class', implode(' ', array_unique($classes)));
    }

    private function isPositioningClass(string $class): bool
    {
        // Strip variant prefixes (md:, hover:, group-hover:) and the important/negative markers.
        $utility = ltrim((string) preg_replace('/^(?:[^\[\]:]+:)+/', '', $class), '!-');

        // Arbitrary properties and arbitrary variants ([position:fixed], [&>*]:fixed) can set anything.
        return str_starts_with($utility, '[') || preg_match(self::POSITIONING_UTILITY, $utility) === 1;
    }

    private function sanitizeAnchor(DOMElement $element, HtmlSanitizerPolicy $policy): void
    {
        $href = trim(html_entity_decode($element->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! $this->isSafeAnchorUrl($href)) {
            $element->removeAttribute('href');

            return;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            $element->setAttribute('rel', $policy->externalLinkRel);
            $element->setAttribute('target', '_blank');
        }
    }

    private function sanitizeEmbed(DOMElement $element, HtmlSanitizerPolicy $policy): void
    {
        $source = trim(html_entity_decode($element->getAttribute('src'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = parse_url($source);
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : null;

        $safe = is_array($parts)
            && is_string($host)
            && preg_match('#^https://#', $source) === 1
            && ! $this->containsUnsafeUrlCharacter($source)
            && ! isset($parts['user'])
            && ! isset($parts['port'])
            && $policy->allowsEmbed(strtolower($host), $parts['path'] ?? '')
            && ! $this->isRequestHost(strtolower($host));

        if (! $safe) {
            $element->parentNode?->removeChild($element);

            return;
        }

        // The frame waits for consent: the privacy runtime copies data-src into src once granted.
        $element->removeAttribute('src');
        $element->setAttribute('data-src', $source);
        $element->setAttribute('data-capell-consent', self::EMBED_CONSENT);

        if ($element->hasAttribute('allowfullscreen')) {
            $element->setAttribute('allowfullscreen', '');
        }

        $element->setAttribute('sandbox', self::EMBED_SANDBOX);
        $element->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        $element->setAttribute('loading', 'lazy');

        // A frame never carries inline content of its own.
        while ($element->firstChild instanceof DOMNode) {
            $element->removeChild($element->firstChild);
        }
    }

    /**
     * A frame on the page's own origin with allow-scripts and allow-same-origin can
     * remove its own sandbox, so the rendering host is never an embed host.
     */
    private function isRequestHost(string $host): bool
    {
        $container = Container::getInstance();

        if (! $container->bound('request')) {
            return false;
        }

        $request = $container->make('request');

        return $request instanceof Request && strtolower($request->getHost()) === $host;
    }

    /**
     * Browsers strip tabs and newlines inside URLs and read a backslash as a slash,
     * so "/\t/evil.example" or "/\\evil.example" would leave the site.
     */
    private function containsUnsafeUrlCharacter(string $url): bool
    {
        return preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1;
    }

    private function sanitizeImage(DOMElement $element): bool
    {
        if (! $this->isSafeImageUrl($element->getAttribute('src'))) {
            $element->parentNode?->removeChild($element);

            return false;
        }

        return true;
    }

    /**
     * Expects an already entity-decoded, trimmed URL.
     */
    private function isSafeAnchorUrl(string $url): bool
    {
        return $url !== ''
            && ! $this->containsUnsafeUrlCharacter($url)
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

        return ! $this->containsUnsafeUrlCharacter($url)
            && (preg_match('#^https?://#i', $url) === 1 || $this->isSafeRelativePath($url));
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

        // Reserved envelopes retain their leading underscores in the policy.
        // Check them before alias normalisation so an ordinary `capell` key stays public.
        if (isset($this->blockedPublicKeySet()[strtolower($key)])) {
            return true;
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
