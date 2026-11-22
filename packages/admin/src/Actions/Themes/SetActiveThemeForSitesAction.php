<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Admin\Data\Themes\SetActiveThemeForSitesData;
use Capell\Admin\Enums\Themes\ThemeActivationScope;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Theme run(SetActiveThemeForSitesData $data)
 */
final class SetActiveThemeForSitesAction
{
    use AsFake;
    use AsObject;

    /**
     * Authorisation is enforced here, where the write happens, so every caller is covered:
     * the actor needs the Theme update ability, global scope needs unrestricted site access,
     * and each selected site must pass the Site update policy. A disabled theme is refused
     * rather than silently re-enabled; enabling is a deliberate edit of the theme.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(SetActiveThemeForSitesData $data): Theme
    {
        return DB::transaction(function () use ($data): Theme {
            $theme = Theme::query()->findOrFail($data->themeId);

            Gate::authorize('update', $theme);

            throw_unless($theme->status, ValidationException::withMessages([
                'scope' => __('capell-admin::theme-library.validation.theme_disabled'),
            ]));

            if ($data->scope === ThemeActivationScope::Global) {
                throw_unless(SiteAccess::current()->isGlobal(), AuthorizationException::class);
            }

            $sites = new EloquentCollection;

            if ($data->scope === ThemeActivationScope::SelectedSites && $data->siteIds !== []) {
                /** @var EloquentCollection<int, Site> $sites */
                $sites = SiteAccess::current()->query(Site::class)
                    ->whereKey($data->siteIds)
                    ->get();

                $sites->each(fn (Site $site): mixed => Gate::authorize('update', $site));
            }

            if ($data->scope === ThemeActivationScope::Global) {
                Theme::query()
                    ->whereKeyNot($theme->getKey())
                    ->update(['default' => false]);

                $theme->forceFill(['default' => true])->save();
            }

            if ($sites->isNotEmpty()) {
                $sites->each(function (Site $site) use ($theme): void {
                    $site->forceFill(['theme_id' => $theme->getKey()])->save();
                });

                event(new FrontendSurrogateKeysInvalidated($this->siteSurrogateKeys($sites->modelKeys())));
            }

            return $theme->refresh();
        });
    }

    /**
     * @param  array<int, int>  $siteIds
     * @return list<string>
     */
    private function siteSurrogateKeys(array $siteIds): array
    {
        return array_values(array_map(fn (int $siteId): string => 'site-' . $siteId, $siteIds));
    }
}
