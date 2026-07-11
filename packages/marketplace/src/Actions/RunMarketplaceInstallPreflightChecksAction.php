<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Composer\InstalledVersions;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\ExecutableFinder;

final class RunMarketplaceInstallPreflightChecksAction
{
    use AsAction;

    /**
     * @return array{passed: bool, checks: list<array{name: string, passed: bool, message: string}>}
     */
    public function handle(MarketplaceInstallAttempt $attempt): array
    {
        $checks = [
            $this->check('php_cli', is_string((new ExecutableFinder)->find('php')), 'PHP CLI binary is available.'),
            $this->check('composer_binary', is_string((new ExecutableFinder)->find('composer')), 'Composer binary is available.'),
            $this->check('composer_json', is_file(base_path('composer.json')) && is_writable(base_path('composer.json')), 'composer.json is writable.'),
            $this->check('composer_lock', ! is_file(base_path('composer.lock')) || is_writable(base_path('composer.lock')), 'composer.lock is writable or absent.'),
            $this->check('package_not_installed', ! $this->packageAlreadyInstalled($attempt->composer_name) || $this->allowsInstalledPackageRetry($attempt), 'Package is not already installed in Capell or is eligible for cancel-after-Composer recovery.'),
            $this->check('no_duplicate_active_install', ! $this->hasDuplicateActiveInstall($attempt), 'No duplicate active install exists.'),
            $this->check('queue_ready', config('queue.default') !== null, 'Queue connection is configured.'),
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

    /** @return array{name: string, passed: bool, message: string} */
    private function check(string $name, bool $passed, string $successMessage): array
    {
        return [
            'name' => $name,
            'passed' => $passed,
            'message' => $passed ? $successMessage : __('capell-marketplace::marketplace.operations.preflight_failed_check', [
                'check' => str_replace('_', ' ', $name),
            ]),
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
