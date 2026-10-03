<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Contracts\Extenders\PublishPanelExtender;
use Capell\Admin\Data\PagePublishStateData;
use Capell\Admin\Enums\PublishPanelStatusEnum;
use Capell\Admin\Filament\Livewire\PublishStatusPanel;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

function panelPagePermission(string $affix): string
{
    $permissions = Utils::getConfig()->permissions;

    return FilamentShield::defaultPermissionKeyBuilder(
        affix: $affix,
        separator: $permissions->separator,
        subject: 'Page',
        case: $permissions->case,
    );
}

function panelFor(Page $page): Testable
{
    return Livewire::test(PublishStatusPanel::class, ['recordClass' => Page::class, 'recordId' => $page->getKey()]);
}

it('reports the published status for a live page', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    $status = panelFor($page)->instance()->viewData()->status;

    expect($status)->toBe(PublishPanelStatusEnum::published);
});

it('reports the scheduled status for a future publish date within the sentinel boundary', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->addWeek()]);

    expect(panelFor($page)->instance()->viewData()->status)->toBe(PublishPanelStatusEnum::scheduled);
});

it('reports the draft status for the far-future sentinel, not scheduled', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => PagePublishSentinel::draftValue()]);

    expect(panelFor($page)->instance()->viewData()->status)->toBe(PublishPanelStatusEnum::draft);
});

it('reports the expired status for a past unpublish date', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subMonth(), 'visible_until' => now()->subDay()]);

    expect(panelFor($page)->instance()->viewData()->status)->toBe(PublishPanelStatusEnum::expired);
});

it('publishes immediately via the publishNow action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => PagePublishSentinel::draftValue()]);

    panelFor($page)->callAction('publishNow');

    expect($page->fresh()->isPending())->toBeFalse()
        ->and($page->fresh()->visible_from?->isFuture())->toBeFalse();
});

it('schedules a future publish via the schedulePublish action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $when = now()->addWeek()->startOfMinute();

    panelFor($page)->callAction('schedulePublish', ['publish_at' => $when->toDateTimeString()]);

    expect($page->fresh()->visible_from?->isFuture())->toBeTrue();
});

it('reverts to draft via the revertToDraft action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    panelFor($page)->callAction('revertToDraft');

    expect(PagePublishSentinel::isDraftValue($page->fresh()->visible_from))->toBeTrue();
});

it('unpublishes a live page via the unpublish action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    panelFor($page)->callAction('unpublish');

    expect($page->fresh()->isExpired())->toBeTrue();
});

it('shows unpublish only for live pages', function (): void {
    test()->actingAsAdmin();
    $live = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
    $expired = Page::factory()->create(['visible_from' => now()->subWeek(), 'visible_until' => now()->subDay()]);

    panelFor($live)->assertActionVisible('unpublish');
    panelFor($expired)->assertActionHidden('unpublish');
});

it('shows cancel scheduled unpublish only when a future unpublish date is set', function (): void {
    test()->actingAsAdmin();
    $scheduled = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => now()->addWeek()]);
    $live = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    panelFor($scheduled)->assertActionVisible('cancelScheduledUnpublish');
    panelFor($live)->assertActionHidden('cancelScheduledUnpublish');
});

it('cancels a scheduled unpublish via the cancelScheduledUnpublish action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => now()->addWeek()]);

    panelFor($page)->callAction('cancelScheduledUnpublish');

    expect($page->fresh()->visible_until)->toBeNull();
});

it('schedules a future unpublish via the setExpiry action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
    $when = now()->addWeek()->startOfMinute();

    panelFor($page)->callAction('setExpiry', ['unpublish_at' => $when->toDateTimeString()]);

    expect($page->fresh()->visible_until?->isFuture())->toBeTrue();
});

it('hides every management action from a user who cannot update the page', function (): void {
    Permission::findOrCreate(panelPagePermission('update'));
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $user = test()->createUser();
    $user->assignedSiteIds = collect([(int) $page->site_id]);

    test()->actingAs($user);

    panelFor($page)
        ->assertActionHidden('publishNow')
        ->assertActionHidden('unpublish')
        ->assertActionHidden('revertToDraft');
});

