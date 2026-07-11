<?php

declare(strict_types=1);

namespace Capell\Marketplace\Http\Controllers;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\CompleteMarketplaceAccountConnectionAction;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class MarketplaceAccountConnectionCallbackController
{
    public function __invoke(Request $request, CompleteMarketplaceAccountConnectionAction $completeConnection): RedirectResponse
    {
        abort_unless(ExtensionsPage::canManageExtensions(), 403);

        $connectionSessionId = $request->query('connection_session_id');
        $code = $request->query('code');
        $state = $request->query('state');

        if (! is_string($connectionSessionId) || ! is_string($code) || ! is_string($state)) {
            Notification::make()
                ->title((string) __('capell-marketplace::marketplace.marketplace.account_connection_failed'))
                ->body((string) __('capell-marketplace::marketplace.marketplace.account_connection_failed_body'))
                ->danger()
                ->persistent()
                ->send();

            return $this->redirectToExtensionsPage();
        }

        try {
            $completeConnection->handle($connectionSessionId, $code, $state);
        } catch (Throwable $throwable) {
            Log::warning('capell-marketplace: account connection callback failed', ['error' => $throwable->getMessage()]);

            Notification::make()
                ->title((string) __('capell-marketplace::marketplace.marketplace.account_connection_failed'))
                ->body($this->failureMessage($throwable))
                ->danger()
                ->persistent()
                ->send();

            return $this->redirectToExtensionsPage();
        }

        Notification::make()
            ->title(__('capell-marketplace::marketplace.marketplace.account_connected'))
            ->body(__('capell-marketplace::marketplace.marketplace.account_connected_body'))
            ->success()
            ->send();

        session()->flash('capell-marketplace.open-marketplace', true);

        return $this->redirectToExtensionsPage();
    }

    private function redirectToExtensionsPage(): RedirectResponse
    {
        return redirect(ExtensionsPage::getUrl());
    }

    private function failureMessage(Throwable $throwable): string
    {
        if ($throwable instanceof RuntimeException && in_array($throwable->getMessage(), [
            'Your Capell account email must be verified before connecting Marketplace.',
            'Marketplace account connection session has expired.',
            'Marketplace account connection state is invalid.',
        ], true)) {
            return $throwable->getMessage();
        }

        return (string) __('capell-marketplace::marketplace.marketplace.account_connection_failed_body');
    }
}
