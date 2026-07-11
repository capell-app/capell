<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Support;

use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class MarketplaceInstallActionPresenter
{
    public function __construct(
        private readonly MarketplaceConnectionFormModel $connectionFormModel,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public function state(array $record): MarketplaceInstallState
    {
        if ((bool) ($record['is_installed'] ?? false)) {
            return MarketplaceInstallState::Installed;
        }

        if ((bool) ($record['install_in_progress'] ?? false)) {
            return MarketplaceInstallState::Blocked;
        }

        if (! (bool) ($record['is_compatible'] ?? true)) {
            return MarketplaceInstallState::Incompatible;
        }

        $policy = $this->eligibilityPolicy($record);

        if ($policy->state instanceof MarketplaceInstallState) {
            return $policy->state;
        }

        $serverState = $this->serverInstallState($record['marketplace_install_state'] ?? $record['install_state'] ?? null);

        if ($serverState instanceof MarketplaceInstallState) {
            return $serverState;
        }

        if ((bool) ($record['install_authorized'] ?? false)) {
            return MarketplaceInstallState::Authorized;
        }

        if ((bool) ($record['activation_required'] ?? false)) {
            return MarketplaceInstallState::ActivationRequired;
        }

        if ((bool) ($record['is_paid'] ?? false) && is_string($record['purchase_url'] ?? null) && $record['purchase_url'] !== '') {
            return MarketplaceInstallState::PurchaseRequired;
        }

        return (bool) ($record['is_paid'] ?? false)
            ? MarketplaceInstallState::PurchaseRequired
            : MarketplaceInstallState::FreeAvailable;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function label(array $record): string
    {
        return match ($this->state($record)) {
            MarketplaceInstallState::PurchaseRequired => (string) __('capell-marketplace::marketplace.install.purchase_button'),
            MarketplaceInstallState::ActivationRequired => (string) __('capell-marketplace::marketplace.install.activate_button'),
            MarketplaceInstallState::Incompatible => (string) __('capell-marketplace::marketplace.install.incompatible_button'),
            MarketplaceInstallState::Blocked => (string) __('capell-marketplace::marketplace.install.blocked.' . ($this->blockReason($record) ?? 'blocked') . '.title'),
            default => (string) __('capell-marketplace::marketplace.install.button'),
        };
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function color(array $record): string
    {
        return match ($this->state($record)) {
            MarketplaceInstallState::PurchaseRequired, MarketplaceInstallState::ActivationRequired => 'warning',
            MarketplaceInstallState::Incompatible, MarketplaceInstallState::Blocked => 'gray',
            default => 'primary',
        };
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function tooltip(array $record): string
    {
        $blockReason = $this->blockReason($record);

        if ($blockReason !== null) {
            return (string) __('capell-marketplace::marketplace.install.blocked.' . $blockReason . '.tooltip');
        }

        return (string) __('capell-marketplace::marketplace.install.tooltip');
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function url(array $record): ?string
    {
        if ($this->state($record) !== MarketplaceInstallState::PurchaseRequired) {
            return null;
        }

        $purchaseUrl = $record['purchase_url'] ?? null;

        return is_string($purchaseUrl) && $purchaseUrl !== ''
            ? $this->purchaseUrlWithContext($purchaseUrl, $record)
            : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function blockReason(array $record): ?string
    {
        if ((bool) ($record['install_in_progress'] ?? false)) {
            return 'install_in_progress';
        }

        if ($this->state($record) === MarketplaceInstallState::Incompatible) {
            return 'incompatible';
        }

        $policy = $this->eligibilityPolicy($record);

        if ($policy->blocksInstall()) {
            return $policy->blockReason ?? 'blocked';
        }

        if ($this->state($record) === MarketplaceInstallState::PurchaseRequired
            && ! $this->hasUsablePurchaseUrl($record)) {
            return 'checkout_unavailable';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function sendBlockedNotification(array $record): bool
    {
        $reason = $this->blockReason($record);

        if ($reason === null) {
            return false;
        }

        $notification = Notification::make()
            ->warning()
            ->title((string) __('capell-marketplace::marketplace.install.blocked.' . $reason . '.title'))
            ->body((string) __('capell-marketplace::marketplace.install.blocked.' . $reason . '.body'));

        if (in_array($reason, ['not_connected', 'account_required', 'email_verification_required'], true)) {
            $notification
                ->actions([
                    Action::make('connectMarketplace')
                        ->label((string) __('capell-marketplace::marketplace.marketplace.connect_button'))
                        ->icon(Heroicon::OutlinedLink)
                        ->color('warning')
                        ->link()
                        ->close()
                        ->dispatch('connect-marketplace'),
                ])
                ->persistent();
        }

        $notification->send();

        return true;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function purchaseUrlWithContext(string $purchaseUrl, array $record): ?string
    {
        $purchaseUrl = $this->trustedMarketplaceUrl($purchaseUrl);

        if ($purchaseUrl === null) {
            return null;
        }

        $context = [
            'source' => 'capell_admin',
            'return_url' => request()->fullUrl(),
            'composer_name' => is_string($record['composer_name'] ?? null) ? $record['composer_name'] : null,
            'instance_id' => $this->connectionFormModel->instance()?->instance_id,
            'account_id' => $this->connectionFormModel->instance()?->account_id,
        ];
        $context = array_filter($context, fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($context === []) {
            return $purchaseUrl;
        }

        return $purchaseUrl . (str_contains($purchaseUrl, '?') ? '&' : '?') . http_build_query($context);
    }

    private function serverInstallState(mixed $installState): ?MarketplaceInstallState
    {
        if (! is_string($installState) || $installState === '') {
            return null;
        }

        return MarketplaceInstallState::tryFrom($installState);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function hasUsablePurchaseUrl(array $record): bool
    {
        $purchaseUrl = $record['purchase_url'] ?? null;

        return is_string($purchaseUrl)
            && $purchaseUrl !== ''
            && $this->trustedMarketplaceUrl($purchaseUrl) !== null;
    }

    private function trustedMarketplaceUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $urlParts = parse_url($url);

        if (! is_array($urlParts)) {
            return null;
        }

        $schemeValue = $urlParts['scheme'] ?? '';
        $hostValue = $urlParts['host'] ?? '';
        $scheme = is_string($schemeValue) ? strtolower($schemeValue) : '';
        $host = is_string($hostValue) ? strtolower($hostValue) : '';

        if ($scheme !== 'https' || $host === '') {
            return null;
        }

        return in_array($host, $this->trustedMarketplaceHosts(), true) ? $url : null;
    }

    /** @return list<string> */
    private function trustedMarketplaceHosts(): array
    {
        return array_values(collect([
            config('capell-marketplace.marketplace.web_url'),
            config('capell.marketplace_web_url'),
            config('capell-marketplace.marketplace.base_url'),
        ])
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(function (string $url): string {
                $host = parse_url($url, PHP_URL_HOST);

                return is_string($host) ? strtolower($host) : '';
            })
            ->filter(fn (string $host): bool => $host !== '')
            ->unique()
            ->values()
            ->all());
    }

    /** @param array<string, mixed> $record */
    private function eligibilityPolicy(array $record): MarketplaceInstallEligibilityData
    {
        $payload = $record['install_eligibility_policy']
            ?? $record['install_eligibility']
            ?? $record['eligibility']
            ?? null;

        return MarketplaceInstallEligibilityData::fromPayload($payload, $this->protectedInstall($record));
    }

    /** @param array<string, mixed> $record */
    private function protectedInstall(array $record): bool
    {
        return (bool) ($record['is_paid'] ?? false)
            || (bool) ($record['activation_required'] ?? false);
    }
}