it('refreshes the existing panel when its parent saves and publishes without a reload', function (): void {
    test()->actingAsAdmin();
    $site = Site::factory()->hasSiteDomains()->create();
    $page = Page::factory()->site($site)->home()->create([
        'visible_from' => PagePublishSentinel::draftValue(),
        'visible_until' => null,
    ]);
    foreach ($site->siteDomains as $domain) {
        $page->translations()->save(Translation::factory()->make([
            'language_id' => $domain->language_id,
            'title' => 'First page',
        ]));
    }

    $panel = panelFor($page);
    $componentId = $panel->instance()->getId();
    expect($panel->instance()->viewData->isDraft())->toBeTrue();
    $panel->assertSee(__('capell-admin::reports.publishing_readiness_public_effect_not_visible'));

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('page-editor-saved', pageId: (int) $page->getKey());

    expect($page->refresh()->publishVisibilityState()->value)->toBe('published');
    $panel->dispatch('page-editor-saved', pageId: (int) $page->getKey())
        ->assertSee(__('capell-admin::reports.publishing_readiness_public_effect_visible'));
    expect($panel->instance()->getId())->toBe($componentId)
        ->and($panel->instance()->viewData->isLive())->toBeTrue()
        ->and($panel->instance()->readiness->publicEligible)->toBeTrue();
});

it('invalidates every computed projection for a matching saved page', function (): void {
    test()->actingAsAdmin();
    $extender = new class implements PublishPanelExtender
    {
        #[Override]
        public function extendPanel(PagePublishStateData $state): string
        {
            return $state->isDraft ? 'Draft extension' : 'Live extension';
        }
    };
    app()->instance('saved-page-panel-extender', $extender);
    app()->tag('saved-page-panel-extender', PublishPanelExtender::TAG);

    $page = Page::factory()->create(['visible_from' => PagePublishSentinel::draftValue()]);
    $component = panelFor($page)->instance();
    $view = $component->viewData;
    $readiness = $component->readiness;
    expect($component->extensions)->toContain('Draft extension');

    $page->update(['visible_from' => null, 'visible_until' => null]);
    $component->refreshAfterPageSaved((int) $page->getKey());

    expect($component->viewData)->not->toBe($view)
        ->and($component->viewData->isLive())->toBeTrue()
        ->and($component->readiness)->not->toBe($readiness)
        ->and($component->readiness->currentState->value)->toBe('published')
        ->and($component->extensions)->toContain('Live extension')->not->toContain('Draft extension');
});

it('ignores a save event belonging to another page', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => PagePublishSentinel::draftValue()]);
    $component = panelFor($page)->instance();
    $view = $component->viewData;
    $readiness = $component->readiness;
    $page->update(['visible_from' => null]);

    $component->refreshAfterPageSaved((int) $page->getKey() + 1);

    expect($component->viewData)->toBe($view)
        ->and($component->readiness)->toBe($readiness);
});

it('shows an unknown publication date honestly for an immediately published or expired page', function (bool $expired): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create([
        'visible_from' => null,
        'visible_until' => $expired ? now()->subDay() : null,
    ]);
    $panel = panelFor($page)
        ->assertSee('Date not recorded')
        ->assertDontSee(__('capell-admin::publish_panel.not_published'));

    expect($panel->instance()->viewData->publishedAt)->toBeNull()
        ->and($panel->instance()->viewData->isExpired())->toBe($expired)
        ->and($panel->instance()->viewData->isLive())->toBe(! $expired);
})->with([false, true]);

it('refreshes cached readiness when the panel itself publishes', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => PagePublishSentinel::draftValue()]);
    $component = panelFor($page)->instance();
    $before = $component->readiness;
    expect($before->currentState->value)->toBe('draft');

    $component->publishNowAction()->call();

    expect($page->refresh()->publishVisibilityState()->value)->toBe('published')
        ->and($component->readiness)->not->toBe($before)
        ->and($component->readiness->currentState->value)->toBe('published');
});

it('retains unpublished date wording for drafts and scheduled pages', function (bool $scheduled): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create([
        'visible_from' => $scheduled ? now()->addDay() : PagePublishSentinel::draftValue(),
        'visible_until' => null,
    ]);

    panelFor($page)
        ->assertSee(__('capell-admin::publish_panel.not_published'))
        ->assertDontSee('Date not recorded');
})->with([false, true]);

it('retains the recorded publication date for a dated live page', function (): void {
    test()->actingAsAdmin();
    $publishedAt = now()->subDay()->startOfMinute();
    $page = Page::factory()->create(['visible_from' => $publishedAt, 'visible_until' => null]);

    panelFor($page)
        ->assertSee($publishedAt->translatedFormat('j M Y, H:i'))
        ->assertDontSee('Date not recorded')
        ->assertDontSee(__('capell-admin::publish_panel.not_published'));
});
