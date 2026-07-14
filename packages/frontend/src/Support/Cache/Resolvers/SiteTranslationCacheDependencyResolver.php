<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache\Resolvers;

use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Contracts\Cache\TranslationCacheDependencyResolver;
use Illuminate\Database\Eloquent\Model;

final class SiteTranslationCacheDependencyResolver implements TranslationCacheDependencyResolver
{
    public function supports(Translation $translation): bool
    {
        return $this->owner($translation) instanceof Site;
    }

    /** @return iterable<Model> */
    public function roots(Translation $translation): iterable
    {
        $owner = $this->owner($translation);

        return $owner instanceof Site ? [$owner] : [];
    }

    private function owner(Translation $translation): ?Model
    {
        $translation->loadMissing('translatable');
        $owner = $translation->getRelation('translatable');

        return $owner instanceof Model ? $owner : null;
    }
}
