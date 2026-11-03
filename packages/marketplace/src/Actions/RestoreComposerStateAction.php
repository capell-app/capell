<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\Extensions\HashExtensionContentsAction;
use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Marketplace\Support\MarketplaceComposerAuthWorkspace;
use Capell\Marketplace\Support\MarketplaceComposerEnvironment;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Put composer.json, composer.lock and vendor/ back to the snapshot.
 *
 * The recovery sequence itself lives in ComposerStateSnapshot, shared with the
 * package removal that first proved it. This action exists to give the
 * Marketplace side the environment its other Composer subprocesses run under —
 * same cache directory, same memory limit, same proxy settings — so a rollback
 * does not re-download everything the failed operation had already cached, or
 * fail to reach the network at all on a host that needs a proxy.
 *
 * It throws on failure. A rollback that could not complete is the one thing the
 * caller must never treat as handled.
 */
final class RestoreComposerStateAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, string|null>  $expectedContentHashes  Pre-operation content hash per changed package;
     *                                                             null means the package was not installed before.
     * @return bool Without expected hashes: whether vendor/ actually had to be
     *              rebuilt (false means the manifests were never changed). With
     *              them: whether every listed package now holds exactly the
     *              bytes it held before the operation.
     */
    public function handle(
        ComposerStateSnapshot $snapshot,
        int $timeoutSeconds = ComposerStateSnapshot::DEFAULT_TIMEOUT_SECONDS,
        array $expectedContentHashes = [],
    ): bool {
        if ($expectedContentHashes !== []) {
            $this->restore($snapshot, $timeoutSeconds);

            return $this->restoredBytesMatch($expectedContentHashes);
        }

        return $this->restore($snapshot, $timeoutSeconds);
    }

    private function restore(ComposerStateSnapshot $snapshot, int $timeoutSeconds): bool
    {
        // A failure before Composer ever wrote anything — a lifecycle action
        // that threw on a package that was already downloaded, say — leaves the
        // manifests untouched. Rebuilding vendor/ then would spend minutes and a
        // network connection reproducing the state that is already on disk, at
        // the worst possible moment.
        if ($snapshot->matchesDisk()) {
            return false;
        }

        $snapshot->restoreFiles();

        $environment = new MarketplaceComposerEnvironment;
        $composerHome = storage_path('framework/composer');
        $authWorkspace = new MarketplaceComposerAuthWorkspace;
        $authWorkspace->ensureDirectory($composerHome);
        $authWorkspace->ensureDirectory($environment->cacheDirectory());

        $snapshot->restoreInstalledPackages(
            processFactory: resolve(ProcessFactoryInterface::class),
            timeoutSeconds: max(1, $timeoutSeconds),
            environment: $environment->variables($composerHome),
        );

        return true;
    }

    /** @param array<string, string|null> $expectedContentHashes */
    private function restoredBytesMatch(array $expectedContentHashes): bool
    {
        $vendorPath = base_path('vendor');
        $installed = CapturePreOperationPackageStateAction::installedPackages($vendorPath);
        if ($installed === null) {
            return false;
        }

        foreach ($expectedContentHashes as $name => $expectedHash) {
            $package = $installed[$name] ?? null;
            if ($expectedHash === null) {
                if ($package !== null) {
                    return false;
                }

                continue;
            }

            $path = $package === null ? null : CapturePreOperationPackageStateAction::installPath($vendorPath, $package);
            if ($path === null) {
                return false;
            }

            try {
                if (! hash_equals($expectedHash, HashExtensionContentsAction::run($path))) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
