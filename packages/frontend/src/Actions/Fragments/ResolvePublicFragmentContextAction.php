<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions\Fragments;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Frontend\Data\Fragments\PublicFragmentContextData;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicFragmentContextAction
{
    use AsObject;

    public function handle(PublicFragmentReferenceData $reference): PublicFragmentContextData
    {
        $modelClass = Relation::getMorphedModel($reference->pageableType);

        if (! is_string($modelClass)
            || ! is_subclass_of($modelClass, Model::class)
            || ! is_subclass_of($modelClass, Pageable::class)) {
            $this->invalid();
        }

        /** @var class-string<Model&Pageable> $modelClass */
        $page = $modelClass::query()
            ->withTrashed()
            ->whereKey($reference->pageableId)
            ->first();

        if (! $page instanceof Model
            || ! $page instanceof Pageable
            || (method_exists($page, 'trashed') && $page->trashed())
            || ! method_exists($page, 'publishVisibilityState')
            || $page->publishVisibilityState() !== PublishVisibilityStateEnum::published) {
            $this->invalid();
        }

        $site = Site::query()->enabled()->whereKey($reference->siteId)->first();
        $language = Language::query()->enabled()->whereKey($reference->languageId)->first();

        if (! $site instanceof Site
            || ! $language instanceof Language
            || (string) $page->getAttribute('site_id') !== (string) $site->getKey()
            || ! $this->languageBelongsToSite($site, $language)
            || ! $this->blueprintIsPublic($page)) {
            $this->invalid();
        }

        $translation = Translation::query()
            ->where('translatable_type', $page->getMorphClass())
            ->where('translatable_id', $page->getKey())
            ->where('language_id', $language->getKey())
            ->first();
        $pageUrl = PageUrl::query()
            ->enabled()
            ->where('pageable_type', $page->getMorphClass())
            ->where('pageable_id', $page->getKey())
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->where(function (Builder $query): void {
                $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect);
            })
            ->first();

        if (! $translation instanceof Translation || ! $pageUrl instanceof PageUrl) {
            $this->invalid();
        }

        $layoutId = $reference->ownerContext['layoutId'] ?? null;
        $pageLayoutId = $page->getAttribute('layout_id');
        $layout = Layout::query()
            ->enabled()
            ->whereKey($pageLayoutId)
            ->where(function (Builder $query) use ($site): void {
                $query->whereNull('site_id')->orWhere('site_id', $site->getKey());
            })
            ->first();

        if (! $layout instanceof Layout
            || (string) $layoutId !== (string) $layout->getKey()
            || (string) $pageLayoutId !== (string) $layout->getKey()) {
            $this->invalid();
        }

        $currentContentVersion = ResolvePublicFragmentContentVersionAction::run(
            $page,
            $site,
            $language,
            $layout,
            $reference->ownerContext,
        );

        if (! hash_equals($currentContentVersion, $reference->contentVersion)) {
            $this->invalid();
        }

        $page->setRelation('site', $site);
        $page->setRelation('layout', $layout);
        $page->setRelation('translation', $translation);
        $page->setRelation('pageUrl', $pageUrl);

        return new PublicFragmentContextData(
            page: $page,
            site: $site,
            language: $language,
            reference: $reference,
        );
    }

    private function languageBelongsToSite(Site $site, Language $language): bool
    {
        if ((string) $site->language_id === (string) $language->getKey()) {
            return true;
        }

        return SiteDomain::query()
            ->enabled()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->exists();
    }

    /**
     * @param  Model&Pageable  $page
     */
    private function blueprintIsPublic(Model $page): bool
    {
        $blueprintId = $page->getAttribute('blueprint_id');

        return $blueprintId !== null
            && Blueprint::query()
                ->enabled()
                ->accessible()
                ->whereKey($blueprintId)
                ->exists();
    }

    private function invalid(): never
    {
        throw new ModelNotFoundException;
    }
}
