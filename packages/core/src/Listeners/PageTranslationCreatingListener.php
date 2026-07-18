<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Slug\SlugGenerator;
use Illuminate\Database\Eloquent\Model;

final class PageTranslationCreatingListener
{
    public function __invoke(Translation $translation): void
    {
        if (! $translation->isPageable()) {
            return;
        }

        /** @var Model&Pageable<Model> $page */
        $page = $translation->translatable;

        if ($translation->title === null) {
            $translation->title = $page->name;
        }

        $meta = $translation->meta ?? [];
        $slug = $translation->slug;

        if ($slug === null) {
            $slug = SlugGenerator::slug($translation->title);
        }

        $meta['slug'] = $slug !== '/' ? trim($slug, '/') : $slug;

        $translation->meta = $meta;
    }
}
