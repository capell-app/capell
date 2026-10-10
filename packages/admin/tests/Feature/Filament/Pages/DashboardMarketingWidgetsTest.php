<?php

declare(strict_types=1);

use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioAdvancedFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioQuickActionsFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioTimelineFilamentWidget;
use Capell\Admin\Filament\Widgets\MarketingStudio\MarketingStudioWorkQueueFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

it('renders marketing dashboard actions through the dashboard Filament widgets', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();

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

    Livewire::test(CapellDashboard::class)
        ->assertSuccessful()
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

it('filters marketing dashboard widgets using admin dashboard settings', function (): void {
    test()->actingAsAdmin();
    Site::factory()->createOne();

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = [
        MarketingStudioQuickActionsFilamentWidget::settingsKey() => true,
        MarketingStudioTimelineFilamentWidget::settingsKey() => false,
        MarketingStudioAdvancedFilamentWidget::settingsKey() => false,
    ];
    $settings->save();

    $widgets = (new CapellDashboard)->getWidgets();

    expect($widgets)
        ->toContain(MarketingStudioQuickActionsFilamentWidget::class)
        ->not->toContain(MarketingStudioTimelineFilamentWidget::class)
        ->not->toContain(MarketingStudioAdvancedFilamentWidget::class);
});
