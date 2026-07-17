<?php

declare(strict_types=1);

namespace Capell\Marketplace\Http\Controllers;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\CompleteMarketplaceInstallFlowAction;
use Capell\Marketplace\Actions\ResumeMarketplaceInstallFlowAction;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceInstallFlowCallbackController
{
    public function __invoke(
        Request $request,
        CompleteMarketplaceInstallFlowAction $completeFlow,
        ResumeMarketplaceInstallFlowAction $resumeFlow,
    ): RedirectResponse {
        abort_unless(ExtensionsPage::canManageExtensions(), 403);

        $flowId = $request->query('flow_id');
        $code = $request->query('code');
        $state = $request->query('state');

        if (! is_string($flowId) || ! is_string($code) || ! is_string($state)) {
            $this->failedNotification($this->supportReference(is_string($flowId) ? $flowId : null));

            return $this->redirectToMarketplace();
        }

        try {
            $session = CompleteMarketplaceInstallFlowAction::run($flowId, $code, $state);
            $attempts = ResumeMarketplaceInstallFlowAction::run($session);
        } catch (Throwable $throwable) {
            Log::warning('capell-marketplace: install flow callback failed', [
                'error' => $throwable->getMessage(),
                'flow_id' => $flowId,
                'support_reference' => $this->supportReference($flowId),
            ]);

            $this->failedNotification($this->supportReference($flowId));

            return $this->redirectToMarketplace();
        }

        $supportReference = $this->supportReference($session->remote_flow_id);
        $extensionNames = $this->attemptExtensionNames($attempts);

        Notification::make()
            ->title((string) __('capell-marketplace::marketplace.install_flow.completed_title'))
            ->body(trans_choice('capell-marketplace::marketplace.install_flow.completed_body', count($attempts), [
                'count' => count($attempts),
                'extensions' => $extensionNames,
                'reference' => $supportReference,
            ]))
            ->success()
            ->persistent()
            ->send();

        session()->flash('capell-marketplace.open-marketplace', true);
        session()->flash('capell-marketplace.install-flow-completed', true);
        session()->flash('capell-marketplace.install-flow-support-reference', $supportReference);
        session()->flash('capell-marketplace.affected-composer-names', $this->attemptComposerNames($attempts));

        return $this->redirectToMarketplace();
    }

    private function failedNotification(string $supportReference): void
    {
        Notification::make()
            ->title((string) __('capell-marketplace::marketplace.install_flow.failed_title'))
            ->body((string) __('capell-marketplace::marketplace.install_flow.failed_body', [
                'reference' => $supportReference,
            ]))
            ->danger()
            ->persistent()
            ->send();
    }

    private function redirectToMarketplace(): RedirectResponse
    {
        session()->flash('capell-marketplace.open-marketplace', true);

        return redirect(ExtensionsPage::getUrl());
    }

    /**
     * @param  array<int, MarketplaceInstallAttempt>  $attempts
     */
    private function attemptExtensionNames(array $attempts): string
    {
        return Collection::make($attempts)
            ->map(fn (MarketplaceInstallAttempt $attempt): string => trim($attempt->extension_name))
            ->filter()
            ->unique()
            ->implode(', ') ?: (string) __('capell-marketplace::marketplace.operations.unknown_extension');
    }

    /**
     * @param  array<int, MarketplaceInstallAttempt>  $attempts
     * @return list<string>
     */
    private function attemptComposerNames(array $attempts): array
    {
        return array_values(Collection::make($attempts)
            ->map(fn (MarketplaceInstallAttempt $attempt): string => trim($attempt->composer_name))
            ->filter()
            ->unique()
            ->values()
            ->all());
    }

    private function supportReference(?string $flowId): string
    {
        if (is_string($flowId) && $flowId !== '') {
            return $flowId;
        }

        return (string) __('capell-marketplace::marketplace.install_flow.unknown_support_reference');
    }
}
