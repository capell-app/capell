<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildWorkFirstAttentionAction;
use Capell\Admin\Actions\Dashboard\TrackDashboardAttentionRecoveryAction;
use Capell\Admin\Contracts\Dashboard\DashboardAttentionContributor;
use Capell\Admin\Contracts\Dashboard\DashboardAttentionRecoveryContributor;
use Capell\Admin\Data\Dashboard\DashboardAdvisoryDecisionData;
use Capell\Admin\Data\Dashboard\DashboardAttentionItemData;
use Capell\Admin\Data\Dashboard\DashboardAttentionRecoveryCandidateData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\AttentionWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\UrgentSummaryWidget;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Capell\Admin\Support\Workspace\AdminWorkspacePreferenceStore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

it('separates live publishing blockers from draft improvements without using content age', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $draft = Page::withoutEvents(fn () => Page::factory()->create([
        'site_id' => $site->id, 'name' => 'Old unfinished page',
        'visible_from' => PublishSentinel::draftValue(), 'updated_at' => now()->subYears(2),
    ]));
    $live = Page::withoutEvents(fn () => Page::factory()->create([
        'site_id' => $site->id, 'name' => 'Unavailable live page',
        'visible_from' => now()->subDay(),
    ]));
    Page::withoutEvents(fn () => Page::factory()->create(['site_id' => $otherSite->id]));

    $items = collect(BuildWorkFirstAttentionAction::run($actor, $site->id, null))->keyBy('key');

    expect($items)->toHaveCount(2)
        ->and($items->firstOrFail('key', 'page-setup-' . $draft->id)->urgent)->toBeFalse()
        ->and($items->firstOrFail('key', 'page-setup-' . $live->id)->urgent)->toBeTrue()
        ->and($items->firstOrFail()->key)->toBe('page-setup-' . $live->id);
});

it('lets a person dismiss an improvement but cannot dismiss an urgent issue', function (): void {
    $this->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $draft = Page::withoutEvents(fn () => Page::factory()->create([
        'visible_from' => PublishSentinel::draftValue(),
    ]));
    $live = Page::withoutEvents(fn () => Page::factory()->create([
        'site_id' => $draft->site_id, 'visible_from' => now()->subDay(),
    ]));
    $widget = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $draft->site_id]])->assertOk();
    expect($widget->get('items'))->toHaveCount(2);
    $widget->call('dismiss', 'page-setup-' . $draft->id)->assertOk();
    expect($widget->get('items'))->toHaveCount(1);
    $widget->call('dismiss', 'page-setup-' . $live->id)->assertOk();
    expect($widget->get('items'))->toHaveCount(1);
    $this->actingAsAdmin();
    expect(Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $draft->site_id]])->get('items'))->toHaveCount(2);
});

it('keeps urgent notices working when the personal attention detail is disabled', function (): void {
    $this->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    config()->set('capell-admin.dashboard.work_first.widgets.attention', false);
    Page::withoutEvents(fn () => Page::factory()->create(['visible_from' => now()->subDay()]));

    expect(AttentionWidget::canView())->toBeFalse()
        ->and(UrgentSummaryWidget::canView())->toBeTrue()
        ->and((new UrgentSummaryWidget)->items())->toHaveCount(1);

    config()->set('capell-admin.dashboard.work_first.enabled', false);
    expect((new UrgentSummaryWidget)->items())->toBe([]);
});

it('makes snoozed improvements visible again after the reminder expires', function (): void {
    $this->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $draft = Page::withoutEvents(fn () => Page::factory()->create(['visible_from' => PublishSentinel::draftValue()]));
    $widget = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $draft->site_id]]);
    $widget->call('snooze', 'page-setup-' . $draft->id);

    expect($widget->get('items'))->toBe([]);
    $this->travel(2)->days();
    expect(Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $draft->site_id]])->get('items'))->toHaveCount(1);
});

