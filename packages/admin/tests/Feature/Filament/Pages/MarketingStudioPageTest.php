<?php

declare(strict_types=1);

use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\MarketingStudioPage;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioAdvancedFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioQuickActionsFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioTimelineFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioWorkQueueFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

it('renders marketing studio actions through the dashboard Filament widgets', function (): void {
    grantMarketingStudioPageAccess();

    CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
        key: 'launch-newsletter',
        label: 'Launch newsletter campaign',
        url: '/admin/campaigns/newsletter',
        section: MarketingStudioSectionEnum::Campaigns,
        sort: 20,
        description: 'Prepare and schedule the weekly newsletter.',
        badge: 'Ready',
    ));
    CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
        key: 'review-forms',
        label: 'Review lead forms',
        url: '/admin/forms/review',
        section: MarketingStudioSectionEnum::WorkQueue,
        sort: 10,
        description: 'Check stalled form submissions.',
        badge: 3,
    ));
    CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
        key: 'advanced-attribution',
        label: 'Configure attribution rules',
        url: '/admin/marketing/attribution',
        section: MarketingStudioSectionEnum::Advanced,
        sort: 50,
        description: 'Tune campaign attribution windows.',
    ));
    CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
        key: 'hidden-experiment',
        label: 'Hidden experiment',
        url: '/admin/marketing/hidden',
        section: MarketingStudioSectionEnum::Performance,
        visible: false,
    ));

    Livewire::test(MarketingStudioPage::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::marketing-studio.title'))
        ->assertSeeLivewire(MarketingStudioQuickActionsFilamentWidget::class)
        ->assertSeeLivewire(MarketingStudioWorkQueueFilamentWidget::class)
        ->assertSeeLivewire(MarketingStudioAdvancedFilamentWidget::class);

    Livewire::test(MarketingStudioQuickActionsFilamentWidget::class)
        ->assertSee(__('capell-admin::marketing-studio.quick_actions'))
        ->assertSee('Launch newsletter campaign')
        ->assertSee('Prepare and schedule the weekly newsletter.')
        ->assertDontSee('Configure attribution rules')
        ->assertDontSee('Hidden experiment');

    Livewire::test(MarketingStudioWorkQueueFilamentWidget::class)
        ->assertSee('Review lead forms')
        ->assertSee('Check stalled form submissions.')
        ->assertDontSee('Launch newsletter campaign');

    Livewire::test(MarketingStudioAdvancedFilamentWidget::class)
        ->assertSee(__('capell-admin::marketing-studio.advanced_description'))
        ->assertDontSee('sync plumbing')
        ->assertSee('Configure attribution rules')
        ->assertDontSee('Hidden experiment');
});

it('filters marketing studio widgets using admin dashboard settings', function (): void {
    grantMarketingStudioPageAccess();

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        MarketingStudioQuickActionsFilamentWidget::settingsKey() => true,
        MarketingStudioTimelineFilamentWidget::settingsKey() => false,
        MarketingStudioAdvancedFilamentWidget::settingsKey() => false,
    ];
    $settings->save();

    $widgets = (new MarketingStudioPage)->getWidgets();

    expect($widgets)
        ->toContain(MarketingStudioQuickActionsFilamentWidget::class)
        ->not->toContain(MarketingStudioTimelineFilamentWidget::class)
        ->not->toContain(MarketingStudioAdvancedFilamentWidget::class);
});

it('persists dashboard layout changes from the marketing studio customise action', function (): void {
    grantMarketingStudioPageAccess(canManageSettings: true);

    Livewire::test(MarketingStudioPage::class)
        ->assertActionVisible('customiseMarketingStudioDashboard')
        ->callAction('customiseMarketingStudioDashboard', data: [
            'widget_layout' => [
                [
                    'key' => MarketingStudioQuickActionsFilamentWidget::settingsKey(),
                    'enabled' => true,
                    'order' => 30,
                ],
                [
                    'key' => MarketingStudioTimelineFilamentWidget::settingsKey(),
                    'enabled' => false,
                    'order' => 10,
                ],
            ],
        ])
        ->assertNotified(__('capell-admin::notification.dashboard_customised'));

    $settings = AdminSettings::instance()->refresh();

    expect($settings->enabled_widgets)
        ->toHaveKey(MarketingStudioQuickActionsFilamentWidget::settingsKey(), true)
        ->toHaveKey(MarketingStudioTimelineFilamentWidget::settingsKey(), false)
        ->and($settings->widget_order)
        ->toHaveKey(MarketingStudioQuickActionsFilamentWidget::settingsKey(), 30)
        ->toHaveKey(MarketingStudioTimelineFilamentWidget::settingsKey(), 10);
});

it('hides the dashboard customise action from users without settings access', function (): void {
    grantMarketingStudioPageAccess(asAdmin: false);

    Livewire::test(MarketingStudioPage::class)
        ->assertActionHidden('customiseMarketingStudioDashboard');
});

function grantMarketingStudioPageAccess(bool $canManageSettings = false, bool $asAdmin = true): void
{
    Permission::create(['name' => 'View:MarketingStudioPage', 'guard_name' => 'web']);
    Permission::create(['name' => 'View:SettingsPage', 'guard_name' => 'web']);

    $asAdmin ? test()->actingAsAdmin() : test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo('View:MarketingStudioPage');

    if ($canManageSettings) {
        test()->authenticatedUser()->givePermissionTo('View:SettingsPage');
    }
}
