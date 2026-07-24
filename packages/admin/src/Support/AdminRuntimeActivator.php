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

    private bool $activated = false;

    private bool $activating = false;

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
        if ($this->prepared || $this->preparing) {
            return;
        }

        $this->preparing = true;

        try {
            ($this->prepareBuiltIns)();

            foreach ($this->bridges->packageNames() as $packageName) {
                ($this->bootBridges)($packageName);
            }

            $this->prepared = true;
        } catch (Throwable $throwable) {
            $this->prepared = false;

            throw $throwable;
        } finally {
            $this->preparing = false;
        }
    }

    public function activate(): void
    {
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
}
