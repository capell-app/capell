<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Support\ServiceProvider;
use Override;

final class ManifestBootstrapFrontendProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('manifest-frontend-registered', true);
    }
}
