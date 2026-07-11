<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\Action;

interface SiteRecordActionExtender
{
    public const string TAG = 'capell-admin:site-record-actions';

    /** @return array<int, Action> */
    public function actions(): array;
}
