<?php

declare(strict_types=1);

use Capell\Core\Actions\SanitizeSiteSpecSectionHtmlAction;
use Capell\Core\Actions\ValidateSiteSpecAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Support\CapellSiteSpecConstraints;
use Capell\Core\Support\CapellSiteSpecSchema;

function validSiteSpecPayload(): array
{
    return [
        'site' => ['name' => 'Harbour Books'],
        'theme' => ['key' => 'default', 'colors' => ['primary' => '#123456']],
        'pages' => [[
            'name' => 'Home',
            'slug' => 'home',
            'title' => 'Welcome',
            'pageType' => 'page',
            'sections' => [['type' => 'content', 'content' => '<p>Hello</p>']],
        ]],
    ];
}

it('normalizes the neutral site spec contract', function (): void {
    $spec = CapellSiteSpecData::validateAndCreate(validSiteSpecPayload());

    expect($spec->language->locale)->toBe('en_GB')
        ->and($spec->pages[0]->resolvedUrl())->toBe('/home')
        ->and($spec->theme->colors->primary)->toBe('#123456')
        ->and($spec->navigations)->toBe([])
        ->and($spec->media->hasRemoteAssets())->toBeFalse()
        ->and($spec->extensions)->toBe([]);
});

it('normalizes deterministic navigation media and extension inputs', function (): void {
    $payload = validSiteSpecPayload();
    $payload['navigations'] = [[
        'key' => 'main',
        'name' => 'Main navigation',
        'pageSlugs' => ['home'],
    ]];
    $payload['media'] = [
        'sourceUrl' => 'https://example.com',
        'logo' => 'https://example.com/logo.png',
        'images' => ['home' => 'https://example.com/home.png'],
    ];
    $payload['extensions'] = ['capell-app/navigation'];

    $spec = CapellSiteSpecData::validateAndCreate($payload);

    expect($spec->navigations[0]->key)->toBe('main')
        ->and($spec->navigations[0]->pageSlugs)->toBe(['home'])
        ->and($spec->media->logo)->toBe('https://example.com/logo.png')
        ->and($spec->media->images)->toBe(['home' => 'https://example.com/home.png'])
        ->and($spec->extensions)->toBe(['capell-app/navigation']);
});

it('publishes a bounded schema matching the contract', function (): void {
    $schema = CapellSiteSpecSchema::toArray();

    expect($schema['properties']['pages']['minItems'])->toBe(CapellSiteSpecConstraints::MIN_PAGES)
        ->and($schema['properties']['pages']['maxItems'])->toBe(CapellSiteSpecConstraints::MAX_PAGES)
        ->and($schema['properties']['pages']['items']['properties']['slug']['pattern'])->toBe(CapellSiteSpecConstraints::SLUG_PATTERN)
        ->and($schema['properties']['theme']['properties']['colors']['properties']['primary']['pattern'])->toBe(CapellSiteSpecConstraints::HEX_COLOUR_PATTERN)
        ->and($schema['properties']['pages']['items']['properties']['sections']['items']['properties']['content']['maxLength'])
        ->toBe(CapellSiteSpecConstraints::MAX_SECTION_CONTENT_LENGTH)
        ->and($schema['properties']['navigations']['maxItems'])->toBe(CapellSiteSpecConstraints::MAX_NAVIGATIONS)
        ->and($schema['properties']['media']['properties']['images']['maxProperties'])->toBe(CapellSiteSpecConstraints::MAX_MEDIA_IMAGES)
        ->and($schema['properties']['extensions']['maxItems'])->toBe(CapellSiteSpecConstraints::MAX_EXTENSIONS);
});

it('sanitizes unsafe section html and rejects oversized input', function (): void {
    expect(SanitizeSiteSpecSectionHtmlAction::run('<p class="lead">Safe</p><script>alert(1)</script>'))
        ->toBe('<p class="lead">Safe</p>');

    SanitizeSiteSpecSectionHtmlAction::run(str_repeat('a', 20001));
})->throws(InvalidArgumentException::class);

it('validates catalogue keys slugs colours and content limits', function (): void {
    $valid = ValidateSiteSpecAction::run(validSiteSpecPayload(), ['default'], ['page'], ['content']);

    $invalidPayload = validSiteSpecPayload();
    $invalidPayload['theme']['key'] = 'missing';
    $invalidPayload['theme']['colors']['primary'] = 'red';
    $invalidPayload['pages'][] = array_merge($invalidPayload['pages'][0], ['slug' => 'home']);
    $invalidPayload['pages'][0]['sections'][0]['type'] = 'unknown';
    $invalid = ValidateSiteSpecAction::run($invalidPayload, ['default'], ['page'], ['content']);

    expect($valid['valid'])->toBeTrue()
        ->and($valid['normalized'])->not->toBeNull()
        ->and($invalid['valid'])->toBeFalse()
        ->and($invalid['errors'])->toHaveKeys(['theme.key', 'theme.colors.primary', 'pages.1.slug', 'pages.0.sections.0.type']);
});

it('enforces the page and total content limits', function (): void {
    $payload = validSiteSpecPayload();
    $payload['pages'] = array_fill(0, 16, $payload['pages'][0]);
    $payload['pages'][0]['sections'][0]['content'] = str_repeat('x', 20001);

    $result = ValidateSiteSpecAction::run($payload, ['default'], ['page'], ['content']);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toHaveKeys(['pages', 'pages.0.sections.0.content']);
});

it('rejects excessive aggregate section content', function (): void {
    $payload = validSiteSpecPayload();
    $payload['pages'][0]['sections'] = array_fill(0, 11, [
        'type' => 'content',
        'content' => str_repeat('x', 20000),
    ]);

    $result = ValidateSiteSpecAction::run($payload, ['default'], ['page'], ['content']);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toHaveKey('pages');
});

it('requires explicit acknowledgement for public visibility', function (): void {
    $payload = validSiteSpecPayload();
    $payload['initialVisibility'] = 'public';

    $result = ValidateSiteSpecAction::run($payload, ['default'], ['page'], ['content']);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toHaveKey('acknowledgePublic');
});

it('validates cross references and remote media requirements', function (): void {
    $payload = validSiteSpecPayload();
    $payload['navigations'] = [[
        'key' => 'main',
        'pageSlugs' => ['missing-page'],
    ]];
    $payload['media'] = [
        'logo' => 'https://example.com/logo.png',
        'images' => ['missing-page' => 'https://example.com/image.png'],
    ];
    $payload['extensions'] = ['Not a Composer package'];

    $result = ValidateSiteSpecAction::run($payload, ['default'], ['page'], ['content']);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->toHaveKeys([
            'navigations.0.pageSlugs.0',
            'media.sourceUrl',
            'media.images.missing-page',
            'extensions.0',
        ]);
});

it('allows a page in different navigations but rejects duplicates within one navigation', function (): void {
    $payload = validSiteSpecPayload();
    $payload['navigations'] = [
        ['key' => 'main', 'pageSlugs' => ['home']],
        ['key' => 'footer', 'pageSlugs' => ['home']],
    ];

    $valid = ValidateSiteSpecAction::run($payload, ['default'], ['page'], ['content']);

    $payload['navigations'][0]['pageSlugs'] = ['home', 'home'];
    $invalid = ValidateSiteSpecAction::run($payload, ['default'], ['page'], ['content']);

    expect($valid['valid'])->toBeTrue()
        ->and($invalid['valid'])->toBeFalse()
        ->and($invalid['errors'])->toHaveKey('navigations.0.pageSlugs.1');
});
