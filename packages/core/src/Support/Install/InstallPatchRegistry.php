<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Support\Patching\Patch;
use Closure;

/**
 * Core-owned seam for install-time application patches. Companion packages
 * (for example the installer) register patch factories from their service
 * providers; the install command evaluates them against the current install
 * selection without depending on any contributing package's classes.
 */
final class InstallPatchRegistry
{
    /** @var list<array{factory: Closure(InstallPatchContext): ?Patch, confirmation: ?InstallPatchConfirmation}> */
    private array $contributions = [];

    /**
     * @param  callable(InstallPatchContext): ?Patch  $factory  Return the patch when it applies to the context, or null to skip.
     */
    public function register(callable $factory, ?InstallPatchConfirmation $confirmation = null): void
    {
        $this->contributions[] = [
            'factory' => Closure::fromCallable($factory),
            'confirmation' => $confirmation,
        ];
    }

    /**
     * @return list<RegisteredInstallPatch>
     */
    public function patchesFor(InstallPatchContext $context): array
    {
        $registeredPatches = [];

        foreach ($this->contributions as $contribution) {
            $patch = ($contribution['factory'])($context);

            if (! $patch instanceof Patch) {
                continue;
            }

            $registeredPatches[] = new RegisteredInstallPatch($patch, $contribution['confirmation']);
        }

        return $registeredPatches;
    }
}
