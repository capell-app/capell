<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Pages;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Events\PageUrlsRewritten;

final class PageUrlRewritePromptState
{
    /** @var array<string, PageUrlsRewritten> */
    private array $rewrites = [];

    public function remember(PageUrlsRewritten $rewrite): void
    {
        $key = $this->key($rewrite->page);
        $existing = $this->rewrites[$key] ?? null;

        if (! $existing instanceof PageUrlsRewritten) {
            $this->rewrites[$key] = $rewrite;

            return;
        }

        $this->rewrites[$key] = new PageUrlsRewritten(
            page: $rewrite->page,
            urlChanges: $this->mergeChanges($existing->urlChanges, $rewrite->urlChanges),
            descendantUrlChanges: $this->mergeDescendantChanges(
                $existing->descendantUrlChanges,
                $rewrite->descendantUrlChanges,
            ),
            automaticRedirectsAllowed: $existing->automaticRedirectsAllowed && $rewrite->automaticRedirectsAllowed,
        );
    }

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    public function consume(Pageable $page): ?PageUrlsRewritten
    {
        $key = $this->key($page);
        $rewrite = $this->rewrites[$key] ?? null;

        unset($this->rewrites[$key]);

        return $rewrite;
    }

    /**
     * @param  array<int, array{old: string, new: string}>  $existing
     * @param  array<int, array{old: string, new: string}>  $incoming
     * @return array<int, array{old: string, new: string}>
     */
    private function mergeChanges(array $existing, array $incoming): array
    {
        foreach ($incoming as $languageId => $change) {
            $existing[$languageId] = isset($existing[$languageId])
                ? ['old' => $existing[$languageId]['old'], 'new' => $change['new']]
                : $change;
        }

        return $existing;
    }

    /**
     * @param  array<int, array<int, array{old: string, new: string}>>  $existing
     * @param  array<int, array<int, array{old: string, new: string}>>  $incoming
     * @return array<int, array<int, array{old: string, new: string}>>
     */
    private function mergeDescendantChanges(array $existing, array $incoming): array
    {
        foreach ($incoming as $pageId => $changes) {
            $existing[$pageId] = $this->mergeChanges($existing[$pageId] ?? [], $changes);
        }

        return $existing;
    }

    /**
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function key(Pageable $page): string
    {
        return $page->getMorphClass() . ':' . $page->getKey();
    }
}
