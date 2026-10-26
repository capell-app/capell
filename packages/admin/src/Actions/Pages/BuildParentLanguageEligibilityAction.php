<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\ParentLanguageEligibilityData;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildParentLanguageEligibilityAction
{
    use AsFake;
    use AsObject;

    /** @param array<array-key, mixed> $translations */
    public function handle(int $parentId, ?int $siteId, array $translations): ParentLanguageEligibilityData
    {
        $parent = SiteAccess::current()->query(Page::class)->with('translations')->find($parentId);
        $site = SiteAccess::current()->query(Site::class)->with(['siteDomains.language', 'language'])->find($siteId);

        if (! $parent instanceof Page || ! $site instanceof Site || $parent->site_id !== $site->id || ! Gate::allows('view', $parent)) {
            return new ParentLanguageEligibilityData(false);
        }

        $requiredIds = collect($translations)->filter(fn (mixed $row): bool => is_array($row))->pluck('language_id')->filter(fn (mixed $id): bool => $id !== null && $id !== '')->map(fn (mixed $id): int => (int) $id)->all();
        $parentIds = $parent->translations->pluck('language_id')->map(fn (mixed $id): int => (int) $id)->all();
        $languages = $site->getAllLanguages();
        $missing = $languages->whereIn('id', $requiredIds)->whereNotIn('id', $parentIds)->map(fn (Language $language): string => $language->name)->values()->all();

        return new ParentLanguageEligibilityData(
            true,
            array_values($missing),
            Gate::allows('update', $parent) ? GetEditPageResourceUrlAction::run($parent) : null,
            array_diff($requiredIds, $parentIds) === [],
        );
    }
}
