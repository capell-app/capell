<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Capell\Core\ThemeStudio\Data\GenericSectionData;

it('exposes the type as the renderer key', function (): void {
    $section = new GenericSectionData('project-showcase', ['heading' => 'Work']);

    expect($section)->toBeInstanceOf(ThemeSection::class)
        ->and($section->key())->toBe('project-showcase');
});

it('defaults the fallback key to content-listing and allows an override', function (): void {
    expect(new GenericSectionData('case-study')->fallbackKey())->toBe('content-listing')
        ->and(new GenericSectionData('case-study', [], 'proof')->fallbackKey())->toBe('proof')
        ->and(new GenericSectionData('case-study', [], null)->fallbackKey())->toBeNull();
});

it('reads carried payload through object property access', function (): void {
    $section = new GenericSectionData('team', [
        'heading' => 'The team',
        'people' => [['name' => 'Priya', 'role' => 'Founder']],
    ]);

    expect($section->heading)->toBe('The team')
        ->and($section->people)->toBe([['name' => 'Priya', 'role' => 'Founder']]);
});

it('returns null for missing keys so views degrade gracefully', function (): void {
    $section = new GenericSectionData('services', ['heading' => 'Services']);

    expect($section->summary)->toBeNull()
        ->and(isset($section->heading))->toBeTrue()
        ->and(isset($section->summary))->toBeFalse()
        ->and($section->summary ?? 'fallback')->toBe('fallback');
});

it('spreads the payload and itself into view data', function (): void {
    $section = new GenericSectionData('client-logos', ['heading' => 'Trusted by', 'logos' => [['name' => 'Acme']]]);

    $viewData = $section->toViewData();

    expect($viewData['heading'])->toBe('Trusted by')
        ->and($viewData['logos'])->toBe([['name' => 'Acme']])
        ->and($viewData['section'])->toBe($section);
});
