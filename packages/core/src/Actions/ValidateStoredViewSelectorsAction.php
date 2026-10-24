<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Layout;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Themes\LayoutViewRegistry;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ValidateStoredViewSelectorsAction
{
    use AsFake;
    use AsObject;

    public function handle(Layout|Theme $record): void
    {
        $options = $record instanceof Layout
            ? [
                'master_file' => resolve(LayoutViewRegistry::class)->masterOptions(),
                'layout_file' => resolve(LayoutViewRegistry::class)->layoutOptions(),
            ]
            : [
                'header_file' => resolve(ThemeChromeRegistry::class)->headerOptions(),
                'footer_file' => resolve(ThemeChromeRegistry::class)->footerOptions(),
            ];

        $rules = [];
        foreach ($options as $field => $views) {
            $rules['meta.' . $field] = ['nullable', 'string', Rule::in(array_keys($views))];
        }

        Validator::make(['meta' => $record->meta], $rules)->validate();
    }
}
