<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
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

    public function handle(Layout|Theme|Site|Blueprint $record): void
    {
        if ($record instanceof Site || $record instanceof Blueprint) {
            if ($record instanceof Site) {
                // Effective metadata may inherit the selector from the blueprint.
                $record->loadMissing('blueprint');
            }

            $selector = $record instanceof Site
                ? $record->getMeta('logo_blade_view', 'brand.capell-logo')
                : data_get($record->meta, 'logo_blade_view');

            // False is the supported explicit opt-out, including inherited metadata.
            if ($selector !== false) {
                Validator::make(['meta' => ['logo_blade_view' => $selector]], [
                    'meta.logo_blade_view' => ['nullable', 'string', Rule::in(array_keys(resolve(ThemeChromeRegistry::class)->logoOptions()))],
                ])->validate();
            }

            return;
        }

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
