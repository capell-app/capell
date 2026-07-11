<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Pages\Widgets;

use Capell\Admin\Actions\Pages\PageHasHeroContentWithoutHeroWidgetAction;
use Capell\Admin\Data\MessageData;
use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Widgets\ResourceAlertsFilamentWidget;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\Page;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

class PageAlertsFilamentWidget extends ResourceAlertsFilamentWidget
{
    public ?Page $record = null;

    /**
     * @return Collection<string, MessageData>
     */
    protected function buildAlerts(): Collection
    {
        $alerts = collect();

        if (! $this->record instanceof Page) {
            return $alerts;
        }

        if (PageHasHeroContentWithoutHeroWidgetAction::run($this->record) !== true) {
            return $alerts;
        }

        $alerts->put(
            'missingHeroWidget',
            new MessageData(
                title: __('capell-admin::message.page_hero_widget_missing_heading'),
                message: __('capell-admin::message.page_hero_widget_missing_warning'),
                type: AlertTypeEnum::Warning,
                icon: 'heroicon-o-exclamation-triangle',
                action: $this->editLayoutAction(),
            ),
        );

        return $alerts;
    }

    private function editLayoutAction(): ?Action
    {
        $layout = $this->record?->layout;

        if ($layout === null) {
            return null;
        }

        return Action::make('editLayout')
            ->label(__('capell-admin::button.edit_layout'))
            ->url(fn (): string => AdminSurfaceLookup::resource(ResourceEnum::Layout)::getUrl('edit', [
                'record' => $layout,
            ]))
            ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('update', $layout))
            ->icon('heroicon-o-pencil-square')
            ->button();
    }
}
