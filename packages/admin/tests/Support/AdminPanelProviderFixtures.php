<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

final class AdminPanelProviderFixtures
{
    public static function clean(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login();
    }
}
PHP;
    }
}
