<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Capell\Admin\Filament\Actions\ImportHeaderAction;

trait HasImportExportHeaderActions
{
    /**
     * @param  array<int, mixed>  $actions
     * @return array<int, mixed>
     */
    protected function prependImportHeaderAction(array $actions): array
    {
        return [
            ImportHeaderAction::make(static::class),
            ...$actions,
        ];
    }
}
