<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

trait HasCustomSelectOption
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function getSelectOption(Model $record, ?array $data = null): string
    {
        if ($data === null) {
            $data = [
                'label' => $record->getAttribute('name'),
            ];
        }

        /** @var view-string $view */
        $view = 'capell-admin::components.forms.select-option';

        return view($view, $data)->render();
    }
}
