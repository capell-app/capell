<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Illuminate\Support\ServiceProvider;
use Override;

/** Adds security before panel routes are served in the fresh application. */
final class OldCacheDenyProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->tag([OldCacheDenyExtender::class], AdminPanelExtender::TAG);
    }
}
