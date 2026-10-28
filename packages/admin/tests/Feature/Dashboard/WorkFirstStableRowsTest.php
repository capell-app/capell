<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Dashboard\DashboardApprovalsDataProvider;
use Capell\Admin\Contracts\Dashboard\DashboardWorkDataProvider;
use Capell\Admin\Data\Dashboard\DashboardApprovalItemData;
use Capell\Admin\Data\Dashboard\DashboardApprovalsData;
use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Capell\Admin\Data\Dashboard\DashboardWorkItemData;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\ApprovalsWidget;
use Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\PersonalDraftsWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

it('offers new work before changing the displayed order', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $provider = new class implements DashboardWorkDataProvider
    {
        /** @var list<DashboardWorkItemData> */
        public array $items = [];

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null): DashboardWorkData
        {
            return new DashboardWorkData(true, count($this->items), $this->items);
        }
    };
    $provider->items = [new DashboardWorkItemData(1, 1, 'Site', 'First assignment', 'assigned', null, null, null)];

    app()->instance(DashboardWorkDataProvider::class, $provider);
    $component = Livewire::test(PersonalDraftsWidget::class)->assertSee('First assignment');
    array_unshift($provider->items, new DashboardWorkItemData(2, 1, 'Site', 'Second assignment', 'assigned', null, null, null));
    $component->call('$refresh')->assertSee('Show updates')->assertDontSee('Second assignment');
    $component->call('applyWorkUpdates')->assertSee('Second assignment');
    $provider->items = [$provider->items[0]];
    $component->call('$refresh')->assertDontSee('First assignment');
});

it('offers new approvals before changing the displayed order', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $provider = new class implements DashboardApprovalsDataProvider
    {
        /** @var list<DashboardApprovalItemData> */
        public array $items = [];

        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null): DashboardApprovalsData
        {
            return new DashboardApprovalsData(true, true, true, count($this->items), 0, null, $this->items);
        }
    };
    $provider->items = [new DashboardApprovalItemData(1, 1, 'Site', 'First review', 'Editor', null, null, '/review/1')];

    app()->instance(DashboardApprovalsDataProvider::class, $provider);
    $component = Livewire::test(ApprovalsWidget::class)->assertSee('First review');
    array_unshift($provider->items, new DashboardApprovalItemData(2, 1, 'Site', 'Second review', 'Editor', null, null, '/review/2'));
    $component->call('$refresh')->assertSee('Show updates')->assertDontSee('Second review');
    $component->call('applyApprovalUpdates')->assertSee('Second review');
    $provider->items = [$provider->items[0]];
    $component->call('$refresh')->assertDontSee('First review');
});

it('reveals more work rows in the wider size without internal scrolling', function (): void {
    test()->actingAsAdmin();
    config()->set('capell-admin.dashboard.work_first.enabled', true);
    $provider = new class implements DashboardWorkDataProvider
    {
        #[Override]
        public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null): DashboardWorkData
        {
            $items = [];
            foreach (range(1, 8) as $number) {
                $items[] = new DashboardWorkItemData($number, 1, 'Site', 'Assignment ' . $number, 'assigned', null, null, null);
            }

            return new DashboardWorkData(true, count($items), $items);
        }
    };
    app()->instance(DashboardWorkDataProvider::class, $provider);
    Livewire::test(PersonalDraftsWidget::class)->assertSee('Assignment 5')->assertDontSee('Assignment 6')
        ->set('dashboardSize', 'wide')->assertSee('Assignment 6')->assertSee('Assignment 8');
});
