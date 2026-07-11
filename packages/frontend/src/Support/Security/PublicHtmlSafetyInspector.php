<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

use Capell\Frontend\Data\PublicHtmlSafetyDetectionData;

final class PublicHtmlSafetyInspector
{
    private const int MAX_FULL_HTML_UNICODE_DECODE_BYTES = 65536;

    private const int LARGE_SIGNED_URL_SCAN_AFTER_BYTES = 4096;

    private const int LARGE_SIGNED_URL_SCAN_BEFORE_BYTES = 60000;

    /**
     * @var list<non-empty-string>
     */
    private const array AUTHORING_ATTRIBUTES = [
        'data-capell-authoring',
        'data-capell-editable',
        'data-capell-editor',
        'data-capell-editor-url',
        'data-field-path',
        'data-model-id',
        'data-permission',
        'data-capell-package',
    ];

    /**
     * @var list<non-empty-string>
     */
    private const array AUTHORING_JSON_KEYS = [
        'field_path',
        'fieldpath',
        'model_id',
        'modelid',
        'editor_url',
        'editorurl',
        'signed_editor_url',
        'signededitorurl',
        'signed_admin_url',
        'signedadminurl',
    ];

    /**
     * @var list<non-empty-string>
     */
    private const array AUTHORING_SIGNED_URL_JSON_KEYS = [
        'signed_url',
        'signedurl',
    ];

    /**
     * @var list<non-empty-string>
     */
    private const array AUTHORING_CLASS_OR_ID_MARKERS = [
        'capell-authoring',
        'capell-editor',
    ];

    /**
     * Public-safe `data-capell-*` runtime attribute families. These are the
     * namespaces the frontend legitimately emits into public HTML (widget
     * runtime wiring, interaction behaviour, theme tokens). Any `data-capell-*`
     * attribute outside these families (e.g. `data-capell-editor-state`,
     * `data-capell-model-id`, `data-capell-internal-*`, `data-capell-package`)
     * is treated as an authoring/leak surface.
     *
     * Families (prefixes) rather than an exact list are used deliberately: the
     * attribute set grows over time and some entries are emitted from PHP, not
     * Blade, so an exact allowlist would drift and 500 legitimate pages. The
     * authoring/leak attribute names all live outside these families, so they
     * are still caught. KEEP IN SYNC with the public runtime attributes
     * documented in PublicBladeSafetyTest.
     *
     * @var list<non-empty-string>
     */
    private const array ALLOWED_CAPELL_RUNTIME_ATTRIBUTE_PREFIXES = [
        'data-capell-widget-',
        'data-capell-interaction-',
        'data-capell-theme-',
        // The insights package emits a public, anonymous-facing consent banner
        // and analytics tracker. Every data-capell-insights-* attribute is
        // client-side consent/analytics wiring (the package has no editor
        // surface), so the whole family is public-safe.
        'data-capell-insights-',
    ];

    /**
     * Exact public-safe `data-capell-*` attributes that are not covered by a
     * trailing-hyphen family prefix (i.e. the bare family root).
     *
     * @var list<non-empty-string>
     */
    private const array ALLOWED_CAPELL_RUNTIME_ATTRIBUTES = [
        'data-capell-interaction',
    ];

    public function containsAuthoringSurface(string $html): bool
    {
        return $this->detectAuthoringSurface($html) instanceof PublicHtmlSafetyDetectionData;
    }

    public function detectAuthoringSurface(string $html): ?PublicHtmlSafetyDetectionData
    {
        if ($html === '') {
            return null;
        }

        if ($this->containsSignedAdminUrl($html)) {
            return new PublicHtmlSafetyDetectionData(
                category: 'signed_admin_url',
                matched: '/' . $this->adminPath() . '/...signature=',
                reason: 'Public HTML contains a signed admin URL.',
            );
        }

        $attributeMarker = $this->detectAuthoringAttribute($html);

        if ($attributeMarker !== null) {
            return $this->authoringMarkerDetection($attributeMarker);
        }

        $classOrIdMarker = $this->detectAuthoringClassOrId($html);

        if ($classOrIdMarker !== null) {
            return $this->authoringMarkerDetection($classOrIdMarker);
        }

        $unknownCapellAttribute = $this->detectUnknownCapellAttribute($html);

        if ($unknownCapellAttribute !== null) {
            return $this->authoringMarkerDetection($unknownCapellAttribute);
        }

        $literalAuthoringMarker = $this->detectLiteralAuthoringMarker($html);

        if ($literalAuthoringMarker !== null) {
            return $this->authoringMarkerDetection($literalAuthoringMarker);
        }

        $jsonMarker = $this->detectAuthoringJsonPayload($html);

        if ($jsonMarker !== null) {
            return $this->authoringMarkerDetection($jsonMarker);
        }

        $jsonLikeMarker = $this->detectAuthoringJsonLikeMarkup($html);

        if ($jsonLikeMarker !== null) {
            return $this->authoringMarkerDetection($jsonLikeMarker);
        }

        foreach ($this->configuredLiteralMarkers() as $marker) {
            if (stripos($html, $marker) === false) {
                continue;
            }

            return $this->authoringMarkerDetection($marker);
        }

        return null;
    }

