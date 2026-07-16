<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Theme run(Theme $theme)
 */
final class SetActiveThemeAction
{
    use AsFake;
    use AsObject;

    public function handle(Theme $theme): Theme
    {
        return DB::transaction(function () use ($theme): Theme {
            Theme::query()
                ->whereKeyNot($theme->getKey())
                ->update(['default' => false]);

            $theme->forceFill([
                'default' => true,
                'status' => true,
            ])->save();

            return $theme->refresh();
        });
    }
}
