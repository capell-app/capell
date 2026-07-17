<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Core\Actions\CreateThemeAction;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Theme run(string $themeKey)
 */
final class CreateAvailableThemeAction
{
    use AsFake;
    use AsObject;

    /**
     * @throws ValidationException
     */
    public function handle(string $themeKey): Theme
    {
        try {
            return DB::transaction(fn (): Theme => $this->createTheme($themeKey));
        } catch (QueryException $queryException) {
            if (Theme::query()->where('key', $themeKey)->exists()) {
                throw ValidationException::withMessages([
                    'theme' => __('capell-admin::theme-library.validation.available_theme_exists'),
                ]);
            }

            throw $queryException;
        }
    }

    /**
     * @throws ValidationException
     */
    private function createTheme(string $themeKey): Theme
    {
        $definition = ResolveThemeDefinitionsAction::run()[$themeKey] ?? null;

        if (! $definition instanceof ThemeDefinitionData) {
            throw ValidationException::withMessages([
                'theme' => __('capell-admin::theme-library.validation.available_theme_missing'),
            ]);
        }

        if (Theme::query()->where('key', $themeKey)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'theme' => __('capell-admin::theme-library.validation.available_theme_exists'),
            ]);
        }

        $diagnostics = ValidateThemeDefinitionAction::run($themeKey, $definition);

        if (! $diagnostics->isValid()) {
            throw ValidationException::withMessages([
                'theme' => collect($diagnostics->errors)
                    ->first() ?? __('capell-admin::theme-library.validation.diagnostics_block_create'),
            ]);
        }

        return CreateThemeAction::run(
            key: $definition->key,
            name: $definition->name,
            assets: array_values($definition->assets),
            default: false,
            activePreset: $definition->presets[0]->key ?? null,
        );
    }
}