    /**
     * @return list<non-empty-string>
     */
    private function configuredLiteralMarkers(): array
    {
        $configuredMarkers = config('capell-frontend.public_html_authoring_markers', []);

        if (! is_array($configuredMarkers)) {
            $configuredMarkers = [];
        }

        $markers = [];

        foreach ($configuredMarkers as $marker) {
            if (is_string($marker) && $marker !== '') {
                $markers[] = $marker;
            }
        }

        return array_values(array_unique($markers));
    }

    private function detectAuthoringAttribute(string $html): ?string
    {
        foreach (self::AUTHORING_ATTRIBUTES as $attribute) {
            $pattern = '#<[^>]+\\s' . preg_quote($attribute, '#') . '(?:\\s|=|>)#i';

            if (preg_match($pattern, $html) === 1) {
                return $attribute;
            }
        }

        return null;
    }

    private function detectAuthoringClassOrId(string $html): ?string
    {
        foreach (self::AUTHORING_CLASS_OR_ID_MARKERS as $marker) {
            $pattern = '#\\s(?:class|id)=["\'][^"\']*\\b' . preg_quote($marker, '#') . '\\b[^"\']*["\']#i';

            if (preg_match($pattern, $html) === 1) {
                return $marker;
            }
        }

        return null;
    }

    /**
     * Flag any `data-capell-*` attribute used on a tag that is not on the
     * public-safe runtime allowlist. `<pre>`/`<code>` blocks are stripped first
     * so documentation/code samples that merely mention an attribute are allowed,
     * matching the behaviour of the other literal-marker detectors.
     */
    private function detectUnknownCapellAttribute(string $html): ?string
    {
        $html = preg_replace('#<(pre|code)\\b[^>]*>.*?</\\1>#is', '', $html) ?? $html;

        // Only inspect attributes actually used on a tag, never the name merely
        // appearing in body text. Scan each opening tag's attribute span as a
        // whole so a tag carrying multiple `data-capell-*` attributes (e.g. an
        // allowed one alongside a leaking one) is fully checked — a single
        // greedy match per tag would otherwise miss all but the last attribute.
        if (preg_match_all('#<[a-z][a-z0-9]*\\b[^>]*>#i', $html, $tagMatches) < 1) {
            return null;
        }

        foreach ($tagMatches[0] as $tag) {
            if (preg_match_all('#\\b(data-capell-[a-z0-9-]+)#i', $tag, $attributeMatches) < 1) {
                continue;
            }

            foreach ($attributeMatches[1] as $attribute) {
                $normalized = strtolower($attribute);

                if ($this->isAllowedCapellRuntimeAttribute($normalized)) {
                    continue;
                }

                return $normalized;
            }
        }

        return null;
    }

    private function isAllowedCapellRuntimeAttribute(string $attribute): bool
    {
        if (in_array($attribute, self::ALLOWED_CAPELL_RUNTIME_ATTRIBUTES, true)) {
            return true;
        }

        return array_any(self::ALLOWED_CAPELL_RUNTIME_ATTRIBUTE_PREFIXES, fn ($prefix): bool => str_starts_with($attribute, (string) $prefix));
    }

    private function detectLiteralAuthoringMarker(string $html): ?string
    {
        $html = preg_replace('#<(pre|code)\\b[^>]*>.*?</\\1>#is', '', $html) ?? $html;

        foreach (self::AUTHORING_CLASS_OR_ID_MARKERS as $marker) {
            foreach ($this->htmlVariants($html) as $htmlVariant) {
                if (stripos($htmlVariant, $marker) !== false) {
                    return $marker;
                }
            }
        }

        return null;
    }

