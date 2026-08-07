<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Theme;
use Capell\Marketplace\Actions\ApplyRequestedThemeActivationAction;
use Capell\Marketplace\Actions\NotifyMarketplaceInstallCompletedAction;
use Capell\Marketplace\Actions\PersistMarketplaceActivationAction;
use Capell\Marketplace\Actions\PropagateMarketplaceRuntimeStateAction;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Override;
use RuntimeException;
use Throwable;

final class RunMarketplaceInstallAttemptJob extends AbstractMarketplaceOperationJob
{
    private bool $activationOnly = false;

    /**
     * Queue, lock, snapshot, budget and rollback all live in the base class.
     * What is left here is the part that is genuinely about installing: running
     * the package lifecycle, and everything the operator is told once a fresh
     * extension exists on this site.
     */
    #[Override]
    protected function progressTotal(): int
    {
        return 5;
    }

    #[Override]
    protected function stageProgress(MarketplaceInstallFailureStage $stage): int
    {
        return match ($stage) {
            MarketplaceInstallFailureStage::Preflight,
            MarketplaceInstallFailureStage::Queue => 0,
            MarketplaceInstallFailureStage::Composer => 1,
            MarketplaceInstallFailureStage::PackageDiscovery => 2,
            MarketplaceInstallFailureStage::Lifecycle,
            MarketplaceInstallFailureStage::Migration => 3,
            MarketplaceInstallFailureStage::HealthCheck => 4,
            MarketplaceInstallFailureStage::Notification,
            MarketplaceInstallFailureStage::DeploymentHandoff => 5,
        };
    }

    /**
     * A package already in vendor/ is the whole point of an install, so there is
     * nothing for Composer to do when the deployment pipeline has already put it
     * there.
     */
    #[Override]
    protected function mayReuseDownloadedPackage(): bool
    {
        return true;
    }

    #[Override]
    protected function shouldSkipComposerStage(MarketplaceInstallAttempt $attempt): bool
    {
        $this->activationOnly = ($attempt->context['activation_only'] ?? false) === true;

        return $this->activationOnly
            || parent::shouldSkipComposerStage($attempt);
    }

    #[Override]
    protected function composerSkippedTranslationKey(): string
    {
        return $this->activationOnly
            ? 'timeline_composer_skipped_activation_only'
            : 'timeline_composer_skipped_downloaded';
    }

    #[Override]
    protected function applyOperation(MarketplaceInstallAttempt $attempt): void
    {
        if (($attempt->context['activation_only'] ?? false) === true) {
            PersistMarketplaceActivationAction::run($attempt);

            return;
        }

        $this->reloadPackageRegistry();
        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_registry_reloaded', MarketplaceInstallFailureStage::PackageDiscovery);

        if (! CapellCore::hasPackage($attempt->composer_name)) {
            throw new RuntimeException(sprintf('Installed package [%s] was not discovered by Capell.', $attempt->composer_name));
        }

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_package_discovered', MarketplaceInstallFailureStage::PackageDiscovery);

        $package = CapellCore::getPackage($attempt->composer_name);

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_lifecycle_started', MarketplaceInstallFailureStage::Lifecycle);
        InstallPackageAction::run($package, [], null, false);
        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_lifecycle_completed', MarketplaceInstallFailureStage::Lifecycle);
        PersistMarketplaceActivationAction::run($attempt);
    }

    /**
     * Each side effect here is reported and recorded on the timeline rather than
     * rethrown, because a runtime refresh or a notification that failed is worth
     * telling the operator about and never worth retracting a good install for.
     */
    #[Override]
    protected function announceSucceededAttempt(MarketplaceInstallAttempt $attempt): void
    {
        // Every other process serving this application is still running the
        // code from before the install, so this happens before the operator
        // is told the install is done — and what it could not reach travels
        // with that notification.
        $runtimeNotice = null;

        // What the operator ticked on the review screen, honoured here because
        // here is the first moment the theme actually exists on disk. Reported
        // and never rethrown: the install itself succeeded, and a theme that
        // could not be applied is worth telling the operator about but never
        // worth retracting a good install for.
        try {
            $activatedTheme = ApplyRequestedThemeActivationAction::run($attempt);

            if ($activatedTheme instanceof Theme) {
                $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_theme_activated', MarketplaceInstallFailureStage::Notification, [
                    'theme' => $activatedTheme->key,
                ]);
            }
        } catch (Throwable $throwable) {
            report($throwable);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_theme_activation_failed', MarketplaceInstallFailureStage::Notification, [
                'reason' => $throwable->getMessage(),
            ]);
        }

        try {
            $runtimeNotice = PropagateMarketplaceRuntimeStateAction::run($attempt);
        } catch (Throwable $throwable) {
            report($throwable);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_runtime_propagation_failed', MarketplaceInstallFailureStage::Notification, [
                'reason' => $throwable->getMessage(),
            ]);
        }

        try {
            NotifyMarketplaceInstallCompletedAction::run($attempt->refresh(), $runtimeNotice);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_notification_sent', MarketplaceInstallFailureStage::Notification);
        } catch (Throwable $throwable) {
            report($throwable);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_notification_failed', MarketplaceInstallFailureStage::Notification, [
                'reason' => $throwable->getMessage(),
            ]);
        }
    }
}
