<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Admin\Data\Themes\ThemeEditorStateData;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static Theme run(string $name, string $key, ?string $description = null, bool $default = false, bool $status = true)
 */
final class CreateCustomThemeAction
{
    use AsAction;

    /**
     * @throws ValidationException
     */
    public function handle(
        string $name,
        string $key,
        ?string $description = null,
        bool $default = false,
        bool $status = true,
    ): Theme {
        return DB::transaction(function () use ($name, $key, $description, $default, $status): Theme {
            if (Theme::query()->where('key', $key)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'custom_key' => __('capell-admin::theme-library.validation.available_theme_exists'),
                ]);
            }

            if ($default) {
                Theme::query()->where('default', true)->update(['default' => false]);
                $status = true;
            }

            $editorState = ThemeEditorStateData::defaults();
            $adminEditor = [
                ...$editorState->adminEditor(),
                'description' => $description ?? '',
            ];

            return Theme::query()->create([
                'name' => $name,
                'key' => $key,
                'blueprint_id' => Blueprint::query()->themeType()->value('id'),
                'default' => $default,
                'status' => $status,
                'meta' => [
                    'editor' => $editorState->metaEditor(),
                ],
                'admin' => [
                    'editor' => $adminEditor,
                ],
            ]);
        });
    }
}
