<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\Action;

interface SiteHeaderActionExtender
{
    public const string TAG = 'capell-admin:site-header-actions';

    /** @return array<int, Action> */
    public function actions(): array;
}
