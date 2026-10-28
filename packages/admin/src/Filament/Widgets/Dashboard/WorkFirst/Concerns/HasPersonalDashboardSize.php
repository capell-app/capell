<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Widgets\Dashboard\WorkFirst\Concerns;

use Override;

trait HasPersonalDashboardSize
{
    public string $dashboardSize = 'standard';

    #[Override]
    /** @return array{default: string, lg: int|string} */
    public function getColumnSpan(): array
    {
        return ['default' => 'full', 'lg' => $this->dashboardSize === 'wide' ? 'full' : 1];
    }
}
