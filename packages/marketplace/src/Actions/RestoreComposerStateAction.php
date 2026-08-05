<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Marketplace\Support\MarketplaceComposerAuthWorkspace;
use Capell\Marketplace\Support\MarketplaceComposerEnvironment;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

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
     * @return bool Whether vendor/ actually had to be rebuilt. False means the
     *              manifests were never changed, so there was nothing to undo.
     */
    public function handle(
        ComposerStateSnapshot $snapshot,
        int $timeoutSeconds = ComposerStateSnapshot::DEFAULT_TIMEOUT_SECONDS,
    ): bool {
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
}
