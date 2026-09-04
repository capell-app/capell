<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(CreatesAdminUser::class)
    ->group('layout');

it('shows the saved content impact before a layout is saved', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->english()->createOne();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language, siteDomainData: [
            'domain' => 'example.test',
            'scheme' => 'http',
            'path' => null,
        ])
        ->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    $page = Page::factory()->site($site)->layout($layout)->createOne(['name' => 'Shared landing']);

    PageUrl::factory()->page($page)->site($site)->language($language)->createOne(['url' => '/landing']);

    Livewire::test(EditLayout::class, [
        'record' => $layout->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.layout_impact_preview'))
        ->assertSee(__('capell-admin::generic.layout_impact_preview_exact'))
        ->assertSee('Shared landing')
        ->assertSee('http://example.test/landing')
        ->assertSee(__('capell-admin::generic.layout_impact_preview_cache'))
        ->assertSee(__('capell-admin::generic.layout_impact_preview_reversibility'));
});

it('rejects a save when another editor changes the previewed impact', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    $page = Page::factory()->site($site)->layout($layout)->createOne(['name' => 'Original page']);
    PageUrl::factory()->page($page)->site($site)->language($language)->createOne(['url' => '/landing']);

    $editor = Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()]);

    $page->update(['name' => 'Changed elsewhere']);

    $editor
        ->fillForm(['name' => 'Editor change'])
        ->call('save')
        ->assertHasFormErrors([
            'impactPlanFingerprint' => __('capell-admin::message.impact_plan_stale'),
        ]);

    expect($layout->refresh()->name)->not->toBe('Editor change');
});

it('records a reconciliation after a layout save', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->english()->createOne();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language, siteDomainData: [
            'domain' => 'example.test',
            'scheme' => 'http',
            'path' => null,
        ])
        ->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    $page = Page::factory()->site($site)->layout($layout)->createOne(['name' => 'Reconciled page']);
    PageUrl::factory()->page($page)->site($site)->language($language)->createOne(['url' => '/landing']);

    Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
        ->fillForm(['name' => 'Saved layout'])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()
        ->where('log_name', 'content-impact')
        ->where('subject_id', $layout->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($activity->event)->toBe('reconciled')
        ->and($activity->properties?->get('predictedSurfaces'))->toBe([
            'url:http://example.test/landing',
        ])
        ->and($activity->properties?->get('actualSurfaces'))->toBe([
            'url:http://example.test/landing',
        ])
        ->and($activity->properties?->get('drifted'))->toBeFalse();
});
