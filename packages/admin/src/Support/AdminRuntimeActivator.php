<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Closure;
use Throwable;

final class AdminRuntimeActivator
{
    private bool $activated = false;

    private bool $activating = false;

    /**
     * @param  Closure(): void  $activateBuiltIns
     * @param  Closure(string): void  $bootBridges
     */
    public function __construct(
        private readonly AdminBridgeRegistry $bridges,
        private readonly Closure $activateBuiltIns,
        private readonly Closure $bootBridges,
    ) {}

    public function activate(): void
    {
        if ($this->activated || $this->activating) {
            return;
        }

        $this->activating = true;

        try {
            ($this->activateBuiltIns)();

            foreach ($this->bridges->packageNames() as $packageName) {
                ($this->bootBridges)($packageName);
            }

            $this->activated = true;
        } catch (Throwable $throwable) {
            $this->activated = false;

            throw $throwable;
        } finally {
            $this->activating = false;
        }
    }

    public function isActivated(): bool
    {
        return $this->activated;
    }
}
