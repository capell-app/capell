<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Resources\Pages\PageRegistration;

interface PageResourcePageExtender
{
    public const string TAG = 'capell-admin:page-resource-page-extender';

    /** @return array<string, PageRegistration> */
    public function getPages(): array;
}
