<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Illuminate\Support\ServiceProvider;
use Override;

/** An installed package whose extender adds denying security middleware while the panel boots. */
final class PackageSecurityDenyRuntimeProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->tag([PackageSecurityDenyPanelExtender::class], AdminPanelExtender::TAG);
    }
}
