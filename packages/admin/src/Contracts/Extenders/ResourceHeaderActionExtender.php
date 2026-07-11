<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\Action;

interface ResourceHeaderActionExtender
{
    public const string TAG = 'capell-admin:resource-header-actions';

    public function supports(string $pageClass): bool;

    /** @return array<int, Action> */
    public function actions(): array;
}
