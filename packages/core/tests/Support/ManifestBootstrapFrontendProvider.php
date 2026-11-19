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
        $registrations = $this->app->bound('manifest-frontend-registration-count')
            ? $this->app->make('manifest-frontend-registration-count')
            : 0;
        $this->app->instance('manifest-frontend-registered', true);
        $this->app->instance('manifest-frontend-registration-count', $registrations + 1);
    }
}
