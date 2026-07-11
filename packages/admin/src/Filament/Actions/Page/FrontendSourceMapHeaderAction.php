<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Page;

use Capell\Admin\Actions\Pages\BuildFrontendSourceMapAction;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

final class FrontendSourceMapHeaderAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('capell-admin::generic.frontend_source_map'))
            ->icon('heroicon-o-map')
            ->tooltip(__('capell-admin::generic.frontend_source_map_description'))
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->modalHeading(__('capell-admin::generic.frontend_source_map'))
            ->modalDescription(__('capell-admin::generic.frontend_source_map_description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('capell-admin::button.close'))
            ->modalContent(function (EditPage $livewire): View {
                /** @var view-string $view */
                $view = 'capell-admin::pages.frontend-source-map';

                return view($view, [
                    'items' => BuildFrontendSourceMapAction::run($livewire->record),
                ]);
            });
    }

    public static function getDefaultName(): string
    {
        return 'frontendSourceMap';
    }
}
