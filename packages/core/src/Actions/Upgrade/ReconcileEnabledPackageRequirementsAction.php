<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\Install\RunDeclaredPackageMigrationsAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Data\PackageRequirementData;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Capell\Core\Support\Upgrade\UpgradePipelineIo;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class ReconcileEnabledPackageRequirementsAction
{
    use AsFake;
    use AsObject;

    public function handle(UpgradeRunOptions $options, UpgradePipelineIo $io, bool $validateOnly = false): bool
    {
        /** @var array<string, PackageRequirementData> $repairs */
        $repairs = [];
        $checked = [];

        try {
            // Validate the entire closure before any lifecycle writes. Existing
            // enabled dependencies may themselves have gained new requirements.
            foreach (CapellCore::getPackages(withoutCore: false) as $package) {
                if (CapellCore::isPackageEnabled($package->name)) {
                    $this->collectRepairs($package, $options, $checked, $repairs);
                }
            }
        } catch (RuntimeException $runtimeException) {
            $io->error($runtimeException->getMessage());

            return false;
        }

        if ($validateOnly) {
            return true;
        }

        foreach ($repairs as $repair) {
            if ($options->dryRun) {
                $io->warn(__('capell-core::package-requirements.upgrade_dry_run', $repair->toArray()));

                continue;
            }

            try {
                $package = CapellCore::getPackage($repair->requirement);
                $extension = $this->extension($package->name);
                if ($extension?->installed_at !== null && $extension->status === ExtensionStatusEnum::Disabled) {
                    // Disabled packages are excluded from the normal upgrade
                    // migration pass, but their runtime needs its current schema.
                    RunDeclaredPackageMigrationsAction::run($package, new NullProgressReporter);
                    EnablePackageAction::run($package);
                } else {
                    $arguments = ['--no-interaction' => true];
                    InstallPackageAction::run($package, arguments: $arguments);
                }

                if (! CapellCore::isPackageEnabled($package->name)) {
                    throw new RuntimeException(__('capell-core::package-requirements.not_enabled', $repair->toArray()));
                }

                $io->info(__('capell-core::package-requirements.upgrade_repaired', $repair->toArray()));
            } catch (Throwable $exception) {
                $io->error(__('capell-core::package-requirements.upgrade_failed', [...$repair->toArray(), 'reason' => $exception->getMessage()]));

                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, true>  $checked
     * @param  array<string, PackageRequirementData>  $repairs
     * @param  list<string>  $ancestors
     */
    private function collectRepairs(PackageData $package, UpgradeRunOptions $options, array &$checked, array &$repairs, array $ancestors = []): void
    {
        if (isset($checked[$package->name])) {
            return;
        }

        foreach ($package->getRequirements() as $requirement) {
            if (TrustedCorePackages::isCoreRuntimePackage($requirement)) {
                continue;
            }

            $repair = new PackageRequirementData($package->name, $requirement);
            if (in_array($requirement, [...$ancestors, $package->name], true)) {
                throw new RuntimeException(__('capell-core::package-requirements.circular', $repair->toArray()));
            }

            if (! CapellCore::hasPackage($requirement)) {
                throw new RuntimeException(__('capell-core::package-requirements.source_missing', $repair->toArray()));
            }

            $enabled = CapellCore::isPackageEnabled($requirement);
            if (! $enabled) {
                if ($options->interactive || ! str_starts_with($requirement, 'capell-app/')) {
                    throw new RuntimeException(__('capell-core::package-requirements.manual_required', $repair->toArray()));
                }

                $extension = $this->extension($requirement);
                if ($extension instanceof CapellExtension && (in_array($extension->status, [ExtensionStatusEnum::Failed, ExtensionStatusEnum::Installing], true)
                    || $extension->provider_recovery_state !== ExtensionProviderRecoveryStateEnum::Healthy
                    || resolve(ExtensionLifecycleRepository::class)->runtimeGateAllows($requirement, CapellCore::getPackages(withoutCore: false)) === false)) {
                    throw new RuntimeException(__('capell-core::package-requirements.manual_required', $repair->toArray()));
                }
            }

            $this->collectRepairs(CapellCore::getPackage($requirement), $options, $checked, $repairs, [...$ancestors, $package->name]);
            if (! $enabled) {
                $repairs[$requirement] ??= $repair;
            }
        }

        $checked[$package->name] = true;
    }

    private function extension(string $name): ?CapellExtension
    {
        return Schema::hasTable('capell_extensions')
            ? CapellExtension::query()->where('composer_name', $name)->first()
            : null;
    }
}
