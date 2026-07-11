<?php

declare(strict_types=1);

use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ThemeManifestKey;

function makeManifestForKey(string $name, ?string $themeKey = null): CapellManifestData
{
    return CapellManifestData::fromArray(capellManifestV3Array(
        name: $name,
        surfaces: ['frontend'],
        overrides: [
            'kind' => 'theme',
            'themeKey' => $themeKey,
        ],
    ));
}

describe('ThemeManifestKey::fromPackageName', function (): void {
    it('derives the key from the segment after the final slash', function (): void {
        expect(ThemeManifestKey::fromPackageName('capell-app/seo-suite'))->toBe('seo-suite');
    });

    it('strips a trailing -theme suffix', function (): void {
        expect(ThemeManifestKey::fromPackageName('capell-app/foundation-theme'))->toBe('foundation');
    });

    it('uses only the last segment of a multi-slash name', function (): void {
        expect(ThemeManifestKey::fromPackageName('vendor/group/api-platform-theme'))->toBe('api-platform');
    });

    // Regression: strrpos() returns false with no slash; false + 1 === 1 used
    // to chop the first character ("mytheme-theme" -> "ytheme").
    it('keeps the whole name when there is no slash', function (): void {
        expect(ThemeManifestKey::fromPackageName('mytheme-theme'))->toBe('mytheme');
        expect(ThemeManifestKey::fromPackageName('foundation'))->toBe('foundation');
    });
});

describe('ThemeManifestKey::resolve', function (): void {
    it('prefers an explicit theme key over the derived one', function (): void {
        $manifest = makeManifestForKey('capell-app/foundation-theme', themeKey: 'default');

        expect(ThemeManifestKey::resolve($manifest))->toBe('default');
    });

    it('falls back to the derived key when no theme key is declared', function (): void {
        $manifest = makeManifestForKey('capell-app/foundation-theme');

        expect(ThemeManifestKey::resolve($manifest))->toBe('foundation');
    });

    it('treats an empty theme key as absent and derives from the name', function (): void {
        $manifest = makeManifestForKey('capell-app/theme-api-platform', themeKey: '');

        expect(ThemeManifestKey::resolve($manifest))->toBe('theme-api-platform');
    });
});
