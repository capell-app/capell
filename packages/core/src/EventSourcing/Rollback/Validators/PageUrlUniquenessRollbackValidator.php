<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback\Validators;

use Capell\Core\EventSourcing\Rollback\Contracts\RollbackValidator;
use Capell\Core\EventSourcing\Rollback\RollbackIssueData;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Blocks a rollback whose restored pageUrls would collide with a URL already
 * owned by a different page (same site + language + url). Uniqueness is the
 * canonical example the rollback preview must catch before applying.
 */
final class PageUrlUniquenessRollbackValidator implements RollbackValidator
{
    public function validate(Model $model, array $targetState): array
    {
        $issues = [];

        foreach ($targetState['pageUrls'] ?? [] as $pageUrl) {
            $url = $pageUrl['url'] ?? null;

            if ($url === null) {
                continue;
            }

            $conflicts = PageUrl::query()
                ->where('site_id', $pageUrl['site_id'] ?? null)
                ->where('language_id', $pageUrl['language_id'] ?? null)
                ->where('url', $url)
                ->where(static function (Builder $query) use ($model): void {
                    $query->where('pageable_id', '!=', $model->getKey())
                        ->orWhere('pageable_type', '!=', $model->getMorphClass());
                })
                ->exists();

            if ($conflicts) {
                $issues[] = RollbackIssueData::blocking(
                    code: 'page_url_conflict',
                    message: sprintf("The URL '%s' is already in use by another page.", $url),
                    path: 'pageUrls.' . $url,
                );
            }
        }

        return $issues;
    }
}
