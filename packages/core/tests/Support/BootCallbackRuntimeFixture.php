<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Override;

final class BootCallbackRuntimeFixture extends RuntimeLifecycleFixture
{
    public static string $packageName = 'test/boot-callback-fixture';

    #[Override]
    public function packageBooted(): void
    {
        $this->app->booted(function (): void {
            if ($this->isPackageInstalled()) {
                // An ordinary application callback is deliberately not replayed.
                $this->registrations[] = 'legacy-application-callback';
            }
        });
    }

    #[Override]
    protected function bootInstalledPackage(): self
    {
        return $this;
    }
}
