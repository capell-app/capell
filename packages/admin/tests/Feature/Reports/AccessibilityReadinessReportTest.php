<?php

declare(strict_types=1);

use Capell\Admin\Actions\Reports\BuildAccessibilityReadinessReportAction;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\Reports\AccessibilityReadinessReport;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

it('reports required translations localized urls media metadata and decorative intent', function (): void {
    [$site, $english, $french] = accessibilitySiteFixture();
    $page = Page::factory()->site($site)->withTranslations($english)->create();
    PageUrl::factory()->site($site)->language($english)->page($page)->create();
    PageUrl::factory()->site($site)->language($french)->page($page)->create(['status' => false]);

    $informative = Media::factory()->model($page->translations()->firstOrFail())->create(['name' => 'Editorial hero']);
    Translation::factory()->translatable($informative)->language($english)->create([
        'meta' => ['decorative' => false],
    ]);
    $intentMissing = Media::factory()->model($page->translations()->firstOrFail())->create(['name' => 'Unclassified image']);
    Translation::factory()->translatable($intentMissing)->language($english)->create([
        'meta' => ['alt' => 'A useful description'],
    ]);
    $decorative = Media::factory()->model($page->translations()->firstOrFail())->create(['name' => 'Decoration']);

    foreach ([$english, $french] as $language) {
        Translation::factory()->translatable($decorative)->language($language)->create([
            'meta' => ['decorative' => true],
        ]);
    }

    $snapshot = BuildAccessibilityReadinessReportAction::run($site);
    $ids = collect($snapshot->findings)->pluck('id');

    expect($ids)->toContain(
        'accessibility.translation.required-missing',
        'accessibility.url.localized-broken',
        'accessibility.media.localized-alt-missing',
        'accessibility.media.localized-caption-missing',
        'accessibility.media.localized-credit-missing',
        'accessibility.media.decorative-intent-missing',
    )->and($snapshot->findings)->each(
        fn ($finding) => $finding->evidence->not->toBeEmpty(),
    );
});

it('passes complete multilingual content and registers the report surface', function (): void {
    [$site, $english, $french] = accessibilitySiteFixture();
    $page = Page::factory()->site($site)->withTranslations([$english, $french])->create();

    foreach ([$english, $french] as $language) {
        PageUrl::factory()->site($site)->language($language)->page($page)->create();
    }

    $media = Media::factory()->model($page->translations()->firstOrFail())->create(['name' => 'Complete image']);

    foreach ([$english, $french] as $language) {
        Translation::factory()->translatable($media)->language($language)->create([
            'meta' => [
                'decorative' => false,
                'alt' => 'Localized alternative text',
                'caption' => 'Localized caption',
                'credit' => 'Capell Studio',
            ],
        ]);
    }

    $snapshot = BuildAccessibilityReadinessReportAction::run($site);
    $registry = CapellAdmin::getReportRegistry();

    expect($snapshot->findings)->toBe([])
        ->and($snapshot->key)->toBe(AccessibilityReadinessReport::REPORT_KEY)
        ->and($registry->has(AccessibilityReadinessReport::REPORT_KEY))->toBeTrue()
        ->and($registry->get(AccessibilityReadinessReport::REPORT_KEY)?->pageClass)->toBe(AccessibilityReadinessReport::class);
});

/** @return array{Site, Language, Language} */
function accessibilitySiteFixture(): array
{
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();
    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $french])
        ->create([
            'admin' => ['require_translations' => ['en', 'fr']],
        ]);

    return [$site, $english, $french];
}
