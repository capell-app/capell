<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Illuminate\View\View;

trait HasBlankPlaceholder
{
    public function placeholder(): View
    {
        /** @var view-string $view */
        $view = 'capell-admin::components.blank-placeholder';

        return view($view);
    }
}
