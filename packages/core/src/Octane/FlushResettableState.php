<?php

declare(strict_types=1);

namespace Capell\Core\Octane;

use Illuminate\Contracts\Foundation\Application;

final readonly class FlushResettableState
{
    public function __construct(
        private Application $application,
    ) {}

    public function handle(?Application $application = null): void
    {
        $application ??= $this->application;

        foreach ($application->tagged(Resettable::TAG) as $service) {
            if (! $service instanceof Resettable) {
                continue;
            }

            $service->flushOctaneState();
        }
    }
}
