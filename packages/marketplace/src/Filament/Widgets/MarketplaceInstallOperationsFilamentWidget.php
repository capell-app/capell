<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Widgets;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\ListMarketplaceInstallFlowSessionsAction;
use Capell\Marketplace\Actions\ListMarketplaceInstallOperationsAction;
use Capell\Marketplace\Actions\MarketplaceInstallFlowSessionTransitionAction;
use Capell\Marketplace\Actions\ResumeMarketplaceInstallFlowAction;
use Capell\Marketplace\Enums\MarketplaceInstallFlowSessionStatus;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallFlowSession;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Throwable;

final class MarketplaceInstallOperationsFilamentWidget extends Widget implements CapellFilamentWidgetContract
{
    use GatedByRoleAndSettings;

    private const string TAB_ACTIVE = 'active';

    private const string TAB_FAILED = 'failed';

    private const string TAB_ALL = 'all';

    public string $operationsTab = self::TAB_ACTIVE;

    public ?int $expandedOperationLogId = null;

    /** @var list<string> */
    protected static array $rolesConfigKeys = [];

    protected static string $settingsKey = '';

    protected string $view = 'capell-marketplace::filament.widgets.install-operations';

    protected int|string|array $columnSpan = ['default' => null, 'md' => 12, 'lg' => 12, 'xl' => 12];

    protected static ?int $sort = 15;

    #[Computed(persist: true, seconds: 15)]
    public function operations(): Collection
    {
        return ListMarketplaceInstallOperationsAction::run();
    }

    #[Computed]
    public function visibleOperations(): Collection
    {
        $operations = $this->operations();

        return match ($this->operationsTab) {
            self::TAB_FAILED => $operations->filter(
                fn (MarketplaceInstallAttempt $attempt): bool => in_array($attempt->status, [
                    MarketplaceInstallIntentStatus::Failed,
                    MarketplaceInstallIntentStatus::TimedOut,
                    MarketplaceInstallIntentStatus::Cancelled,
                ], true),
            ),
            self::TAB_ALL => $operations,
            default => $operations->filter(
                fn (MarketplaceInstallAttempt $attempt): bool => in_array($attempt->status, [
                    MarketplaceInstallIntentStatus::Queued,
                    MarketplaceInstallIntentStatus::Running,
                    MarketplaceInstallIntentStatus::CancelRequested,
                ], true),
            ),
        };
    }

    /**
     * @return Collection<int, MarketplaceInstallFlowSession>
     */
    #[Computed(persist: true, seconds: 15)]
    public function flowSessions(): Collection
    {
        return ListMarketplaceInstallFlowSessionsAction::run();
    }

    public function cancel(int $attemptId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $attempt = MarketplaceInstallAttempt::query()->find($attemptId);

        if (! $attempt instanceof MarketplaceInstallAttempt || ! $this->canCancel($attempt)) {
            return;
        }

        CancelMarketplaceInstallAttemptAction::run($attempt);

        unset($this->operations);
    }

    public function setOperationsTab(string $tab): void
    {
        if (! in_array($tab, [self::TAB_ACTIVE, self::TAB_FAILED, self::TAB_ALL], true)) {
            return;
        }

        $this->operationsTab = $tab;
        $this->expandedOperationLogId = null;
    }

    public function toggleOperationLog(int $attemptId): void
    {
        $this->expandedOperationLogId = $this->expandedOperationLogId === $attemptId
            ? null
            : $attemptId;
    }

    public function resumeFlowSession(int $sessionId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $session = MarketplaceInstallFlowSession::query()->find($sessionId);

        if (! $session instanceof MarketplaceInstallFlowSession || ! $this->canResumeFlowSession($session)) {
            return;
        }

        try {
            ResumeMarketplaceInstallFlowAction::run($session);

            Notification::make()
                ->success()
                ->title((string) __('capell-marketplace::marketplace.operations.flow_resumed'))
                ->body((string) __('capell-marketplace::marketplace.operations.flow_resumed_body'))
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title((string) __('capell-marketplace::marketplace.operations.flow_resume_failed'))
                ->body($throwable->getMessage())
                ->send();
        }

        unset($this->operations, $this->flowSessions);
    }

    public function expireFlowSession(int $sessionId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $session = MarketplaceInstallFlowSession::query()->find($sessionId);

        if (! $session instanceof MarketplaceInstallFlowSession || ! $this->canExpireFlowSession($session)) {
            return;
        }

        MarketplaceInstallFlowSessionTransitionAction::run(
            $session,
            MarketplaceInstallFlowSessionStatus::Expired,
            'expired_from_package_operations',
        );

        unset($this->flowSessions);
    }