it('shares attention retrieval within a request and refreshes it on the next request', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    Site::factory()->create();
    $contributor = new class implements DashboardAttentionContributor
    {
        public int $calls = 0;

        /** @return list<DashboardAttentionItemData> */
        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language): array
        {
            $this->calls++;

            return [];
        }
    };
    app()->instance('test.attention-contributor', $contributor);
    app()->tag('test.attention-contributor', DashboardAttentionContributor::TAG);

    $cache = resolve(AdminDashboardDataRequestCache::class);
    expect($cache->attentionSnapshot($actor, null, null))->toBe([])
        ->and($cache->attentionSnapshot($actor, null, null))->toBe([])
        ->and($contributor->calls)->toBe(1);
    app()->instance('request', Request::create('/next-dashboard-request'));
    expect($cache->attentionSnapshot($actor, null, null))->toBe([])
        ->and($contributor->calls)->toBe(2);
});

it('retains verified recovery after reload and preserves other personal preferences', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $page = Page::withoutEvents(fn () => Page::factory()->create(['visible_from' => PublishSentinel::draftValue()]));
    $store = resolve(AdminWorkspacePreferenceStore::class);
    $store->saveDashboardLayout($actor, false, ['kept-widget']);
    $store->saveDashboardAdvisoryDecision($actor, 'kept', new DashboardAdvisoryDecisionData('fingerprint'));

    $contributor = new class($page) implements DashboardAttentionContributor, DashboardAttentionRecoveryContributor
    {
        public bool $active = true;

        public bool $verified = false;

        public bool $historyAllowed = true;

        public function __construct(private readonly Page $page) {}

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language): array
        {
            return $this->active ? [new DashboardAttentionItemData('optional-issue', 'v1', false, 'Optional issue', (int) $this->page->site_id, 'Site', 'Issue', [], null, pageId: (int) $this->page->id)] : [];
        }

        #[Override]
        public function canViewHistory(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
        {
            return $candidate->key === 'optional-issue' && $this->historyAllowed;
        }

        #[Override]
        public function isRecovered(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
        {
            return $candidate->key === 'optional-issue' && $this->verified;
        }
    };
    app()->instance('test.recovery-contributor', $contributor);
    app()->tag('test.recovery-contributor', DashboardAttentionContributor::TAG);

    $widget = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $page->site_id]]);
    expect(collect($widget->get('items'))->pluck('key'))->toContain('optional-issue');
    $contributor->active = false;
    $contributor->verified = true;

    $this->app->instance('request', Request::create('/reloaded-dashboard'));
    $reloaded = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $page->site_id]]);
    expect($reloaded->get('issueResolved'))->toBeTrue();
    $reloaded->assertSee('Optional issue');
    expect(Gate::forUser($actor)->allows('view', $page))->toBeTrue();
    $contributor->historyAllowed = false;
    expect(resolve(TrackDashboardAttentionRecoveryAction::class)->history($actor, (int) $page->site_id, null))->toBe([]);
    $contributor->historyAllowed = true;
    $reloaded->call('acknowledgeRecovery');

    $again = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $page->site_id]]);
    expect($again->get('issueResolved'))->toBeFalse();
    $again->assertSee('Optional issue');
    expect($store->dashboardLayout($actor->refresh(), false))->toBe(['kept-widget'])
        ->and($store->dashboardAdvisoryDecision($actor->refresh(), 'kept')?->fingerprint)->toBe('fingerprint');
});

