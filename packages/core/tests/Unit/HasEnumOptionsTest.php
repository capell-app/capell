<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\CacheFrequency;
use Capell\Core\Enums\ImageSourcePreset;
use Capell\Core\Enums\PresentationLazyPolicy;
use Capell\Core\Enums\UrlScheme;

describe('HasEnumOptions', function (): void {
    it('returns all options as value => label pairs', function (): void {
        expect(BlueprintSubjectEnum::options())->toBe([
            'page' => 'Page',
            'site' => 'Site',
            'theme' => 'Theme',
        ]);
    });

    it('includes every enum case as a key', function (): void {
        $expectedValues = array_map(fn (BlueprintSubjectEnum $case): string => $case->value, BlueprintSubjectEnum::cases());

        expect(array_keys(BlueprintSubjectEnum::options()))->toBe($expectedValues);
    });

    it('returns non-empty options', function (): void {
        expect(BlueprintSubjectEnum::options())->not->toBeEmpty();
    });

    it('returns the same array on repeated calls via static cache', function (): void {
        $first = BlueprintSubjectEnum::options();
        $second = BlueprintSubjectEnum::options();

        expect($first)->toBe($second);
    });

    it('exposes complete option maps for static Filament choice domains', function (): void {
        expect(array_map(
            fn (CacheFrequency $frequency): array => [$frequency->value, $frequency->getLabel()],
            CacheFrequency::cases(),
        ))->toBe([
            ['always', 'Always'],
        ])->and(array_map(
            fn (UrlScheme $scheme): array => [$scheme->value, $scheme->getLabel()],
            UrlScheme::cases(),
        ))->toBe([
            ['http', 'HTTP'],
            ['https', 'HTTPS'],
        ])->and(PresentationLazyPolicy::options())->toBe([
            'server-rendered' => 'Server rendered',
            'visible' => 'Visible',
            'interaction' => 'Interaction',
            'idle' => 'Idle',
        ])->and(ImageSourcePreset::options())->toBe([
            'all' => 'URL, upload, or media library',
            'url_only' => 'URL only',
            'upload_only' => 'Upload only',
            'media_only' => 'Media library only',
            'url_media' => 'URL or media library',
            'upload_media' => 'Upload or media library',
        ]);
    });
});
