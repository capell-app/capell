<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Dashboard\DashboardApprovalsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardApprovalItemData;
use Capell\Admin\Data\Dashboard\DashboardApprovalsData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\ApprovalsWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

it('does not call the approvals provider when disabled and passes selected scope when enabled', function (): void {
    test()->actingAsAdmin();
    $provider = new class implements DashboardApprovalsDataProvider
    {
        public int $calls = 0;

        public ?int $siteId = null;

        public ?string $language = null;

        #[Override]
        /** @param list<int> $retainedPageIds Authorised rows to retain beyond the new-item preview limit. */
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardApprovalsData
        {
            $this->calls++;
            $this->siteId = $siteId;
            $this->language = $language;

            return new DashboardApprovalsData(true, true, false, 0, 0, null, []);
        }
    };
    app()->instance(DashboardApprovalsDataProvider::class, $provider);
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    config()->set('capell-admin.dashboard.work_first.widgets', ['approvals' => false]);

    $widget = new ApprovalsWidget;
    $widget->data();

    expect($provider->calls)->toBe(0);

    config()->set('capell-admin.dashboard.work_first.widgets', []);
    $widget->pageFilters = ['site_id' => 42, 'language' => 'fr'];
    $widget->data();
    expect($provider->calls)->toBe(2)->and($provider->siteId)->toBe(42)->and($provider->language)->toBe('fr');
});

it('shows assigned review details', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    app()->instance(DashboardApprovalsDataProvider::class, new class implements DashboardApprovalsDataProvider
    {
        #[Override]
        /** @param list<int> $retainedPageIds Authorised rows to retain beyond the new-item preview limit. */
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardApprovalsData
        {
            return new DashboardApprovalsData(true, true, true, 1, 0, null, [
                new DashboardApprovalItemData(1, 2, 'Main site', 'Review page', 'Editor', '2026-01-01T12:00:00+00:00', null, '/admin/review'),
            ]);
        }
    });

    Livewire::test(ApprovalsWidget::class)
        ->assertSee('Review page')
        ->assertSee('Main site')
        ->assertSee('Editor')
        ->assertSee('1 assigned to you');
});

it('denies direct widget access when reviews are unavailable', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    Livewire::test(ApprovalsWidget::class)->assertForbidden();
});

it('stops a mounted approvals provider including capability probes when disabled by class', function (): void {
    $this->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $provider = new class implements DashboardApprovalsDataProvider
    {
        public int $calls = 0;

        /** @param list<int> $retainedPageIds */
        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardApprovalsData
        {
            $this->calls++;

            return new DashboardApprovalsData(true, true, true, 0, 0, null, []);
        }
    };
    app()->instance(DashboardApprovalsDataProvider::class, $provider);
    $widget = Livewire::test(ApprovalsWidget::class)->assertOk();
    $before = $provider->calls;
    config()->set('capell-admin.dashboard.work_first.widgets', [ApprovalsWidget::class => false]);
    $widget->call('$refresh');
    expect($provider->calls)->toBe($before)->and(ApprovalsWidget::canView())->toBeFalse();
});
