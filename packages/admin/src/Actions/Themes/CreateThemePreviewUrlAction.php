<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Preview\ThemePreviewSigner;
use Illuminate\Support\Facades\URL;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(Theme $theme, Site $site, Page $page, ?string $presetKey = null)
 */
final class CreateThemePreviewUrlAction
{
    use AsObject;

    public function handle(Theme $theme, Site $site, Page $page, ?string $presetKey = null): string
    {
        $parameters = [
            'theme' => $theme,
            'site' => $site,
            'page' => $page,
        ];

        if ($presetKey !== null && $presetKey !== '' && app()->bound(ThemePreviewSigner::class)) {
            $signer = resolve(ThemePreviewSigner::class);
            $parameters[$signer->tokenParam()] = $signer->generate($theme->key, $presetKey, 15);
        }

        return URL::temporarySignedRoute('capell.admin.theme-preview', now()->addMinutes(15), $parameters);
    }
}
