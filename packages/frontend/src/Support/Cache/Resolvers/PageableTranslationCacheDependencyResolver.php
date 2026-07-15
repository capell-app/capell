<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache\Resolvers;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Translation;
use Capell\Frontend\Contracts\Cache\TranslationCacheDependencyResolver;
use Illuminate\Database\Eloquent\Model;

final class PageableTranslationCacheDependencyResolver implements TranslationCacheDependencyResolver
{
    public function supports(Translation $translation): bool
    {
        return $this->owner($translation) instanceof Pageable;
    }

    /** @return iterable<Model> */
    public function roots(Translation $translation): iterable
    {
        $owner = $this->owner($translation);

        return $owner instanceof Model && $owner instanceof Pageable ? [$owner] : [];
    }

    private function owner(Translation $translation): ?Model
    {
        $translation->loadMissing('translatable');
        $owner = $translation->getRelation('translatable');

        return $owner instanceof Model ? $owner : null;
    }
}
