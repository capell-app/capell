<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildPersonalDraftsAction;
use Capell\Admin\Contracts\Dashboard\DashboardWorkDataProvider;
use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PersonalDraftsWidget;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;

uses(CreatesAdminUser::class);

it('shows only the actors own draft pages in the selected site regardless of analytics period', function (): void {
    test()->actingAsAdmin();
    $actor = test()->authenticatedUser();
    $site = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $own = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Own draft', 'visible_from' => PublishSentinel::draftValue()]);
    $other = Page::factory()->createOne(['site_id' => $otherSite->id, 'name' => 'Other site', 'visible_from' => PublishSentinel::draftValue()]);
    $published = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Published', 'visible_from' => now()->subDay()]);
    $foreign = Page::factory()->createOne(['site_id' => $site->id, 'name' => 'Someone else draft', 'visible_from' => PublishSentinel::draftValue()]);
    $otherUser = test()->createUser();
    $foreign->forceFill(['created_by' => $otherUser->getKey()])->saveQuietly();
    foreach ([$own, $other, $published] as $page) {
        $page->forceFill(['created_by' => $actor->getAuthIdentifier()])->saveQuietly();
    }

    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $widget = new PersonalDraftsWidget;
    $widget->pageFilters = ['site_id' => $site->id, 'date_range' => 'today'];

    expect(collect($widget->data()->items)->pluck('title')->all())->toBe(['Own draft']);
    expect(collect(resolve(BuildPersonalDraftsAction::class)->handle($actor, $otherSite->id, 5)->items)->pluck('title')->all())->toBe(['Other site']);
});

it('does not fetch personal drafts when the widget is disabled', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    config()->set('capell-admin.dashboard.work_first.widgets', ['your_work' => false]);

    expect(PersonalDraftsWidget::canView())->toBeFalse()
        ->and(count((new PersonalDraftsWidget)->data()->items))->toBe(0);
});

it('passes scope to assigned work and does not resolve it when disabled', function (): void {
    test()->actingAsAdmin();
    $provider = new class implements DashboardWorkDataProvider
    {
        public int $calls = 0;

        public ?int $site = null;

        public ?string $language = null;

        public int $limit = 0;

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null): DashboardWorkData
        {
            $this->calls++;
            $this->site = $siteId;
            $this->language = $language;
            $this->limit = $limit;

            return new DashboardWorkData(true, 0, []);
        }
    };
    app()->instance(DashboardWorkDataProvider::class, $provider);
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    config()->set('capell-admin.dashboard.work_first.widgets', ['your_work' => false]);

    $widget = new PersonalDraftsWidget;
    $widget->assignments();

    expect($provider->calls)->toBe(0);

    config()->set('capell-admin.dashboard.work_first.widgets', []);
    $widget->pageFilters = ['site_id' => 12, 'language' => 'fr'];
    $widget->assignments();
    expect($provider->calls)->toBe(1)->and($provider->site)->toBe(12)->and($provider->language)->toBe('fr')->and($provider->limit)->toBe(100);
    $widget->dashboardSize = 'wide';
    $widget->assignments();

    expect($provider->limit)->toBe(100);
    $widget->pageFilters = ['site_id' => 'not-a-site'];
    $widget->assignments();

    expect($provider->site)->toBe(-1);
});
