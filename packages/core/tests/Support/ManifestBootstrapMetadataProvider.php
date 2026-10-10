<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Illuminate\Support\ServiceProvider;
use Override;

final class ManifestBootstrapMetadataProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('manifest-metadata-registered', true);
    }
}
