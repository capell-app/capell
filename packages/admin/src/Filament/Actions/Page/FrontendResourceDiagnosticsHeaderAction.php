<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Page;

use Capell\Admin\Actions\Pages\BuildPageFrontendResourceDiagnosticsAction;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

final class FrontendResourceDiagnosticsHeaderAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('capell-admin::generic.frontend_resource_diagnostics'))
            ->icon('heroicon-o-circle-stack')
            ->tooltip(__('capell-admin::generic.frontend_resource_diagnostics_description'))
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->modalHeading(__('capell-admin::generic.frontend_resource_diagnostics'))
            ->modalDescription(__('capell-admin::generic.frontend_resource_diagnostics_description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('capell-admin::button.close'))
            ->modalContent(function (EditPage $livewire): View {
                /** @var view-string $view */
                $view = 'capell-admin::pages.frontend-resource-diagnostics';

                return view($view, BuildPageFrontendResourceDiagnosticsAction::run($livewire->record));
            });
    }

    public static function getDefaultName(): string
    {
        return 'frontendResourceDiagnostics';
    }
}
