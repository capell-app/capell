<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Filament\Actions\Action;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;

interface UserTableExtender
{
    public const string TAG = 'capell.admin.user_table_extenders';

    /** @return array<int, Column> */
    public function columns(): array;

    /** @return array<int, BaseFilter> */
    public function filters(): array;

    /** @return array<int, Action> */
    public function recordActions(): array;

    /** @return array<int, Action> */
    public function toolbarActions(): array;
}
