<?php

declare(strict_types=1);

namespace Capell\Marketplace\Http\Controllers;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\CompleteMarketplaceInstallFlowAction;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Jobs\ResumeMarketplaceInstallFlowJob;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceInstallFlowCallbackController
{
    public function __invoke(
        Request $request,
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
            $actor = $request->user();
            ResumeMarketplaceInstallFlowJob::dispatch(
                (int) $session->getKey(),
                $actor !== null
                    ? MarketplaceInstallActorData::fromAuthenticatable($actor)
                    : MarketplaceInstallActorData::system('marketplace-hosted-resume'),
            )
                ->onConnection((string) config('capell-marketplace.marketplace.operations_queue_connection', 'database'))
                ->onQueue((string) config('capell-marketplace.marketplace.operations_queue', 'capell-marketplace'));
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
        $composerNames = $this->selectedComposerNames($session->selected_extensions ?? []);

        Notification::make()
            ->title((string) __('capell-marketplace::marketplace.install_flow.completed_title'))
            ->body((string) __('capell-marketplace::marketplace.install_flow.queued_body', [
                'count' => count($composerNames),
                'reference' => $supportReference,
            ]))
            ->success()
            ->persistent()
            ->send();

        session()->flash('capell-marketplace.open-marketplace', true);
        session()->flash('capell-marketplace.install-flow-completed', true);
        session()->flash('capell-marketplace.install-flow-support-reference', $supportReference);
        session()->flash('capell-marketplace.affected-composer-names', $composerNames);

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
     * @param  array<int, mixed>  $selections
     * @return list<string>
     */
    private function selectedComposerNames(array $selections): array
    {
        return array_values(collect($selections)
            ->filter(fn (mixed $selection): bool => is_array($selection))
            ->map(fn (array $selection): string => is_string($selection['composer_name'] ?? null)
                ? trim($selection['composer_name'])
                : '')
            ->filter(fn (string $composerName): bool => $composerName !== '')
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
