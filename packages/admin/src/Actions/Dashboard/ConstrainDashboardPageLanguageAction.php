<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Dashboard;

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use InvalidArgumentException;

final class ConstrainDashboardPageLanguageAction
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $pages
     * @return Builder<TModel>
     */
    public function handle(Builder $pages, SiteAccess $access, ?string $language): Builder
    {
        throw_unless($pages->getModel() instanceof Page, InvalidArgumentException::class, 'Dashboard page language constraints require a Page query.');

        if ($language === null) {
            return $pages;
        }

        // Preserve the caller's workspace/global-scope choices while independently
        // constraining every translation owner to the explicit site-access boundary.
        $owners = $access->scope(clone $pages)->select($pages->getModel()->qualifyColumn('id'));
        $translations = Translation::query();
        $translations->withoutGlobalScopes(array_values(array_filter(
            $pages->removedScopes(),
            static fn (string $scope): bool => $scope !== SoftDeletingScope::class,
        )));
        $translations->where('translatable_type', (new Page)->getMorphClass())
            ->whereIn('translatable_id', $owners)
            ->whereIn('language_id', Language::query()->select('id')->where('code', $language));

        return $pages->whereIn($pages->getModel()->qualifyColumn('id'), $translations->select('translatable_id'));
    }
}
