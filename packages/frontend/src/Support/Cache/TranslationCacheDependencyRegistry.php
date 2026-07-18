<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Models\Translation;
use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Capell\Frontend\Contracts\Cache\TranslationCacheDependencyResolver;
use Illuminate\Database\Eloquent\Model;

/** @extends TaggedProviderRegistry<TranslationCacheDependencyResolver> */
final class TranslationCacheDependencyRegistry extends TaggedProviderRegistry
{
    /** @param iterable<TranslationCacheDependencyResolver> $resolvers */
    public function __construct(iterable $resolvers)
    {
        parent::__construct($resolvers, TranslationCacheDependencyResolver::class);
    }

    /** @return list<Model> */
    public function roots(Translation $translation): array
    {
        $roots = [];

        foreach ($this->providers() as $resolver) {
            if (! $resolver->supports($translation)) {
                continue;
            }

            foreach ($resolver->roots($translation) as $root) {
                $roots[$root::class . ':' . $root->getKey()] = $root;
            }
        }

        return array_values($roots);
    }
}
