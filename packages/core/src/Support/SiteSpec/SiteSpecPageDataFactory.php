<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Data\SiteSpec\CapellSiteSpecPageData;
use Capell\Core\Models\Language;
use Capell\Core\Support\Publishing\PublishSentinel;

/** Builds the page data a SiteSpec page is created from, private by default. */
final class SiteSpecPageDataFactory
{
    /** @return array<string, mixed> */
    public static function make(CapellSiteSpecPageData $page, Language $language, bool $first, bool $public): array
    {
        $content = SiteSpecPageContent::html($page);

        if (! $public && $first) {
            $content = '<h1>Coming soon</h1><p>This site is being prepared.</p>';
        }

        return [
            'name' => $page->name,
            'type_key' => $page->pageType,
            'layout_key' => $first ? 'home' : 'default',
            'visible_from' => $public || $first ? now()->subDay()->toDateString() : PublishSentinel::draftValue()->toDateString(),
            'meta' => ! $public && $first
                ? ['noindex' => true, 'robots' => 'noindex,nofollow']
                : array_merge($page->meta, ['visibility' => $page->visibility, 'noindex' => ! $public], $public ? [] : ['robots' => 'noindex,nofollow']),
            'translations' => [$language->code => [
                'title' => ! $public && $first ? 'Coming soon' : $page->title,
                'content' => $content,
                'summary' => ! $public && $first ? 'This site is being prepared.' : $page->description,
                'slug' => ltrim($page->slug, '/'),
                'meta' => ! $public && $first
                    ? ['title' => 'Coming soon', 'description' => 'This site is being prepared.', 'robots' => 'noindex,nofollow']
                    : array_merge(['description' => $page->description, 'robots' => 'index,follow'], $page->meta, $public ? [] : ['robots' => 'noindex,nofollow']),
            ]],
        ];
    }
}
