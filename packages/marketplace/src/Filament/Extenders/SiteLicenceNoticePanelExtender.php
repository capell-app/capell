<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Extenders;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Marketplace\Actions\CheckSiteLicenceAction;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Override;

/**
 * A polite licence notice at the top of admin pages, for people who manage extensions. It reads the last stored
 * check and never calls the network. It only ever renders inside the admin panel, so nothing reaches public pages,
 * and it never disables a package.
 */
final class SiteLicenceNoticePanelExtender implements AdminPanelExtender
{
    #[Override]
    public function extend(Panel $panel): void
    {
        $panel->renderHook(
            PanelsRenderHook::CONTENT_START,
            static function (): ?View {
                if (auth()->user() === null || ! MarketplacePage::canAccess()) {
                    return null;
                }

                $status = resolve(CheckSiteLicenceAction::class)->current();

                return $status !== null && $status->state->showsNotice()
                    ? view('capell-marketplace::filament.licence.notice', ['status' => $status])
                    : null;
            },
        );
    }
}