    public function canCancel(MarketplaceInstallAttempt $attempt): bool
    {
        if (! $this->canManagePackageOperations()) {
            return false;
        }

        return in_array($attempt->status, [
            MarketplaceInstallIntentStatus::Queued,
            MarketplaceInstallIntentStatus::Running,
        ], true);
    }

    public function canResumeFlowSession(MarketplaceInstallFlowSession $session): bool
    {
        if (! $this->canManagePackageOperations()) {
            return false;
        }

        return in_array($session->status, [
            MarketplaceInstallFlowSessionStatus::Returned,
            MarketplaceInstallFlowSessionStatus::Failed,
        ], true);
    }

    public function canExpireFlowSession(MarketplaceInstallFlowSession $session): bool
    {
        if (! $this->canManagePackageOperations()) {
            return false;
        }

        return in_array($session->status, [
            MarketplaceInstallFlowSessionStatus::Redirected,
            MarketplaceInstallFlowSessionStatus::Authorizing,
            MarketplaceInstallFlowSessionStatus::Returned,
            MarketplaceInstallFlowSessionStatus::Failed,
        ], true);
    }

    public function flowSessionStatusLabel(MarketplaceInstallFlowSession $session): string
    {
        return (string) __('capell-marketplace::marketplace.operations.flow_status.' . $session->status->value);
    }

    public function flowSessionSupportReference(MarketplaceInstallFlowSession $session): string
    {
        return $session->remote_flow_id
            ?: (string) __('capell-marketplace::marketplace.install_flow.unknown_support_reference');
    }

    public function flowSessionAccountEmail(MarketplaceInstallFlowSession $session): string
    {
        $exchangeEmail = data_get($session->last_exchange_payload, 'account.account_email');
        $userEmail = data_get($session->user_context, 'user_email');

        if (is_string($exchangeEmail) && $exchangeEmail !== '') {
            return $exchangeEmail;
        }

        if (is_string($userEmail) && $userEmail !== '') {
            return $userEmail;
        }

        return '-';
    }

    public function flowSessionLastSafeAction(MarketplaceInstallFlowSession $session): string
    {
        if ($this->canResumeFlowSession($session)) {
            return (string) __('capell-marketplace::marketplace.operations.flow_last_safe_actions.resume');
        }

        if ($this->canExpireFlowSession($session)) {
            return (string) __('capell-marketplace::marketplace.operations.flow_last_safe_actions.expire');
        }

        if ($session->status === MarketplaceInstallFlowSessionStatus::Expired) {
            return (string) __('capell-marketplace::marketplace.operations.flow_last_safe_actions.start_again');
        }

        return (string) __('capell-marketplace::marketplace.operations.flow_last_safe_actions.none');
    }

    public function canManagePackageOperations(): bool
    {
        return ExtensionsPage::canManageExtensions();
    }

    public function activeOperationsCount(): int
    {
        return $this->operations()
            ->filter(fn (MarketplaceInstallAttempt $attempt): bool => in_array($attempt->status, [
                MarketplaceInstallIntentStatus::Queued,
                MarketplaceInstallIntentStatus::Running,
                MarketplaceInstallIntentStatus::CancelRequested,
            ], true))
            ->count();
    }

    public function failedOperationsCount(): int
    {
        return $this->operations()
            ->filter(fn (MarketplaceInstallAttempt $attempt): bool => in_array($attempt->status, [
                MarketplaceInstallIntentStatus::Failed,
                MarketplaceInstallIntentStatus::TimedOut,
                MarketplaceInstallIntentStatus::Cancelled,
            ], true))
            ->count();
    }

    public function hasOperationLogs(MarketplaceInstallAttempt $attempt): bool
    {
        return collect($this->operationLogEntries($attempt))
            ->contains(fn (array $entry): bool => $entry['content'] !== '');
    }

    /**
     * @return array<int, array{label: string, content: string}>
     */
    public function operationLogEntries(MarketplaceInstallAttempt $attempt): array
    {
        $deployment = is_array($attempt->deployment) ? $attempt->deployment : [];
        $deploymentReason = is_string($deployment['failure_reason'] ?? null) ? $deployment['failure_reason'] : '';

        return [
            [
                'label' => (string) __('capell-marketplace::marketplace.operations.log_failure_reason'),
                'content' => trim((string) $attempt->failure_reason),
            ],
            [
                'label' => (string) __('capell-marketplace::marketplace.operations.log_error_output'),
                'content' => trim((string) $attempt->error_excerpt),
            ],
            [
                'label' => (string) __('capell-marketplace::marketplace.operations.log_standard_output'),
                'content' => trim((string) $attempt->output_excerpt),
            ],
            [
                'label' => (string) __('capell-marketplace::marketplace.operations.log_deployment'),
                'content' => trim($deploymentReason),
            ],
        ];
    }
}
