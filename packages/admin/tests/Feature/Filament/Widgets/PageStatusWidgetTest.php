<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Widgets\Dashboard\PageStatusFilamentWidget;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('widget');

it('renders last-seven-day audience stats and hides the inventory counts by default', function (): void {
    Page::factory()->createOne();
    $this->actingAs($this->createUser());

    Livewire::test(PageStatusFilamentWidget::class)
        ->assertOk()
        ->assertSee('Capell overview')
        ->assertSee('Visitors')
        ->assertSee('Views per visitor')
        ->assertSee('Last 7 days')
        ->assertDontSee('Total pages')
        ->assertDontSee('Page types');
});

it('restores an inventory count when an operator switches it back on', function (): void {
    Page::factory()->createOne();

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = ['capell_overview_pages' => true];
    $settings->save();

    $this->actingAs($this->createUser());

    Livewire::test(PageStatusFilamentWidget::class)
        ->assertOk()
        ->assertSee('Total pages')
        ->assertSee('1');
});

it('renders enabled extension overview stats', function (): void {
    CapellAdmin::registerOverviewStat(
        key: 'fixture_overview.articles',
        label: 'Articles',
        value: fn (): int => 12,
        group: 'Fixture',
        description: 'Published articles',
        sort: 5,
    );

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = ['fixture_overview.articles' => true];
    $settings->save();

    $this->actingAs($this->createUser());

    Livewire::test(PageStatusFilamentWidget::class)
        ->assertOk()
        ->assertSee('Fixture')
        ->assertSee('Articles')
        ->assertSee('Published articles')
        ->assertSee('12');
});

it('hides disabled extension overview stats', function (): void {
    CapellAdmin::registerOverviewStat(
        key: 'fixture_overview.drafts',
        label: 'Drafts',
        value: fn (): int => 4,
        group: 'Fixture',
    );

    $settings = AdminSettings::instance();
    $settings->enabled_widgets = ['fixture_overview.drafts' => false];
    $settings->save();

    $this->actingAs($this->createUser());

    Livewire::test(PageStatusFilamentWidget::class)
        ->assertOk()
        ->assertDontSee('Drafts');
});
