<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Models\ExtensionQuarantine;
use Throwable;

/**
 * Decides whether a package may enable and boot. Bound as a singleton so the
 * quarantined set is read once per boot cycle rather than once per package;
 * quarantine writes call forget() so the next decision reads the new state.
 */
final class ExtensionQuarantineGate
{
    /** @var array<string, true>|false|null Null: not read yet. False: the store could not be read. */
    private array|false|null $quarantined = null;

    public function allows(string $package): bool
    {
        if (TrustedCorePackages::contains($package)) {
            return true;
        }

        if ($this->quarantined === null) {
            try {
                $this->quarantined = ExtensionQuarantine::quarantinedNames();
            } catch (Throwable) {
                $this->quarantined = false;
            }
        }

        return $this->quarantined !== false && ! isset($this->quarantined[$package]);
    }

    public function forget(): void
    {
        $this->quarantined = null;
    }
}
