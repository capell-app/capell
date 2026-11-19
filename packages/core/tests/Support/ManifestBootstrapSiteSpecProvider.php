<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Themes\ThemeInstallDefaultsRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

final class ManifestBootstrapSiteSpecProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->callAfterResolving(ThemeInstallDefaultsRegistry::class, static function (ThemeInstallDefaultsRegistry $registry): void {
            $registry->register('default', static function (): void {});
        });
    }
}
