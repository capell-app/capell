<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ValidatesDelete
{
    public function validateDelete(Model $record): bool;
}
