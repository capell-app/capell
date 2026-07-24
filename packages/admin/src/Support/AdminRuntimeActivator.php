<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Closure;
use Throwable;

final class AdminRuntimeActivator
{
    private bool $prepared = false;

    private bool $preparing = false;

    private ?Throwable $preparationFailure = null;

    private bool $activated = false;

    private bool $activating = false;

    private int $bridgeRegistryRevision = -1;

    /**
     * @param  Closure(): void  $prepareBuiltIns
     * @param  Closure(): void  $activateRuntime
     * @param  Closure(string): void  $bootBridges
     */
    public function __construct(
        private readonly AdminBridgeRegistry $bridges,
        private readonly Closure $prepareBuiltIns,
        private readonly Closure $activateRuntime,
        private readonly Closure $bootBridges,
    ) {}

    public function prepare(): void
    {
        if ($this->preparationFailure instanceof Throwable) {
            throw $this->preparationFailure;
        }

        if ($this->prepared) {
            try {
                $this->bootPendingBridges();
            } catch (Throwable $throwable) {
                $this->preparationFailure = $throwable;

                throw $throwable;
            }

            return;
        }

        if ($this->preparing) {
            return;
        }

        $this->preparing = true;

        try {
            ($this->prepareBuiltIns)();

            $this->bootPendingBridges();

            $this->prepared = true;
        } catch (Throwable $throwable) {
            $this->prepared = false;
            $this->preparationFailure = $throwable;

            throw $throwable;
        } finally {
            $this->preparing = false;
        }
    }

    public function activate(): void
    {
        if ($this->preparationFailure instanceof Throwable) {
            throw $this->preparationFailure;
        }

        if ($this->activated || $this->activating) {
            return;
        }

        $this->activating = true;

        try {
            $this->prepare();
            ($this->activateRuntime)();

            $this->activated = true;
        } catch (Throwable $throwable) {
            $this->activated = false;

            throw $throwable;
        } finally {
            $this->activating = false;
        }
    }

    public function isPrepared(): bool
    {
        return $this->prepared;
    }

    public function isActivated(): bool
    {
        return $this->activated;
    }

    private function bootPendingBridges(): void
    {
        while ($this->bridgeRegistryRevision !== $this->bridges->revision()) {
            $pendingRevision = $this->bridges->revision();

            foreach ($this->bridges->packageNames() as $packageName) {
                ($this->bootBridges)($packageName);
            }

            $this->bridgeRegistryRevision = $pendingRevision;
        }
    }
}
