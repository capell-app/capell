<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Data\PackageAdmissionData;
use Capell\Core\Enums\ExtensionProviderRecoveryStateEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Closure;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Runs a package's side-effect work only while the package is enabled in durable
 * state, read fresh rather than from this process's cached lifecycle.
 *
 * The lifecycle record is read with a shared lock inside a transaction on its own
 * connection, and the work runs inside that same transaction. Disable, quarantine,
 * uninstall and re-enable take an exclusive lock on the same record, so a lifecycle
 * change waits for admitted work to commit, and work admitted after it reads the
 * committed state. Inside an ambient transaction the lock is held until the outermost
 * commit. The lock covers only writes on the lifecycle connection (the default
 * connection); work that writes through another connection is not protected.
 *
 * @method static PackageAdmissionData run(string $package, Closure $work)
 */
final class RunUnderPackageAdmissionAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly ExtensionLifecycleRepository $lifecycle) {}

    public function handle(string $package, Closure $work): PackageAdmissionData
    {
        if (! CapellCore::hasPackage($package)) {
            return PackageAdmissionData::refused('unknown-package');
        }

        if (app()->resolved(InstalledRuntimeLifecycle::class)
            && resolve(InstalledRuntimeLifecycle::class)->packageFailed($package)) {
            return PackageAdmissionData::refused('runtime-failed');
        }

        if (CapellCore::getPackage($package)->isCore()) {
            return CapellCore::isPackageEnabled($package)
                ? PackageAdmissionData::admitted($work())
                : PackageAdmissionData::refused('disabled');
        }

        return DB::connection($this->lifecycle->connectionName())->transaction(function () use ($package, $work): PackageAdmissionData {
            $refusal = $this->refusal($this->lifecycle->freshRecordForAdmission($package));

            return $refusal === null
                ? PackageAdmissionData::admitted($work())
                : PackageAdmissionData::refused($refusal);
        });
    }

    private function refusal(?CapellExtension $extension): ?string
    {
        if (! $extension instanceof CapellExtension) {
            return 'unavailable';
        }

        if ($extension->provider_recovery_state === ExtensionProviderRecoveryStateEnum::Quarantined) {
            return 'quarantined';
        }

        if ($extension->status !== ExtensionStatusEnum::Enabled) {
            return $extension->status->value;
        }

        return ResolveExtensionRuntimeGateAction::run($extension)->allowed ? null : 'runtime-gate';
    }
}
