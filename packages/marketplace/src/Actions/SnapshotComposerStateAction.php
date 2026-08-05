<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Take the "before" copy of composer.json and composer.lock that a rollback
 * needs, before anything is allowed to touch them.
 *
 * Operation-agnostic on purpose: install, update and uninstall all mutate the
 * same two files, so they all snapshot the same way.
 */
final class SnapshotComposerStateAction
{
    use AsFake;
    use AsObject;

    public function handle(): ComposerStateSnapshot
    {
        return ComposerStateSnapshot::capture(resolve(Filesystem::class));
    }
}
