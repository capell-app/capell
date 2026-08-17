<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

use Capell\Core\Support\Security\PublicOutputLeakPolicy;
use Capell\Frontend\Data\PublicHtmlSafetyDetectionData;

final class PublicHtmlSafetyInspector
{
    private const int MAX_FULL_HTML_UNICODE_DECODE_BYTES = 65536;

    private const int LARGE_SIGNED_URL_SCAN_AFTER_BYTES = 4096;

    private const int LARGE_SIGNED_URL_SCAN_BEFORE_BYTES = 60000;

    /**
     * Matches `signature=` spelled with any mix of literal characters and
     * JSON `\uXXXX` escapes (both cases), tolerating whitespace before `=`.
     */
    private const string SIGNED_ADMIN_SIGNATURE_MARKER_PATTERN = '#(?:s|\\\\u0073|\\\\u0053)(?:i|\\\\u0069|\\\\u0049)(?:g|\\\\u0067|\\\\u0047)(?:n|\\\\u006e|\\\\u004e)(?:a|\\\\u0061|\\\\u0041)(?:t|\\\\u0074|\\\\u0054)(?:u|\\\\u0075|\\\\u0055)(?:r|\\\\u0072|\\\\u0052)(?:e|\\\\u0065|\\\\u0045)\\s*=#i';

    public function __construct(
        private readonly PublicOutputLeakPolicy $leakPolicy = new PublicOutputLeakPolicy,
    ) {}

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
     * This is the admin/authoring-leak check. For "does this response bake
     * in session-specific content that must not reach the shared cache" —
     * a different hazard, checked separately — see
     * {@see containsBakedCsrfToken()}/{@see detectBakedCsrfToken()}.
     */
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

        // The pre/code strip and the variant expansion are the two expensive
        // full-document passes; every detector below shares one computation.
        $strippedHtml = $this->stripPreCodeBlocks($html);
        $strippedHtmlVariants = $this->htmlVariants($strippedHtml);

        $unknownCapellAttribute = $this->detectUnknownCapellAttribute($strippedHtml);

        if ($unknownCapellAttribute !== null) {
            return $this->authoringMarkerDetection($unknownCapellAttribute);
        }

        $literalAuthoringMarker = $this->detectLiteralAuthoringMarker($strippedHtmlVariants);

        if ($literalAuthoringMarker !== null) {
            return $this->authoringMarkerDetection($literalAuthoringMarker);
        }

        $jsonMarker = $this->detectAuthoringJsonPayload($html);

        if ($jsonMarker !== null) {
            return $this->authoringMarkerDetection($jsonMarker);
        }

