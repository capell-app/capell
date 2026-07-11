<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerAutoloaderReloader;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Marketplace\Actions\ClassifyMarketplaceInstallFailureAction;
use Capell\Marketplace\Actions\NotifyMarketplaceInstallCompletedAction;
use Capell\Marketplace\Actions\RecordMarketplaceInstallAttemptEventAction;
use Capell\Marketplace\Actions\RedactMarketplaceDiagnosticContextAction;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

final class RunMarketplaceInstallAttemptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const int COMPOSER_TIMEOUT_SECONDS = 600;

    public int $timeout = 720;

    public int $tries = 0;

    public function __construct(
        private readonly int $installAttemptId,
    ) {}

    public function handle(MarketplaceComposerRunner $composer): void
    {
        $lock = Cache::lock('capell-marketplace:composer-install', self::COMPOSER_TIMEOUT_SECONDS + 120);

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $this->runWithLock($composer);
        } finally {
            $lock->release();
        }
    }

    public function retryUntil(): Carbon
    {
        return now()->addHour();
    }

    public function failed(?Throwable $throwable): void
    {
        $attempt = MarketplaceInstallAttempt::query()->find($this->installAttemptId);

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        if (! $attempt->status->isActiveInstallOperation()) {
            return;
        }

        $reason = $throwable?->getMessage() ?: (string) __('capell-marketplace::marketplace.operations.queue_failed');
        $classification = ClassifyMarketplaceInstallFailureAction::run(
            stage: MarketplaceInstallFailureStage::Queue,
            throwable: $throwable,
            message: $reason,
        );

        $attempt->forceFill([
            'status' => MarketplaceInstallIntentStatus::Failed,
            'failure_reason' => $this->redactedText($reason),
            'failure_type' => $classification['failure_type']->value,
            'failure_stage' => $classification['failure_stage']->value,
            'completed_at' => now(),
            'resolved_at' => null,
        ])->save();

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Error, 'timeline_queue_failed', MarketplaceInstallFailureStage::Queue, [
            'reason' => $reason,
        ]);

    }

    private function runWithLock(MarketplaceComposerRunner $composer): void
    {
        $attempt = MarketplaceInstallAttempt::query()->find($this->installAttemptId);

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        if ($attempt->status === MarketplaceInstallIntentStatus::Cancelled) {
            return;
        }

        if ($attempt->status === MarketplaceInstallIntentStatus::CancelRequested) {
            $this->markCancelled($attempt);

            return;
        }

        if ($attempt->status !== MarketplaceInstallIntentStatus::Queued) {
            return;
        }

        $claimed = MarketplaceInstallAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', MarketplaceInstallIntentStatus::Queued->value)
            ->update([
                'status' => MarketplaceInstallIntentStatus::Running->value,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $attempt->refresh();

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_running', MarketplaceInstallFailureStage::Queue);
        if ($this->packageAlreadyAvailableForLifecycle($attempt)) {
            $result = new MarketplaceComposerResultData(
                exitCode: 0,
                output: (string) __('capell-marketplace::marketplace.operations.timeline_composer_skipped_downloaded'),
                errorOutput: '',
            );

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_composer_skipped_downloaded', MarketplaceInstallFailureStage::Composer, [
                'composer_name' => $attempt->composer_name,
            ], $result->output);
        } else {
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_composer_started', MarketplaceInstallFailureStage::Composer, [
                'composer_name' => $attempt->composer_name,
                'version_constraint' => $attempt->version_constraint ?: '*',
            ]);

            try {
                $result = $this->runComposer($composer, $attempt);
            } catch (Throwable $throwable) {
                $this->markComposerThrowable($attempt, $throwable);

                return;
            }

            $attempt->refresh();

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_composer_completed', MarketplaceInstallFailureStage::Composer, outputExcerpt: $result->output);
        }

        if ($attempt->status === MarketplaceInstallIntentStatus::CancelRequested) {
            $this->markCancelledAfterComposer($attempt, $result);

            return;
        }

        if (! $result->successful()) {
            $this->markComposerFailure($attempt, $result);

            return;
        }

        try {
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

            $attempt->forceFill([
                'status' => MarketplaceInstallIntentStatus::Succeeded,
                'failure_reason' => null,
                'failure_type' => null,
                'failure_stage' => null,
                'output_excerpt' => $this->excerpt($result->output),
                'error_excerpt' => $this->excerpt($result->errorOutput),
                'completed_at' => now(),
                'resolved_at' => $this->deploymentNeedsAttention($attempt) ? null : now(),
            ])->save();

            try {
                NotifyMarketplaceInstallCompletedAction::run($attempt->refresh());
                $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_notification_sent', MarketplaceInstallFailureStage::Notification);
            } catch (Throwable $throwable) {
                report($throwable);
                $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_notification_failed', MarketplaceInstallFailureStage::Notification, [
                    'reason' => $throwable->getMessage(),
                ]);
            }
        } catch (Throwable $throwable) {
            $classification = ClassifyMarketplaceInstallFailureAction::run(
                stage: str_contains(strtolower($throwable->getMessage()), 'not discovered')
                    ? MarketplaceInstallFailureStage::PackageDiscovery
                    : MarketplaceInstallFailureStage::Lifecycle,
                throwable: $throwable,
            );

            $attempt->forceFill([
                'status' => MarketplaceInstallIntentStatus::Failed,
                'failure_reason' => $this->redactedText($throwable->getMessage()),
                'failure_type' => $classification['failure_type']->value,
                'failure_stage' => $classification['failure_stage']->value,
                'output_excerpt' => $this->excerpt($result->output),
                'error_excerpt' => $this->excerpt($result->errorOutput),
                'completed_at' => now(),
                'resolved_at' => null,
            ])->save();

            $this->recordEvent(
                $attempt,
                MarketplaceInstallAttemptEventLevel::Error,
                $classification['failure_type'] === MarketplaceInstallFailureType::PackageNotDiscovered ? 'timeline_package_discovery_failed' : 'timeline_lifecycle_failed',
                $classification['failure_stage'],
                ['reason' => $throwable->getMessage()],
            );
        }
    }

    private function runComposer(MarketplaceComposerRunner $composer, MarketplaceInstallAttempt $attempt): MarketplaceComposerResultData
    {
        $composerAuth = $this->composerAuth($attempt);

        if ($composerAuth !== null) {
            throw_unless(
                $composer instanceof MarketplaceAuthenticatedComposerRunner,
                RuntimeException::class,
                'Marketplace Composer authentication is available but the configured composer runner does not support authentication.',
            );

            return $composer->requireWithComposerAuth(
                composerName: $attempt->composer_name,
                versionConstraint: $attempt->version_constraint ?: '*',
                timeoutSeconds: self::COMPOSER_TIMEOUT_SECONDS,
                composerAuth: $composerAuth,
            );
        }

        return $composer->require(
            composerName: $attempt->composer_name,
            versionConstraint: $attempt->version_constraint ?: '*',
            timeoutSeconds: self::COMPOSER_TIMEOUT_SECONDS,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function composerAuth(MarketplaceInstallAttempt $attempt): ?array
    {
        $context = $attempt->context ?? [];
        $encrypted = $context['composer_auth_encrypted'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Marketplace Composer authentication payload could not be decoded.', $jsonException->getCode(), previous: $jsonException);
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function markComposerFailure(MarketplaceInstallAttempt $attempt, MarketplaceComposerResultData $result): void
    {
        $status = $result->timedOut
            ? MarketplaceInstallIntentStatus::TimedOut
            : MarketplaceInstallIntentStatus::Failed;
        $reason = $result->timedOut
            ? (string) __('capell-marketplace::marketplace.operations.composer_timed_out')
            : (trim($result->errorOutput) ?: trim($result->output) ?: (string) __('capell-marketplace::marketplace.operations.composer_failed'));
        $classification = ClassifyMarketplaceInstallFailureAction::run(
            stage: MarketplaceInstallFailureStage::Composer,
            composerResult: $result,
            message: $reason,
        );

        $attempt->forceFill([
            'status' => $status,
            'failure_reason' => $this->redactedText($reason),
            'failure_type' => $classification['failure_type']->value,
            'failure_stage' => $classification['failure_stage']->value,
            'output_excerpt' => $this->excerpt($result->output),
            'error_excerpt' => $this->excerpt($result->errorOutput),
            'completed_at' => now(),
            'resolved_at' => null,
        ])->save();

        $this->recordEvent(
            $attempt,
            MarketplaceInstallAttemptEventLevel::Error,
            $result->timedOut ? 'timeline_composer_timed_out' : 'timeline_composer_failed',
            MarketplaceInstallFailureStage::Composer,
            ['reason' => $reason],
            $result->errorOutput !== '' ? $result->errorOutput : $result->output,
        );

    }

    private function markComposerThrowable(MarketplaceInstallAttempt $attempt, Throwable $throwable): void
    {
        $classification = ClassifyMarketplaceInstallFailureAction::run(
            stage: MarketplaceInstallFailureStage::Composer,
            throwable: $throwable,
        );

        $attempt->forceFill([
            'status' => MarketplaceInstallIntentStatus::Failed,
            'failure_reason' => $this->redactedText($throwable->getMessage()),
            'failure_type' => $classification['failure_type']->value,
            'failure_stage' => $classification['failure_stage']->value,
            'completed_at' => now(),
            'resolved_at' => null,
        ])->save();

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Error, 'timeline_composer_failed', MarketplaceInstallFailureStage::Composer, [
            'reason' => $throwable->getMessage(),
        ]);

    }

    private function markCancelled(MarketplaceInstallAttempt $attempt): void
    {
        $attempt->forceFill([
            'status' => MarketplaceInstallIntentStatus::Cancelled,
            'cancelled_at' => now(),
            'completed_at' => now(),
            'resolved_at' => now(),
        ])->save();

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_cancelled', MarketplaceInstallFailureStage::Queue);
    }

    private function markCancelledAfterComposer(MarketplaceInstallAttempt $attempt, MarketplaceComposerResultData $result): void
    {
        $reason = (string) __('capell-marketplace::marketplace.operations.cancelled_after_composer');

        $attempt->forceFill([
            'status' => MarketplaceInstallIntentStatus::Cancelled,
            'failure_reason' => $reason,
            'failure_type' => MarketplaceInstallFailureType::CancelledAfterComposer->value,
            'failure_stage' => MarketplaceInstallFailureStage::Composer->value,
            'output_excerpt' => $this->excerpt($result->output),
            'error_excerpt' => $this->excerpt($result->errorOutput),
            'cancelled_at' => now(),
            'completed_at' => now(),
            'resolved_at' => null,
        ])->save();

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_cancelled_after_composer', MarketplaceInstallFailureStage::Composer, [
            'reason' => $reason,
        ]);

    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordEvent(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptEventLevel $level,
        string $translationKey,
        ?MarketplaceInstallFailureStage $stage = null,
        array $context = [],
        ?string $outputExcerpt = null,
    ): void {
        RecordMarketplaceInstallAttemptEventAction::run(
            attempt: $attempt,
            level: $level,
            message: __('capell-marketplace::marketplace.operations.' . $translationKey),
            stage: $stage,
            context: $context,
            outputExcerpt: $outputExcerpt,
        );
    }

    private function packageAlreadyAvailableForLifecycle(MarketplaceInstallAttempt $attempt): bool
    {
        return CapellCore::hasPackage($attempt->composer_name)
            && CapellCore::isPackageAvailable($attempt->composer_name)
            && ! CapellCore::isPackageInstalled($attempt->composer_name);
    }

    private function reloadPackageRegistry(): void
    {
        ComposerAutoloaderReloader::reload();

        CapellCore::clearExtensionCache();

        $registry = resolve(CapellPackageRegistry::class);
        $manifests = new ManifestLoader(new ManifestValidator)->discover();
        $registry->fill($manifests);

        foreach ($manifests as $manifest) {
            CapellCore::registerManifestPackage(
                $manifest,
                CapellCore::getInstalledPrettyVersion($manifest->name),
            );
        }
    }

    private function deploymentNeedsAttention(MarketplaceInstallAttempt $attempt): bool
    {
        if (! is_array($attempt->deployment)) {
            return false;
        }

        return ($attempt->deployment['status'] ?? null) === 'failed';
    }

    private function excerpt(string $output): ?string
    {
        $output = trim($output);

        return $output === '' ? null : $this->redactedText(Str::limit($output, 4000, ''));
    }

    private function redactedText(string $text): string
    {
        $redacted = RedactMarketplaceDiagnosticContextAction::run([
            'text' => $text,
        ]);

        return is_string($redacted['text'] ?? null) ? $redacted['text'] : '[redacted]';
    }
}
