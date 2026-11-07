<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class InstalledRuntimeFrontendProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('capell-tests/conformance-lifecycle', 'frontend');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->make(ConformanceRecorder::class)->record('installed-frontend');
    }
}