        $jsonLikeMarker = $this->detectAuthoringJsonLikeMarkup($strippedHtmlVariants);

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
     * A CSRF token (Laravel's `@csrf` directive: `<input type="hidden"
     * name="_token" value="...">`; `<meta name="csrf-token" content="...">`;
     * Livewire's `data-csrf="..."`, or Livewire's `livewireScriptConfig.csrf`
     * payload) is bound to whoever's session rendered
     * the response. Baking a real one into HTML that reaches the *shared* HTML
     * cache serves every later visitor a foreign token, breaking their own
     * submission (CAP-0216/CAP-0233). This is a cache-eligibility signal, not
     * an authoring-surface leak, so it is deliberately not part of
     * {@see detectAuthoringSurface()}: several public views legitimately
     * render a real, non-empty token today (not only fragment sub-requests —
     * ordinary full-page renders do too), and wiring this into the universal
     * render contract (thrown uncaught on every render) would 500 those
     * pages outright instead of merely excluding them from the shared cache.
     *
     * Requires a non-empty (after trimming) token attribute. The established,
     * already-shipped fix for this exact bug elsewhere in the product renders
     * an EMPTY placeholder token (`value=""` or `content=""`) and hydrates the
     * real value client-side from a dedicated, never-cached endpoint — that
     * pattern must never be flagged, or the entire public site would lose
     * HTML-cache eligibility.
     */
    public function containsBakedCsrfToken(string $html): bool
    {
        return $this->detectBakedCsrfToken($html) instanceof PublicHtmlSafetyDetectionData;
    }

    public function detectBakedCsrfToken(string $html): ?PublicHtmlSafetyDetectionData
    {
        if ($html === '' || (stripos($html, '_token') === false
            && stripos($html, 'csrf-token') === false
            && stripos($html, 'data-csrf') === false
            && stripos($html, 'livewireScriptConfig') === false)) {
            return null;
        }

        if ($this->containsLivewireScriptConfigCsrfToken($html)) {
            return $this->bakedCsrfTokenDetection('livewireScriptConfig.csrf');
        }

        // No stripPreCodeBlocks() pass here, unlike the authoring-marker
        // detectors below: a code sample can only reach this pattern by
        // rendering a literal, unescaped <input> tag inside <pre>/<code>,
        // which is not how documentation examples are written (they use
        // HTML-entity-escaped markup, which this pattern cannot match at
        // all — verified, not assumed).
        if (preg_match_all('#<[a-z][a-z0-9:-]*\\b[^>]*>#i', $html, $tagMatches) < 1) {
            return null;
        }

        foreach ($tagMatches[0] as $tag) {
            if ($this->isTagNamed($tag, 'input')
                && $this->attributeValueMatches($tag, 'name', '_token')
                && $this->hasNonEmptyAttributeValue($tag, 'value')) {
                return $this->bakedCsrfTokenDetection('name="_token"');
            }

            if ($this->isTagNamed($tag, 'meta')
                && $this->attributeValueMatches($tag, 'name', 'csrf-token')
                && $this->hasNonEmptyAttributeValue($tag, 'content')) {
                return $this->bakedCsrfTokenDetection('name="csrf-token"');
            }

            if ($this->hasNonEmptyAttributeValue($tag, 'data-csrf')) {
                return $this->bakedCsrfTokenDetection('data-csrf');
            }
        }

        return null;
    }

    private function isTagNamed(string $tag, string $name): bool
    {
        return preg_match('#^<' . preg_quote($name, '#') . '\\b#i', $tag) === 1;
    }

    private function attributeValueMatches(string $tag, string $attribute, string $expectedValue): bool
    {
        $value = $this->attributeValue($tag, $attribute);

        return $value !== null && strcasecmp($value, $expectedValue) === 0;
    }

    private function hasNonEmptyAttributeValue(string $tag, string $attribute): bool
    {
        $value = $this->attributeValue($tag, $attribute);

        return $value !== null && trim($value) !== '';
    }

    private function attributeValue(string $tag, string $attribute): ?string
    {
        $pattern = '#\\s' . preg_quote($attribute, '#') . '\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s"\'=<>`]+))#is';

        if (preg_match($pattern, $tag, $matches) !== 1) {
            return null;
        }

        foreach ([1, 2, 3] as $index) {
            if (array_key_exists($index, $matches) && $matches[$index] !== '') {
                return $matches[$index];
            }
        }

        return '';
    }

    private function containsLivewireScriptConfigCsrfToken(string $html): bool
    {
        return preg_match(
            '#<script\\b[^>]*>\\s*window\\.livewireScriptConfig\\s*=\\s*\\{(?:(?!</script>).)*?"csrf"\\s*:\\s*"([^"\\s][^"]*)"#is',
            $html,
        ) === 1;
    }

    private function bakedCsrfTokenDetection(string $matched): PublicHtmlSafetyDetectionData
    {
        return new PublicHtmlSafetyDetectionData(
            category: 'baked_csrf_token',
            // `matched` is logged (RecordPublicRenderContractEventAction)
            // and persisted to the database. A hardcoded literal here is
            // deliberate: the real match is the live CSRF token itself,
            // and that must never be written to logs or storage.
            matched: $matched,
            reason: 'Public HTML bakes a session-specific CSRF token into content that may reach the shared cache.',
        );
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
        foreach ($this->leakPolicy->authoringAttributes() as $attribute) {
            // The pattern can only match when the literal attribute occurs;
            // stripos is far cheaper than a regex pass over the document.
            if (stripos($html, $attribute) === false) {
                continue;
            }

            $pattern = '#<[^>]+\\s' . preg_quote($attribute, '#') . '(?:\\s|=|>)#i';

            if (preg_match($pattern, $html) === 1) {
                return $attribute;
            }
        }

        return null;
    }

    private function detectAuthoringClassOrId(string $html): ?string
    {
        foreach ($this->leakPolicy->authoringClassOrIdMarkers() as $marker) {
            if (stripos($html, $marker) === false) {
                continue;
            }

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
    private function stripPreCodeBlocks(string $html): string
    {
        return preg_replace('#<(pre|code)\\b[^>]*>.*?</\\1>#is', '', $html) ?? $html;
    }

    /**
     * Expects HTML already passed through {@see stripPreCodeBlocks()}.
     */
    private function detectUnknownCapellAttribute(string $html): ?string
    {
        // Fast path: sweep the whole document for `data-capell-*` names first.
        // When every candidate is on the runtime allowlist — the shape of every
        // clean public page — there is nothing to detect and the far more
        // expensive per-tag verification below never runs.
        if (preg_match_all('#\\b(data-capell-[a-z0-9-]+)#i', $html, $candidateMatches) < 1) {
            return null;
        }

        $unknownCandidateExists = array_any(array_unique($candidateMatches[1]), fn (string $candidate): bool => ! $this->isAllowedCapellRuntimeAttribute(strtolower($candidate)));

        if (! $unknownCandidateExists) {
            return null;
        }

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
        if (in_array($attribute, $this->leakPolicy->allowedCapellRuntimeAttributes(), true)) {
            return true;
        }

        return array_any($this->leakPolicy->allowedCapellRuntimeAttributePrefixes(), fn (string $prefix): bool => str_starts_with($attribute, $prefix));
    }

    /**
     * @param  list<string>  $htmlVariants  variants of pre/code-stripped HTML
     */
    private function detectLiteralAuthoringMarker(array $htmlVariants): ?string
    {
        foreach ($this->leakPolicy->authoringClassOrIdMarkers() as $marker) {
            foreach ($htmlVariants as $htmlVariant) {
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

            foreach ($this->leakPolicy->authoringJsonKeys() as $key) {
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

    /**
     * @param  list<string>  $htmlVariants  variants of pre/code-stripped HTML
     */
    private function detectAuthoringJsonLikeMarkup(array $htmlVariants): ?string
    {
        foreach ($this->leakPolicy->authoringJsonKeys() as $key) {
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
        if (stripos($html, $key) === false) {
            return false;
        }

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
        foreach ($this->leakPolicy->authoringSignedUrlJsonKeys() as $key) {
            foreach ($htmlVariants as $htmlVariant) {
                if (stripos($htmlVariant, $key) === false) {
                    continue;
                }

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

        return array_any($this->signedAdminUrlPatterns(), fn (string $pattern): bool => preg_match($pattern, $html) === 1);
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
        if (stripos($html, 'signature=') === false) {
            return false;
        }

        $adminPath = $this->adminPath();

        return stripos($html, '/' . $adminPath . '/') !== false
            || stripos($html, '\\/' . $adminPath . '\\/') !== false;
    }

    /**
     * @return iterable<string>
     */
    private function signedAdminUrlScanWindows(string $html): iterable
    {
        $offset = 0;

        while (preg_match(self::SIGNED_ADMIN_SIGNATURE_MARKER_PATTERN, $html, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
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
        // The regex only exists to catch \uXXXX-escaped spellings; a plain
        // literal occurrence is the overwhelmingly common case and stripos
        // answers it without a regex pass. The `\u` probe mirrors the escape
        // syntax the pattern's alternations accept.
        if (stripos($html, 'signature') === false && ! str_contains($html, '\\u')) {
            return false;
        }

        return preg_match(self::SIGNED_ADMIN_SIGNATURE_MARKER_PATTERN, $html) === 1;
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

        // html_entity_decode is an identity transform when no `&` occurs, and
        // the substring probe is far cheaper than the full decode pass.
        $decoded = str_contains($html, '&')
            ? html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $html;

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