it('does not infer recovery from absence or a change of scope, and rechecks history permissions', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $page = Page::withoutEvents(fn () => Page::factory()->create(['visible_from' => PublishSentinel::draftValue()]));
    $otherSite = Site::factory()->create();
    $contributor = new class($page) implements DashboardAttentionContributor, DashboardAttentionRecoveryContributor
    {
        public bool $active = true;

        public bool $verified = false;

        public bool $throws = false;

        public function __construct(private readonly Page $page) {}

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language): array
        {
            return $this->active && ($siteId === null || $siteId === $this->page->site_id) ? [new DashboardAttentionItemData('external-issue', 'v1', false, 'Private issue', (int) $this->page->site_id, 'Site', 'Issue', [], null, pageId: (int) $this->page->id)] : [];
        }

        #[Override]
        public function canViewHistory(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
        {
            return $candidate->key === 'external-issue';
        }

        #[Override]
        public function isRecovered(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
        {
            throw_if($this->throws, RuntimeException::class, 'Contributor unavailable');

            return $candidate->key === 'external-issue' && $this->verified;
        }
    };
    app()->instance('test.scope-recovery-contributor', $contributor);
    app()->tag('test.scope-recovery-contributor', DashboardAttentionContributor::TAG);
    Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $page->site_id]])->assertSee('Private issue');
    $contributor->active = false;
    $this->app->instance('request', Request::create('/absent'));
    $missing = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $page->site_id]]);
    expect($missing->get('issueResolved'))->toBeFalse()
        ->and($missing->get('recoveryHistory'))->toBe([]);
    $contributor->verified = true;
    $contributor->throws = true;

    $this->app->instance('request', Request::create('/contributor-failed'));
    $unavailable = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $page->site_id]]);
    expect($unavailable->get('issueResolved'))->toBeFalse()->and($unavailable->get('recoveryHistory'))->toBe([]);
    $contributor->throws = false;
    config()->set('capell-admin.dashboard.work_first.enabled', false);
    expect((new AttentionWidget)->currentItems())->toBe([]);
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $this->app->instance('request', Request::create('/other-site'));
    $other = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $otherSite->id]]);
    expect($other->get('issueResolved'))->toBeFalse()->and($other->get('recoveryHistory'))->toBe([]);
    $this->app->instance('request', Request::create('/original-site'));
    $recovered = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $page->site_id]]);
    expect($recovered->get('issueResolved'))->toBeTrue()->and($recovered->get('recoveryHistory'))->toHaveCount(1);
    $actor->syncRoles([]);
    $actor->unsetRelation('roles');
    $actor->unsetRelation('permissions');

    $this->app->instance('request', Request::create('/revoked'));
    expect(resolve(TrackDashboardAttentionRecoveryAction::class)->history($actor, (int) $page->site_id, null))->toBe([]);
});

it('records page recovery only after the actual blockers are cleared', function (): void {
    $this->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->createOne();
    config()->set('capell-admin.dashboard.work_first.site_timezones', [$site->uuid => 'Europe/London']);
    $this->travelTo(CarbonImmutable::parse('2026-06-01T12:00:00+00:00'));
    $page = Page::withoutEvents(fn () => Page::factory()->site($site)->withTranslations($language)->createOne(['name' => 'Needs a URL', 'visible_from' => PublishSentinel::draftValue()]));
    $first = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $site->id]]);
    expect(collect($first->get('items'))->pluck('key'))->toContain('page-setup-' . $page->id);
    $page->pageUrls()->create(['site_id' => $site->id, 'language_id' => $language->id, 'url' => '/recovered', 'status' => true]);
    $this->app->instance('request', Request::create('/after-page-repair'));
    $after = Livewire::test(AttentionWidget::class, ['pageFilters' => ['site_id' => $site->id]]);
    expect($after->get('issueResolved'))->toBeTrue()
        ->and(collect($after->get('recoveryHistory'))->pluck('title'))->toContain('Needs a URL')
        ->and($after->get('recoveryHistory')[0]['site_name'])->toBe($site->name)
        ->and($after->get('recoveryHistory')[0]['display_date'])->toBe('1 Jun 2026, 13:00')
        ->and($after->get('recoveryHistory')[0]['timezone'])->toBe('Europe/London');
    $actor = $this->authenticatedUser();
    resolve(AdminWorkspacePreferenceStore::class)->updateDashboardRecoveryState($actor, static function (array $state): array {
        $state['history'][0]['resolved_at'] = 'not-a-date';

        return $state;
    });
    expect(resolve(TrackDashboardAttentionRecoveryAction::class)->history($actor, (int) $site->id, null))->toBe([]);
});

