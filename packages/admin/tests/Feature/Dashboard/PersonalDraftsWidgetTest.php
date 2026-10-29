<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildPersonalDraftsAction;
use Capell\Admin\Contracts\Dashboard\DashboardWorkDataProvider;
use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PersonalDraftsWidget;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;

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
        /** @param list<int> $retainedPageIds Authorised rows to retain beyond the new-item preview limit. */
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardWorkData
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

it('opens the owned drafts queue with matching site and language filters', function (): void {
    test()->actingAsAdmin();
    $actor = test()->authenticatedUser();
    $language = Language::factory()->createOne();
    $otherLanguage = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $own = Page::factory()->recycle($site)->withTranslations($language)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    $otherLang = Page::factory()->recycle($site)->withTranslations($otherLanguage)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    $otherSitePage = Page::factory()->recycle($otherSite)->withTranslations($language)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    $published = Page::factory()->recycle($site)->withTranslations($language)->createOne(['visible_from' => now()->subDay()]);
    $foreign = Page::factory()->recycle($site)->withTranslations($language)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    foreach ([$own, $otherLang, $otherSitePage, $published] as $page) {
        $page->forceFill(['created_by' => $actor->getAuthIdentifier()])->saveQuietly();
    }

    $foreign->forceFill(['created_by' => test()->createUser()->getKey()])->saveQuietly();

    $result = resolve(BuildPersonalDraftsAction::class)->handle($actor, (int) $site->getKey(), 5, $language->code);
    expect($result->listUrl)->not->toBeNull();
    parse_str((string) parse_url((string) $result->listUrl, PHP_URL_QUERY), $query);
    expect($query['site'])->toBe((string) $site->getKey())
        ->and(data_get($query, 'tableFilters.owned_drafts.isActive'))->toBe('1')
        ->and(data_get($query, 'tableFilters.site_id.value'))->toBe((string) $site->getKey())
        ->and(data_get($query, 'tableFilters.filter.language_id'))->toBe((string) $language->getKey());

    Livewire::test(ListPages::class)
        ->filterTable('owned_drafts')
        ->filterTable('site_id', $site->getKey())
        ->set('tableFilters.filter.language_id', $language->getKey())
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$otherLang, $otherSitePage, $published, $foreign]);
});

it('clears the remembered site filter when opening all sites own drafts', function (): void {
    $this->actingAsAdmin();
    $site = Site::factory()->createOne();
    session()->put('capell.current_site_id', $site->id);
    $data = resolve(BuildPersonalDraftsAction::class)->handle($this->authenticatedUser(), null, 5);
    parse_str((string) parse_url((string) $data->listUrl, PHP_URL_QUERY), $query);
    expect(data_get($query, 'tableFilters.site_id.value'))->toBe('');
});

it('stops an already mounted personal work provider when its class switch is disabled', function (): void {
    $this->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $provider = new class implements DashboardWorkDataProvider
    {
        public int $calls = 0;

        /** @param list<int> $retainedPageIds */
        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardWorkData
        {
            $this->calls++;

            return new DashboardWorkData(true, 0, []);
        }
    };
    app()->instance(DashboardWorkDataProvider::class, $provider);
    $widget = Livewire::test(PersonalDraftsWidget::class)->assertOk();
    $before = $provider->calls;
    expect($before)->toBeGreaterThan(0);
    config()->set('capell-admin.dashboard.work_first.widgets', [PersonalDraftsWidget::class => false]);
    $widget->call('$refresh');
    expect($provider->calls)->toBe($before)->and(PersonalDraftsWidget::canView())->toBeFalse();
});