    private function detectAuthoringJsonPayload(string $html): ?string
    {
        preg_match_all('#<script\\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER);

        foreach ($scripts as $script) {
            $body = $script[2];
            $bodyVariants = $this->htmlVariants($body);

            foreach (self::AUTHORING_JSON_KEYS as $key) {
                foreach ($bodyVariants as $bodyVariant) {
                    if ($this->containsAuthoringJsonKey($bodyVariant, $key, allowBareKey: true)) {
                        return '"' . $key . '"';
                    }
                }
            }

            $signedUrlMarker = $this->detectAuthoringSignedUrlJsonKey($bodyVariants);

            if ($signedUrlMarker !== null) {
                return $signedUrlMarker;
            }
        }

        return null;
    }

    private function detectAuthoringJsonLikeMarkup(string $html): ?string
    {
        $html = preg_replace('#<(pre|code)\\b[^>]*>.*?</\\1>#is', '', $html) ?? $html;
        $htmlVariants = $this->htmlVariants($html);

        foreach (self::AUTHORING_JSON_KEYS as $key) {
            foreach ($htmlVariants as $htmlVariant) {
                if ($this->containsAuthoringJsonKey($htmlVariant, $key, allowBareKey: false)) {
                    return '"' . $key . '"';
                }
            }
        }

        return $this->detectAuthoringSignedUrlJsonKey($htmlVariants);
    }

    private function containsAuthoringJsonKey(string $html, string $key, bool $allowBareKey): bool
    {
        $quotedKeyPattern = '["\']' . preg_quote($key, '#') . '["\']';

        if (! $allowBareKey) {
            return preg_match('#' . $quotedKeyPattern . '\\s*:#i', $html) === 1;
        }

        $bareKeyPattern = '(?<![A-Za-z0-9_$-])' . preg_quote($key, '#') . '(?![A-Za-z0-9_$-])';

        return preg_match('#(?:' . $quotedKeyPattern . '|' . $bareKeyPattern . ')\\s*:#i', $html) === 1;
    }

    /**
     * @param  list<string>  $htmlVariants
     */
    private function detectAuthoringSignedUrlJsonKey(array $htmlVariants): ?string
    {
        foreach (self::AUTHORING_SIGNED_URL_JSON_KEYS as $key) {
            foreach ($htmlVariants as $htmlVariant) {
                $quotedKeyPattern = '["\']' . preg_quote($key, '#') . '["\']';
                $bareKeyPattern = '(?<![A-Za-z0-9_$-])' . preg_quote($key, '#') . '(?![A-Za-z0-9_$-])';
                $pattern = '#(?:' . $quotedKeyPattern . '|' . $bareKeyPattern . ')\\s*:\\s*["\'](?<url>[^"\']+)["\']#i';

                if (preg_match($pattern, $htmlVariant, $matches) !== 1) {
                    continue;
                }

                $url = $matches['url'] ?? '';

                if ($this->looksLikeAdminUrl($url)) {
                    return '"' . $key . '"';
                }
            }
        }

        return null;
    }

    private function authoringMarkerDetection(string $matched): PublicHtmlSafetyDetectionData
    {
        return new PublicHtmlSafetyDetectionData(
            category: 'authoring_marker',
            matched: $matched,
            reason: 'Public HTML contains an authoring marker.',
        );
    }

    private function containsSignedAdminUrl(string $html): bool
    {
        foreach ($this->htmlVariants($html) as $candidate) {
            if (! $this->containsSignedAdminSignatureMarker($candidate)) {
                continue;
            }

            if ($this->matchesSignedAdminUrl($candidate)) {
                return true;
            }

            if (strlen($candidate) <= self::MAX_FULL_HTML_UNICODE_DECODE_BYTES) {
                continue;
            }

            if (! str_contains($candidate, '\\u')) {
                continue;
            }

            foreach ($this->signedAdminUrlScanWindows($candidate) as $window) {
                foreach ($this->htmlVariants($window) as $windowVariant) {
                    if ($this->matchesSignedAdminUrl($windowVariant)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function matchesSignedAdminUrl(string $html): bool
    {
        if (! $this->mayContainSignedAdminUrl($html)) {
            return false;
        }

        return array_any($this->signedAdminUrlPatterns(), fn ($pattern): bool => preg_match($pattern, $html) === 1);
    }

    /**
     * @return list<non-empty-string>
     */
    private function signedAdminUrlPatterns(): array
    {
        $adminPath = preg_quote($this->adminPath(), '#');
        $querySeparator = '(?:[?&]|&amp;|\\\\u0026)';

        return [
            '#/' . $adminPath . '/[^\\s"\'<>]*' . $querySeparator . 'signature=#i',
            '#\\\\/' . $adminPath . '\\\\/[^\\s"\'<>]*' . $querySeparator . 'signature=#i',
        ];
    }

    private function mayContainSignedAdminUrl(string $html): bool
    {
        $candidate = strtolower($html);
        $adminPath = strtolower($this->adminPath());

        if (! str_contains($candidate, 'signature=')) {
            return false;
        }

        return str_contains($candidate, '/' . $adminPath . '/')
            || str_contains($candidate, '\\/' . $adminPath . '\\/');
    }

    /**
     * @return iterable<string>
     */
    private function signedAdminUrlScanWindows(string $html): iterable
    {
        $offset = 0;

        while (preg_match($this->signedAdminSignatureMarkerPattern(), $html, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $match = $matches[0];
            $position = $match[1];

            yield $this->signedAdminUrlScanWindow($html, $position);

            $offset = $position + max(1, strlen($match[0]));
        }
    }

    private function signedAdminUrlScanWindow(string $html, int $signaturePosition): string
    {
        $start = $this->signedAdminUrlWindowStart($html, $signaturePosition);
        $length = min(
            strlen($html) - $start,
            ($signaturePosition - $start) + self::LARGE_SIGNED_URL_SCAN_AFTER_BYTES,
        );

        return substr($html, $start, $length);
    }

    private function signedAdminUrlWindowStart(string $html, int $signaturePosition): int
    {
        $minimumStart = max(0, $signaturePosition - self::LARGE_SIGNED_URL_SCAN_BEFORE_BYTES);

        for ($position = $signaturePosition - 1; $position >= $minimumStart; $position--) {
            if (str_contains("\"'`<>\r\n\t ", $html[$position])) {
                return $position + 1;
            }
        }

        return $minimumStart;
    }

    private function containsSignedAdminSignatureMarker(string $html): bool
    {
        return preg_match($this->signedAdminSignatureMarkerPattern(), $html) === 1;
    }

    private function signedAdminSignatureMarkerPattern(): string
    {
        return '#(?:s|\\\\u0073|\\\\u0053)(?:i|\\\\u0069|\\\\u0049)(?:g|\\\\u0067|\\\\u0047)(?:n|\\\\u006e|\\\\u004e)(?:a|\\\\u0061|\\\\u0041)(?:t|\\\\u0074|\\\\u0054)(?:u|\\\\u0075|\\\\u0055)(?:r|\\\\u0072|\\\\u0052)(?:e|\\\\u0065|\\\\u0045)\\s*=#i';
    }

    private function looksLikeAdminUrl(string $url): bool
    {
        $candidate = strtolower(str_replace('\\/', '/', $url));
        $adminPath = strtolower($this->adminPath());

        return str_contains($candidate, '/' . $adminPath . '/');
    }

    /**
     * @return list<string>
     */
    private function htmlVariants(string $html): array
    {
        $variants = [$html];
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($decoded !== $html) {
            $variants[] = $decoded;
        }

        $slashNormalized = str_replace('\\/', '/', $decoded);

        if ($slashNormalized !== $decoded) {
            $variants[] = $slashNormalized;
        }

        if (! str_contains($decoded, '\\u') || strlen($decoded) > self::MAX_FULL_HTML_UNICODE_DECODE_BYTES) {
            return array_values(array_unique($variants));
        }

        $jsonEscaped = preg_replace_callback(
            '#\\\\u([0-9a-fA-F]{4})#',
            static function (array $match): string {
                $character = mb_chr((int) hexdec($match[1]), 'UTF-8');

                return $character === false ? $match[0] : $character;
            },
            $decoded,
        );

        if (! is_string($jsonEscaped)) {
            $jsonEscaped = $decoded;
        }

        $jsonEscaped = str_replace('\\/', '/', $jsonEscaped);

        if ($jsonEscaped !== $decoded) {
            $variants[] = $jsonEscaped;
        }

        return array_values(array_unique($variants));
    }

    private function adminPath(): string
    {
        $path = config('capell-admin.path', 'admin');

        if (! is_string($path)) {
            return 'admin';
        }

        $path = trim($path, '/');

        return $path === '' ? 'admin' : $path;
    }
}
