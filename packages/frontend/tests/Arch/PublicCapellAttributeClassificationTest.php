<?php

declare(strict_types=1);

/**
 * Single source of truth for `data-capell-*` attribute safety.
 *
 * The public render scanner (PublicHtmlSafetyInspector) allows a set of
 * public-safe `data-capell-*` families and flags everything else as an
 * authoring/leak surface. Attributes are emitted from BOTH Blade views and PHP,
 * so a new one can appear without the Blade-source arch test ever seeing it —
 * exactly how `data-capell-theme-tokens` once slipped through and 500'd a page.
 *
 * This test discovers every `data-capell-*` attribute used anywhere in the
 * frontend package and asserts each is explicitly classified as public-safe or
 * authoring. A new, unclassified attribute fails here, forcing an intentional
 * decision instead of a runtime surprise.
 */
it('classifies every emitted data-capell-* attribute as public-safe or authoring', function (): void {
    $discovered = discoverCapellDataAttributes();

    // Guard against a vacuous pass if the scan path ever breaks: the frontend
    // is known to emit well over a dozen data-capell-* attributes.
    expect(count($discovered))->toBeGreaterThan(10);

    $unclassified = [];

    foreach ($discovered as $attribute) {
        if (isPublicSafeCapellAttribute($attribute)) {
            continue;
        }

        if (in_array($attribute, knownAuthoringCapellAttributes(), true)) {
            continue;
        }

        $unclassified[] = $attribute;
    }

    sort($unclassified);

    expect($unclassified)->toBe(
        [],
        'Found unclassified data-capell-* attribute(s). Add each to the public-safe '
        . 'families in PublicHtmlSafetyInspector (if anonymous-safe) or to '
        . 'knownAuthoringCapellAttributes() (if admin-only): ' . implode(', ', $unclassified),
    );
});

it('treats known authoring data-capell attributes as NOT public-safe', function (): void {
    foreach (knownAuthoringCapellAttributes() as $attribute) {
        expect(isPublicSafeCapellAttribute($attribute))->toBeFalse(
            $attribute . ' is authoring-only and must never pass the public-safe check.',
        );
    }
});

/**
 * Public-safe `data-capell-*` families. KEEP IN SYNC with
 * PublicHtmlSafetyInspector::ALLOWED_CAPELL_RUNTIME_ATTRIBUTE_PREFIXES and
 * ::ALLOWED_CAPELL_RUNTIME_ATTRIBUTES.
 */
function isPublicSafeCapellAttribute(string $attribute): bool
{
    $exact = ['data-capell-interaction'];

    if (in_array($attribute, $exact, true)) {
        return true;
    }

    $families = [
        'data-capell-widget-',
        'data-capell-interaction-',
        'data-capell-theme-',
        'data-capell-insights-',
    ];

    return array_any($families, fn ($prefix): bool => str_starts_with($attribute, (string) $prefix));
}

/**
 * `data-capell-*` attributes that are intentionally authoring/admin-only. They
 * are emitted only for admin audiences and MUST be flagged if they reach public
 * output. KEEP IN SYNC with PublicHtmlSafetyInspector::AUTHORING_ATTRIBUTES.
 *
 * @return list<string>
 */
function knownAuthoringCapellAttributes(): array
{
    return [
        'data-capell-authoring',
        'data-capell-editable',
        'data-capell-editor',
        'data-capell-editor-url',
        'data-capell-package',
    ];
}

/**
 * @return list<string>
 */
function discoverCapellDataAttributes(): array
{
    $roots = [
        dirname(__DIR__, 2) . '/src',
        dirname(__DIR__, 2) . '/resources',
    ];

    $attributes = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            // The Support/Security layer *defines and documents* the attribute
            // allow/deny lists (including example names in comments); it does not
            // *emit* attributes into HTML. Scanning it would classify its own
            // documentation examples as emitted attributes.
            if (str_contains(str_replace('\\', '/', $file->getPathname()), '/Support/Security/')) {
                continue;
            }

            $extension = strtolower((string) $file->getExtension());

            if (! in_array($extension, ['php', 'blade', 'js'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/data-capell-[a-z0-9-]+/i', $contents, $matches) < 1) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $attributes[strtolower($match)] = true;
            }
        }
    }

    return array_keys($attributes);
}
