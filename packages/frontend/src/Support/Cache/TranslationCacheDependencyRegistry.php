<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Models\Translation;
use Capell\Frontend\Contracts\Cache\TranslationCacheDependencyResolver;
use Illuminate\Database\Eloquent\Model;

final class TranslationCacheDependencyRegistry
{
    /** @param iterable<TranslationCacheDependencyResolver> $resolvers */
    public function __construct(
        private readonly iterable $resolvers,
    ) {}

    /** @return list<Model> */
    public function roots(Translation $translation): array
    {
        $roots = [];

        foreach ($this->resolvers as $resolver) {
            if (! $resolver->supports($translation)) {
                continue;
            }

            foreach ($resolver->roots($translation) as $root) {
                $roots[$root::class . ':' . (string) $root->getKey()] = $root;
            }
        }

        return array_values($roots);
    }
}
