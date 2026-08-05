<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Packages\ActiveThemeUninstallGuard;
use Capell\Marketplace\Data\MarketplaceUninstallOptionsData;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Everything that makes an uninstall impossible, asked before it is queued.
 *
 * These are not host-health checks — the queue preflight already runs those.
 * They are the refusals an operator can act on, and the reason they are asked
 * here rather than left to the job is that all three are already known at the
 * moment the button is clicked. Discovering them on a worker instead means an
 * attempt that fails minutes later, after the extension's lifecycle has already
 * run, for a reason the operator could have been told immediately.
 */
final class AssertMarketplaceUninstallAllowedAction
{
    use AsFake;
    use AsObject;

    /**
     * The Composer removal writes exactly what an install writes, and the same
     * `bootstrap/cache` manifests a removal has to invalidate.
     *
     * @var list<string>
     */
    public const array RELEASE_ROOT_PATHS = ['composer.json', 'composer.lock', 'vendor', 'bootstrap/cache'];

    public const string OPERATION = 'Removing a Marketplace extension with Composer';

    public function __construct(
        private readonly ReleaseRootWriteGuard $releaseRootWriteGuard = new ReleaseRootWriteGuard,
        private readonly ActiveThemeUninstallGuard $activeThemeUninstallGuard = new ActiveThemeUninstallGuard,
    ) {}

    public function handle(string $composerName, MarketplaceUninstallOptionsData $options): void
    {
        $reason = $this->refusalReason($composerName, $options);

        if ($reason === null) {
            return;
        }

        throw ValidationException::withMessages(['composer_name' => $reason]);
    }

    /**
     * The refusal as a value, so a surface deciding whether to *offer* an
     * uninstall asks the same question this action answers when it refuses one.
     */
    public function refusalReason(string $composerName, MarketplaceUninstallOptionsData $options): ?string
    {
        $packageNames = $options->packageNames !== [] ? $options->packageNames : [$composerName];

        if (end($packageNames) !== $composerName) {
            return (string) __('capell-marketplace::marketplace.uninstalls.invalid_package_order');
        }

        foreach ($packageNames as $offset => $packageName) {
            if (! CapellCore::hasPackage($packageName) || ($options->runLifecycle && ! CapellCore::isPackageInstalled($packageName))) {
                return (string) __('capell-marketplace::marketplace.uninstalls.not_installed', [
                    'package' => $packageName,
                ]);
            }

            $package = CapellCore::getPackage($packageName);

            if (! $options->runLifecycle) {
                continue;
            }

            $alreadyRemovedPackageNames = array_slice($packageNames, 0, $offset);
            $outsideDependents = CapellCore::getDependentInstalledPackages($packageName)
                ->pluck('name')
                ->reject(static fn (string $dependent): bool => in_array($dependent, $alreadyRemovedPackageNames, true));

            if ($outsideDependents->isNotEmpty()) {
                return (string) __('capell-marketplace::marketplace.uninstalls.blocked_by_dependents', [
                    'package' => $package->name,
                    'dependents' => $outsideDependents->implode(', '),
                ]);
            }

            $activeThemeRefusal = $this->activeThemeUninstallGuard->refusalReason($package);

            if ($activeThemeRefusal !== null) {
                return $activeThemeRefusal;
            }
        }

        return $this->releaseRootRefusal($options);
    }

    /**
     * Only asked when the operator chose to delete the package.
     *
     * An uninstall that keeps the files never touches composer.json, so
     * refusing it because the release root is immutable would block the one
     * form of uninstall an immutable host *can* perform.
     */
    private function releaseRootRefusal(MarketplaceUninstallOptionsData $options): ?string
    {
        if (! $options->deletePackage) {
            return null;
        }

        return $this->releaseRootWriteGuard->check(
            operation: self::OPERATION,
            relativePaths: self::RELEASE_ROOT_PATHS,
            // The same gate the in-request removal path carries. Queueing the
            // write does not make it attended: an HTTP request still set it in
            // motion and nobody is watching the terminal.
            requiresServerSideTooling: true,
        );
    }
}
