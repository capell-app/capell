<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Actions;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\PhoneHomeAction;
use Capell\Marketplace\Actions\StartMarketplaceAccountConnectionAction;
use Capell\Marketplace\Enums\MarketplaceConnectionMode;
use Capell\Marketplace\Enums\MarketplacePermission;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Capell\Marketplace\Support\MarketplaceWebUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarketplaceConnectionFormModel
{
    public const INSTALL_FAILED_NOTIFICATION_ID = 'capell-marketplace-install-failed';

    public function __construct(private readonly MarketplaceInstanceResolver $instances) {}

    public function heartbeatAction(): Action
    {
        return Action::make('runMarketplaceHeartbeat')
            ->label((string) __('capell-marketplace::marketplace.install.run_heartbeat'))
            ->icon(Heroicon::ArrowPath)
            ->color('gray')
            ->tooltip((string) __('capell-marketplace::marketplace.marketplace.heartbeat_tooltip'))
            ->visible(fn (): bool => ExtensionsPage::canManageExtensions())
            ->authorize(fn (): bool => ExtensionsPage::canManageExtensions())
            ->disabled(fn (): bool => ! $this->instance() instanceof MarketplaceInstance)
            ->action(function (): void {
                $this->runHeartbeat();
            });
    }

    public function connectionState(): string
    {
        if (! $this->marketplaceBaseUrlConfigured()) {
            return 'needs_configuration';
        }

        return $this->hasAccountLinkedInstance() ? 'connected' : 'not_connected';
    }

    public function connectionLanguagePath(string $languageKey): string
    {
        $state = match ($this->connectionState()) {
            'needs_configuration', 'connected', 'not_connected' => $this->connectionState(),
            default => 'not_connected',
        };
        $key = match ($languageKey) {
            'label', 'title', 'body' => $languageKey,
            default => 'body',
        };

        return sprintf('capell-marketplace::marketplace.marketplace.status.%s.%s', $state, $key);
    }

    public function connectionTitle(): string
    {
        return (string) __($this->connectionLanguagePath('title'));
    }

    public function connectionBody(): string
    {
        return (string) __($this->connectionLanguagePath('body'));
    }

    public function instance(): ?MarketplaceInstance
    {
        return $this->instances->latest();
    }

    public function hasAccountLinkedInstance(): bool
    {
        $instance = $this->instance();

        return $instance instanceof MarketplaceInstance
            && $instance->connection_mode === MarketplaceConnectionMode::AccountLinked
            && is_string($instance->account_id)
            && $instance->account_id !== '';
    }

    /** @return array<int, string> */
    public function verifiedDomains(): array
    {
        return [];
    }

    public function hasVerifiedDomains(): bool
    {
        return false;
    }

    /** @return array<int, string> */
    public function accountLinkedDomains(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function domainStatuses(): array
    {
        return [];
    }

    public function startAccountConnection(): ?string
    {
        try {
            $approvalUrl = StartMarketplaceAccountConnectionAction::run();
        } catch (Throwable $throwable) {
            Log::warning('capell-marketplace: marketplace account connection failed', ['error' => $throwable->getMessage()]);

            return $this->capellAppAccountUrl();
        }

        return $approvalUrl;
    }

    public function runHeartbeat(): void
    {
        $phoneHome = resolve(PhoneHomeAction::class);

        if (! $phoneHome->handle()) {
            $notification = Notification::make('marketplace-error')
                ->title((string) __('capell-marketplace::marketplace.install.heartbeat_failed'))
                ->body((string) __('capell-marketplace::marketplace.install.heartbeat_failed_body', [
                    'reason' => $phoneHome->failureMessage() ?? (string) __('capell-marketplace::marketplace.install.heartbeat_default_failure'),
                ]))
                ->danger()
                ->persistent();

            $troubleshootingUrl = config('capell-marketplace.marketplace.troubleshooting_url');

            if (is_string($troubleshootingUrl) && $troubleshootingUrl !== '') {
                $notification->actions([
                    Action::make('marketplaceHeartbeatDocs')
                        ->label((string) __('capell-marketplace::marketplace.install.heartbeat_docs'))
                        ->icon(Heroicon::BookOpen)
                        ->link()
                        ->url($troubleshootingUrl, shouldOpenInNewTab: true),
                ]);
            }

            $notification->send();

            return;
        }

        Notification::make()
            ->title((string) __('capell-marketplace::marketplace.install.heartbeat_completed'))
            ->success()
            ->send();
    }

    public function canStartRegistration(): bool
    {
        return $this->marketplaceBaseUrlConfigured();
    }

    public function canUseConnectionActions(): bool
    {
        $user = auth()->user();
        if (ExtensionsPage::canAccess()) {
            return true;
        }

        if ($user?->can(MarketplacePermission::ViewExtensionsPage->value) ?? false) {
            return true;
        }

        return $user?->can(MarketplacePermission::ViewMarketplacePage->value) ?? false;
    }

    public function canManageConnectionActions(): bool
    {
        return ExtensionsPage::canManageExtensions();
    }

    public function canViewConnectionDetails(): bool
    {
        $user = auth()->user();
        $configuredRole = config('capell.roles.super_admin', config('filament-shield.super_admin.name', 'super_admin'));
        $superAdminRole = is_string($configuredRole) && $configuredRole !== '' ? $configuredRole : 'super_admin';

        return is_object($user)
            && method_exists($user, 'hasRole')
            && $user->hasRole($superAdminRole);
    }

    private function marketplaceBaseUrlConfigured(): bool
    {
        $baseUrl = config('capell-marketplace.marketplace.base_url');

        return is_string($baseUrl) && $baseUrl !== '';
    }

    private function capellAppAccountUrl(): string
    {
        return MarketplaceWebUrl::resolve() . '/login';
    }
}
