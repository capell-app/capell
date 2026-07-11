<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\Action;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Schema;

interface PageTitleWithSlugInputExtender
{
    public const string TAG = 'capell-admin:page-title-with-slug-input';

    /**
     * @return array<int, Action>
     */
    public function actions(): array;

    public function afterLabel(FusedGroup $component): ?Schema;
}
