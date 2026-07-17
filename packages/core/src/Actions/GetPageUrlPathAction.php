<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Illuminate\Database\Query\JoinClause;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Builds the parent URL path for a page by walking its ancestor slugs.
 *
 * @method static string run(Page $page, Language $language, bool $fullUrl = false)
 */
final class GetPageUrlPathAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page, Language $language, bool $fullUrl = false): string
    {
        $url = $fullUrl ? $page->site->getSiteDomainUrl($language) : '/';

        if ($page->parent_id === null) {
            return $url;
        }

        $paths = $page->newQuery()
            ->select('translations.meta->slug AS slug')
            ->join(
                'translations',
                fn (JoinClause $join) => $join->on('translations.translatable_id', $page->qualifyColumn('id'))
                    ->where('translations.translatable_type', $page->getMorphClass()),
            )
            ->where('translations.language_id', $language->id)
            ->whereAncestorOf($page->parent_id, true)
            ->where('site_id', $page->site_id)
            ->ordered()
            ->pluck('slug');

        $paths = $paths->reject(
            fn (?string $path): bool => in_array($path, [null, '', '/'], true),
        );

        if ($paths->isEmpty()) {
            return $url;
        }

        return $url . $paths->implode('/');
    }
}