it('verifies urgent recovery across the selected language while retaining its scope', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $page = Page::withoutEvents(fn () => Page::factory()->create());
    $contributor = new class implements DashboardAttentionContributor, DashboardAttentionRecoveryContributor
    {
        public ?string $verifiedLanguage = 'not-called';

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language): array
        {
            return [];
        }

        #[Override]
        public function canViewHistory(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
        {
            return $candidate->key === 'urgent-external';
        }

        #[Override]
        public function isRecovered(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
        {
            $this->verifiedLanguage = $candidate->language;

            return $candidate->key === 'urgent-external';
        }
    };
    app()->instance('test.urgent-recovery-contributor', $contributor);
    app()->tag('test.urgent-recovery-contributor', DashboardAttentionContributor::TAG);

    $tracker = resolve(TrackDashboardAttentionRecoveryAction::class);
    $item = new DashboardAttentionItemData('urgent-external', 'v1', true, 'Urgent issue', (int) $page->site_id, 'Site', 'Issue', [], null, pageId: (int) $page->id);
    $tracker->observe($actor, (int) $page->site_id, 'fr', [$item]);
    $tracker->observe($actor, (int) $page->site_id, 'fr', []);
    expect($contributor->verifiedLanguage)->toBeNull()
        ->and($tracker->history($actor, (int) $page->site_id, 'fr'))->toHaveCount(1)
        ->and($tracker->history($actor, (int) $page->site_id, null))->toBe([]);
});

it('bounds retained observations and verified history', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $page = Page::withoutEvents(fn () => Page::factory()->create());
    $contributor = new class implements DashboardAttentionContributor, DashboardAttentionRecoveryContributor
    {
        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, ?string $language): array
        {
            return [];
        }

        #[Override]
        public function canViewHistory(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
        {
            return str_starts_with($candidate->key, 'bounded-');
        }

        #[Override]
        public function isRecovered(Authenticatable $actor, DashboardAttentionRecoveryCandidateData $candidate): bool
        {
            return str_starts_with($candidate->key, 'bounded-');
        }
    };
    app()->instance('test.bounded-recovery-contributor', $contributor);
    app()->tag('test.bounded-recovery-contributor', DashboardAttentionContributor::TAG);

    $tracker = resolve(TrackDashboardAttentionRecoveryAction::class);
    $items = [];
    foreach (range(1, 110) as $number) {
        $items[] = new DashboardAttentionItemData('bounded-' . $number, 'v1', false, 'Issue ' . $number, (int) $page->site_id, 'Site', 'Issue', [], null, pageId: (int) $page->id);
    }

    $tracker->observe($actor, (int) $page->site_id, null, $items);
    $state = resolve(AdminWorkspacePreferenceStore::class)->dashboardRecoveryState($actor);
    expect(array_values($state['observed'])[0])->toHaveCount(100);
    $tracker->observe($actor, (int) $page->site_id, null, []);
    expect($tracker->history($actor, (int) $page->site_id, null))->toHaveCount(50);
});

it('does not write preferences for an empty or unchanged attention snapshot', function (): void {
    $this->actingAsAdmin();
    $actor = $this->authenticatedUser();
    $page = Page::withoutEvents(fn () => Page::factory()->create());
    $tracker = resolve(TrackDashboardAttentionRecoveryAction::class);
    $item = new DashboardAttentionItemData('unchanged', 'v1', false, 'Issue', (int) $page->site_id, 'Site', 'Issue', [], null, pageId: (int) $page->id);
    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $connection->flushQueryLog();
        $tracker->observe($actor, (int) $page->site_id, null, []);
        expect(collect($connection->getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_starts_with(strtolower($query), 'update'))->all())->toBe([]);
        $tracker->observe($actor, (int) $page->site_id, null, [$item]);
        $connection->flushQueryLog();
        $tracker->observe($actor, (int) $page->site_id, null, [$item]);
        expect(collect($connection->getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_starts_with(strtolower($query), 'update'))->all())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }
});
