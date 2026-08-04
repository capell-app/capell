<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Data\MarketplaceReadinessCheckData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Composer\InstalledVersions;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Per-attempt install preflight, prefixed with the host-level readiness checks.
 *
 * The readiness evaluation is the canonical answer about the host; this action
 * adds only the facts that depend on the attempt itself.
 */
final class RunMarketplaceInstallPreflightChecksAction
{
    use AsFake;
    use AsObject;

    /**
     * Readiness entries are prefixed so they cannot collide with the per-attempt
     * checks, which ask narrower questions under similar names.
     */
    private const string READINESS_PREFIX = 'environment_';

    /**
     * @return array{passed: bool, checks: list<array{name: string, passed: bool, message: string, remediation: string|null, docs_anchor: string|null}>}
     */
    public function handle(MarketplaceInstallAttempt $attempt): array
    {
        $readiness = EvaluateMarketplaceEnvironmentReadinessAction::run();

        $checks = [
            ...array_map(
                fn (MarketplaceReadinessCheckData $check): array => $this->readinessCheck($check),
                $readiness->checks,
            ),
            $this->check('php_cli', is_string(new ExecutableFinder()->find('php'))),
            $this->check('composer_binary', is_string(new ExecutableFinder()->find('composer'))),
            $this->check('composer_json', is_file(base_path('composer.json')) && is_writable(base_path('composer.json'))),
            $this->check('composer_lock', ! is_file(base_path('composer.lock')) || is_writable(base_path('composer.lock'))),
            $this->check('package_not_installed', ! $this->packageAlreadyInstalled($attempt->composer_name) || $this->allowsInstalledPackageRetry($attempt)),
            $this->check('no_duplicate_active_install', ! $this->hasDuplicateActiveInstall($attempt)),
            $this->check('queue_ready', config('queue.default') !== null),
            // The queue retry_after rule is not repeated here: readiness owns it
            // as `environment_timeout_chain`, and a user must not be shown two
            // differently worded failures for one condition.
        ];

        $passed = collect($checks)->every(fn (array $check): bool => $check['passed']);

        foreach ($checks as $check) {
            RecordMarketplaceInstallAttemptEventAction::run(
                attempt: $attempt,
                level: $check['passed'] ? MarketplaceInstallAttemptEventLevel::Success : MarketplaceInstallAttemptEventLevel::Error,
                message: $check['message'],
                stage: MarketplaceInstallFailureStage::Preflight,
                context: ['check' => $check['name']],
            );
        }

        return [
            'passed' => $passed,
            'checks' => $checks,
        ];
    }

    /** @return array{name: string, passed: bool, message: string, remediation: string|null, docs_anchor: string|null} */
    private function readinessCheck(MarketplaceReadinessCheckData $check): array
    {
        // A warning is honest reporting, not a reason to refuse the attempt.
        return [
            'name' => self::READINESS_PREFIX . $check->key,
            'passed' => ! $check->failed(),
            'message' => $check->message,
            'remediation' => $check->remediation,
            'docs_anchor' => $check->docsAnchor,
        ];
    }

    /** @return array{name: string, passed: bool, message: string, remediation: string|null, docs_anchor: string|null} */
    private function check(string $name, bool $passed): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'message' => (string) __('capell-marketplace::marketplace.readiness.preflight.' . $name . ($passed ? '_pass' : '_fail')),
            'remediation' => $passed
                ? null
                : (string) __('capell-marketplace::marketplace.readiness.preflight.' . $name . '_remediation'),
            'docs_anchor' => $passed ? null : str_replace('_', '-', $name),
        ];
    }

    private function packageAlreadyInstalled(string $composerName): bool
    {
        if (CapellCore::hasPackage($composerName)) {
            return CapellCore::isPackageInstalled($composerName);
        }

        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($composerName);
    }

    private function hasDuplicateActiveInstall(MarketplaceInstallAttempt $attempt): bool
    {
        return MarketplaceInstallAttempt::query()
            ->whereKeyNot($attempt->getKey())
            ->where('composer_name', $attempt->composer_name)
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->exists();
    }

    private function allowsInstalledPackageRetry(MarketplaceInstallAttempt $attempt): bool
    {
        if ($attempt->retry_of_id === null) {
            return false;
        }

        return MarketplaceInstallAttempt::query()
            ->whereKey($attempt->retry_of_id)
            ->where('failure_type', MarketplaceInstallFailureType::CancelledAfterComposer->value)
            ->exists();
    }
}
