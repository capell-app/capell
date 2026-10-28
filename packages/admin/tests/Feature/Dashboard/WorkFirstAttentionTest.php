<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildWorkFirstAttentionAction;
use Capell\Admin\Contracts\Dashboard\DashboardAttentionContributor;
use Capell\Admin\Data\Dashboard\DashboardAttentionItemData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\AttentionWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\UrgentSummaryWidget;
use Capell\Admin\Support\Dashboard\AdminDashboardDataRequestCache;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
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

    $items = collect(resolve(BuildWorkFirstAttentionAction::class)->handle($actor, $site->id, null))->keyBy('key');

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
