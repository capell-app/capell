<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Widgets;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\BuildMarketplaceInstallOperationsSummaryAction;
use Capell\Marketplace\Actions\ListMarketplaceInstallOperationsAction;
use Capell\Marketplace\Actions\ResolveMarketplaceInstallOperationAction;
use Capell\Marketplace\Actions\RetryMarketplaceInstallAttemptAction;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

final class MarketplacePackageOperationsAlertFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = '';

    protected string $view = 'capell-marketplace::filament.widgets.package-operations-alert';

    /** @var int|string|array<string, int|string|null> */
    protected int|string|array $columnSpan = ['default' => 'full'];

    protected static ?int $sort = 18;

    #[Computed]
    public function operations(): Collection
    {
        return ListMarketplaceInstallOperationsAction::run();
    }

    public function activeCount(): int
    {
        return BuildMarketplaceInstallOperationsSummaryAction::run()->activeCount;
    }

    public function attentionCount(): int
    {
        return BuildMarketplaceInstallOperationsSummaryAction::run()->attentionCount;
    }

    public function dismiss(int $attemptId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $attempt = MarketplaceInstallAttempt::query()->find($attemptId);

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        ResolveMarketplaceInstallOperationAction::run($attempt);

        unset($this->operations);
    }

    public function retry(int $attemptId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $attempt = MarketplaceInstallAttempt::query()->find($attemptId);

        if (! $attempt instanceof MarketplaceInstallAttempt || ! $this->canRetry($attempt)) {
            return;
        }

        RetryMarketplaceInstallAttemptAction::run($attempt);

        unset($this->operations);
    }

    public function operationUrl(MarketplaceInstallAttempt $attempt): string
    {
        return MarketplacePackageOperationsPage::getUrl([
            'operation' => $attempt->getKey(),
        ]);
    }

    public function canRetry(MarketplaceInstallAttempt $attempt): bool
    {
        if (! $this->canManagePackageOperations()) {
            return false;
        }

        if (in_array($attempt->status, [
            MarketplaceInstallIntentStatus::Failed,
            MarketplaceInstallIntentStatus::TimedOut,
        ], true)) {
            return true;
        }

        return $attempt->status === MarketplaceInstallIntentStatus::Cancelled
            && $attempt->failure_type === MarketplaceInstallFailureType::CancelledAfterComposer->value;
    }

    public function canManagePackageOperations(): bool
    {
        return ExtensionsPage::canManageExtensions();
    }

    public function attentionOperations(): Collection
    {
        return $this->operations()
            ->filter(fn (MarketplaceInstallAttempt $attempt): bool => $attempt->resolved_at === null && (
                in_array($attempt->status, [
                    MarketplaceInstallIntentStatus::Failed,
                    MarketplaceInstallIntentStatus::TimedOut,
                    MarketplaceInstallIntentStatus::Cancelled,
                ], true)
                || in_array(data_get($attempt->deployment, 'status'), ['failed', 'unavailable'], true)
            ));
    }
}
