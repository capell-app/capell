<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Events\PageUrlsRewritten;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Url\PageUrlRewriteContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class SetupPageUrlsAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly PageUrlRewriteContext $rewriteContext,
    ) {}

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>&Model  $page
     */
    public function handle(Pageable&Model $page, bool $updateDescendants = true, ?bool $automaticRedirectsAllowed = null): void
    {
        $page->load($this->getRelations());

        $previousUrls = $this->captureUrls($page, $updateDescendants);

        $this->updateTranslations($page, $page->site);

        if ($updateDescendants) {
            $this->updateDescendantsUrls($page, $page->site);
        }

        $currentUrls = $this->captureUrls($page, $updateDescendants);
        $pageId = (int) $page->getKey();
        $urlChanges = $this->diffUrls($previousUrls[$pageId] ?? [], $currentUrls[$pageId] ?? []);
        $descendantUrlChanges = [];

        if ($updateDescendants) {
            foreach ($previousUrls as $descendantId => $urls) {
                if ($descendantId === $pageId) {
                    continue;
                }

                $changes = $this->diffUrls($urls, $currentUrls[$descendantId] ?? []);

                if ($changes !== []) {
                    $descendantUrlChanges[$descendantId] = $changes;
                }
            }
        }

        if ($urlChanges !== [] || $descendantUrlChanges !== []) {
            event(new PageUrlsRewritten(
                page: $page,
                urlChanges: $urlChanges,
                descendantUrlChanges: $descendantUrlChanges,
                automaticRedirectsAllowed: $automaticRedirectsAllowed ?? $this->rewriteContext->automaticRedirectsAllowed(),
            ));
        }
    }

    /**
     * @return list<string>
     */
    private function getRelations(): array
    {
        return [
            'translations:translatable_id,translatable_type,language_id,meta',
            'translations.language',
        ];
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function updateTranslations(Pageable $page, Site $site): void
    {
        $page->translations->each(function (Translation $translation) use ($page, $site): void {
            $url = $page->getParentUrl($translation->language);

            UpdatePageUrlAction::run($site, $translation, $url);
        });
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function updateDescendantsUrls(Pageable $page, Site $site): void
    {
        $descendants = $page->descendants()->get();
        $descendants->load($this->getRelations());

        $descendants->each(function (Model $descendant) use ($site): void {
            if (! $descendant instanceof Pageable) {
                return;
            }

            $descendant->translations->each(function (Translation $translation) use ($site, $descendant): void {
                $url = $descendant->getParentUrl(language: $translation->language);

                UpdatePageUrlAction::run($site, $translation, $url);
            });
        });
    }

    /**
     * @param  Pageable<covariant Model>&Model  $page
     * @return array<int, array<int, string>>
     */
    private function captureUrls(Pageable&Model $page, bool $includeDescendants): array
    {
        /** @var Collection<int, Model> $pages */
        $pages = new Collection([$page]);

        if ($includeDescendants) {
            $pages = $pages->merge($page->newQuery()->whereDescendantOf($page->getKey())->get());
        }

        $pages->load('pageUrls');
        $urls = [];

        foreach ($pages as $target) {
            if (! $target instanceof Pageable) {
                continue;
            }

            foreach ($target->pageUrls as $pageUrl) {
                if (! $pageUrl instanceof PageUrl
                    || $pageUrl->type === UrlTypeEnum::Redirect
                    || $pageUrl->language_id === null
                    || ! is_string($pageUrl->url)) {
                    continue;
                }

                $urls[(int) $target->getKey()][(int) $pageUrl->language_id] = $pageUrl->url;
            }
        }

        return $urls;
    }

    /**
     * @param  array<int, string>  $previousUrls
     * @param  array<int, string>  $currentUrls
     * @return array<int, array{old: string, new: string}>
     */
    private function diffUrls(array $previousUrls, array $currentUrls): array
    {
        $changes = [];

        foreach ($previousUrls as $languageId => $oldUrl) {
            $newUrl = $currentUrls[$languageId] ?? null;

            if (is_string($newUrl) && $oldUrl !== $newUrl) {
                $changes[$languageId] = [
                    'old' => $oldUrl,
                    'new' => $newUrl,
                ];
            }
        }

        return $changes;
    }
}
