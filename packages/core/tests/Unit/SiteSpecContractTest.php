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
        ->and($spec->theme->colors->primary)->toBe('#123456');
});

it('publishes a bounded schema matching the contract', function (): void {
    $schema = CapellSiteSpecSchema::toArray();

    expect($schema['properties']['pages']['minItems'])->toBe(CapellSiteSpecConstraints::MIN_PAGES)
        ->and($schema['properties']['pages']['maxItems'])->toBe(CapellSiteSpecConstraints::MAX_PAGES)
        ->and($schema['properties']['pages']['items']['properties']['slug']['pattern'])->toBe(CapellSiteSpecConstraints::SLUG_PATTERN)
        ->and($schema['properties']['theme']['properties']['colors']['properties']['primary']['pattern'])->toBe(CapellSiteSpecConstraints::HEX_COLOUR_PATTERN)
        ->and($schema['properties']['pages']['items']['properties']['sections']['items']['properties']['content']['maxLength'])
        ->toBe(CapellSiteSpecConstraints::MAX_SECTION_CONTENT_LENGTH);
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
