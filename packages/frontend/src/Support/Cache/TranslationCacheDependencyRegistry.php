<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Models\Translation;
use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Capell\Frontend\Contracts\Cache\TranslationCacheDependencyResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;

/** @extends TaggedProviderRegistry<TranslationCacheDependencyResolver> */
final class TranslationCacheDependencyRegistry extends TaggedProviderRegistry
{
    public function __construct(Application $application)
    {
        parent::__construct(
            $application,
            TranslationCacheDependencyResolver::TAG,
            TranslationCacheDependencyResolver::class,
        );
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
