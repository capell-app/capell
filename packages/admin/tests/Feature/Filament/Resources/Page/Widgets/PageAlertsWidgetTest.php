<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\PageHasHeroContentWithoutHeroWidgetAction;
use Capell\Admin\Data\MessageData;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Pages\Widgets\PageAlertsFilamentWidget;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Livewire\Livewire;

uses(CreatesAdminUser::class);

it('does not show page alerts for ordinary pages', function (): void {
    test()->actingAsUser();

    $component = Livewire::test(PageAlertsFilamentWidget::class, [
        'record' => Page::factory()->withTranslations()->create(),
    ])->instance();

    assert($component instanceof PageAlertsFilamentWidget);

    expect($component->alerts())->toBeEmpty();
});

it('shows a missing hero widget alert with a layout edit action for editable layouts', function (): void {
    test()->actingAsAdmin();

    $layout = Layout::factory()->createOne();
    $page = Page::factory()
        ->layout($layout)
        ->withTranslations()
        ->createOne();

    $heroContentSpy = bindFakeAction(PageHasHeroContentWithoutHeroWidgetAction::class, true);

    $component = Livewire::test(PageAlertsFilamentWidget::class, [
        'record' => $page->fresh(['layout']),
    ])->instance();

    assert($component instanceof PageAlertsFilamentWidget);

    $alert = $component->alerts()->get('missingHeroWidget');

    assert($alert instanceof MessageData);
    assert($alert->action instanceof Action);

    expect($heroContentSpy->called)->toBeTrue()
        ->and($alert)->toBeInstanceOf(MessageData::class)
        ->and($alert->title)->toBe(__('capell-admin::message.page_hero_widget_missing_heading'))
        ->and($alert->message)->toBe(__('capell-admin::message.page_hero_widget_missing_warning'))
        ->and($alert->action)->not->toBeNull()
        ->and($alert->action->getName())->toBe('editLayout')
        ->and($alert->action->getUrl())->toBe(LayoutResource::getUrl('edit', ['record' => $layout]))
        ->and($alert->action->isVisible())->toBeTrue();
});
