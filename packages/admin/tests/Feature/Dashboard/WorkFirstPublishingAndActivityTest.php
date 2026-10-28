<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildWorkFirstActivityAction;
use Capell\Admin\Actions\Dashboard\BuildWorkFirstAttentionAction;
use Capell\Admin\Actions\Dashboard\BuildWorkFirstPublishingAction;
use Capell\Admin\Data\Dashboard\DashboardPublicationItemData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PublishingHealthWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\TeamActivityWidget;
use Capell\Core\Contracts\Publishing\PublicationReadinessContributor;
use Capell\Core\Data\Publishing\PublicationReadinessCheckData;
use Capell\Core\Data\Publishing\PublicationReadinessContextData;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Publishing\PublicationReadinessRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Sinnbeck\DomAssertions\TestHtml;
use Spatie\Activitylog\Models\Activity;

uses(CreatesAdminUser::class);

it('shows scoped upcoming readiness with exact site times and a seven day window', function (): void {
    $this->actingAsAdmin();
    $site = Site::factory()->create();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    config()->set('capell-admin.dashboard.work_first.site_timezones', [$site->uuid => 'Europe/London']);

    $ready = Page::withoutEvents(fn () => Page::factory()->create(['site_id' => $site->id, 'visible_from' => now()->addDays(2)]));
    Translation::withoutEvents(fn () => Translation::factory()->translatable($ready)->create(['language_id' => $site->language_id]));
    PageUrl::withoutEvents(fn () => PageUrl::query()->create([
        'language_id' => $site->language_id, 'site_id' => $site->id, 'status' => true,
        'url' => '/scheduled-ready', 'pageable_id' => $ready->id, 'pageable_type' => $ready->getMorphClass(),
    ]));
    $blocked = Page::withoutEvents(fn () => Page::factory()->create(['site_id' => $site->id, 'visible_from' => now()->addDays(3)]));
    Page::withoutEvents(fn () => Page::factory()->create(['site_id' => $site->id, 'visible_from' => now()->addDays(9)]));
    Page::withoutEvents(fn () => Page::factory()->create(['visible_from' => now()->addDay()]));

    $items = resolve(BuildWorkFirstPublishingAction::class)->handle($this->authenticatedUser(), $site->id, null);

    expect($items)->toHaveCount(2)
        ->and($items[0]->pageId)->toBe($ready->id)
        ->and($items[0]->readiness)->toBe('ready')
        ->and($items[0]->timezone)->toBe('Europe/London')
        ->and($items[1]->pageId)->toBe($blocked->id)
        ->and($items[1]->readiness)->toBe('blocked');
    Livewire::test(PublishingHealthWidget::class, ['pageFilters' => ['site_id' => $site->id]])
        ->assertOk()->assertSee('Europe/London')->assertSee('Ready')->assertSee('Blocked');
});

it('groups repeated human content changes while excluding system and irrelevant site events', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $page = Page::withoutEvents(fn () => Page::factory()->create());
    $other = Page::withoutEvents(fn () => Page::factory()->create());
    Activity::query()->delete();
    foreach ([[$page, $actor, 'name'], [$page, $actor, 'name'], [$page, null, 'name'], [$page, $actor, 'updated_at'], [$other, $actor, 'name']] as [$subject, $causer, $field]) {
        Activity::query()->create([
            'log_name' => 'default', 'description' => 'updated', 'event' => 'updated',
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id,
            'causer_type' => $causer?->getMorphClass(), 'causer_id' => $causer?->id,
            'properties' => ['attributes' => [$field => 'Changed'], 'old' => [$field => 'Previous']],
            'created_at' => now(),
        ]);
    }

    $items = resolve(BuildWorkFirstActivityAction::class)->handle($actor, $page->site_id, null);

    expect($items)->toHaveCount(1)
        ->and($items[0]->title)->toBe($page->name)
        ->and($items[0]->changes)->toBe(2);
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    Livewire::test(TeamActivityWidget::class, ['pageFilters' => ['site_id' => $page->site_id]])
        ->assertOk()->assertSee($page->name)->assertSee('2 changes grouped');
});

it('does not fetch publishing or activity data when their widget switches are off', function (): void {
    $this->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    config()->set('capell-admin.dashboard.work_first.widgets', ['publishing_health' => false, 'team_activity' => false]);

    expect((new PublishingHealthWidget)->items())->toBe([])
        ->and((new TeamActivityWidget)->items())->toBe([]);
});

it('includes registered publication blockers in upcoming readiness and site attention', function (): void {
    $this->actingAsAdmin();
    $site = Site::factory()->create();
    $page = Page::withoutEvents(fn () => Page::factory()->create(['site_id' => $site->id, 'visible_from' => now()->addDay()]));
    Translation::withoutEvents(fn () => Translation::factory()->translatable($page)->create(['language_id' => $site->language_id]));
    resolve(PublicationReadinessRegistry::class)->register(new class implements PublicationReadinessContributor
    {
        #[Override]
        public function supports(Model&Publishable $record): bool
        {
            return true;
        }

        /** @return list<PublicationReadinessCheckData> */
        #[Override]
        public function checks(Model&Publishable $record, PublicationReadinessContextData $context): array
        {
            return [new PublicationReadinessCheckData('test.required-review')];
        }
    });
    $actor = $this->authenticatedUser();
    $upcoming = resolve(BuildWorkFirstPublishingAction::class)->handle($actor, $site->id, null);
    $attention = resolve(BuildWorkFirstAttentionAction::class)->handle($actor, $site->id, null);

    expect($upcoming[0]->readiness)->toBe('blocked')
        ->and($upcoming[0]->reasons)->toContain('publication_check')
        ->and($attention[0]->reasons)->toContain('publication_check');
});

it('keeps blocked publication diagnostics reachable independently of the attention preview', function (): void {
    $this->actingAsAdmin();
    $item = new DashboardPublicationItemData(
        key: 'blocked-publication',
        pageId: 1,
        siteId: 1,
        siteName: 'Site',
        title: 'Upcoming page',
        scheduledAt: now()->addDay()->toIso8601String(),
        timezone: 'UTC',
        readiness: 'blocked',
        reasons: ['publication_check'],
        url: '/admin/review/upcoming-page',
    );
    foreach ([true, false] as $attentionVisible) {
        new TestHtml(view('capell-admin::widgets.dashboard.work-first-publishing-row', ['item' => $item, 'attentionVisible' => $attentionVisible])->render())
            ->assertContainsElement('a', ['href' => '/admin/review/upcoming-page']);
    }
});
