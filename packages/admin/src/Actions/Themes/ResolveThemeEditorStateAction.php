<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Admin\Data\Themes\ThemeEditorStateData;
use Capell\Core\Models\Theme;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static ThemeEditorStateData run(Theme $theme)
 */
final class ResolveThemeEditorStateAction
{
    use AsAction;

    public function handle(Theme $theme): ThemeEditorStateData
    {
        $definition = ResolveThemeDefinitionsAction::run()[$theme->key] ?? null;

        return ThemeEditorStateData::forTheme($theme, $definition);
    }
}
