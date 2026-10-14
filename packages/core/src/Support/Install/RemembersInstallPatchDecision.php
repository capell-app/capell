<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

/**
 * An install patch whose confirmation answer outlives the current run, so later
 * resumed or non-interactive installs keep a declined patch skipped until the
 * user opts in again.
 */
interface RemembersInstallPatchDecision
{
    public function rememberDecision(bool $accepted): void;

    public function declined(): bool;
}
