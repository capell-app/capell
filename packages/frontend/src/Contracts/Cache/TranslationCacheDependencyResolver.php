<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts\Cache;

use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;

interface TranslationCacheDependencyResolver
{
    public const string TAG = 'capell.frontend.translation-cache-dependency-resolver';

    public function supports(Translation $translation): bool;

    /** @return iterable<Model> */
    public function roots(Translation $translation): iterable;
}
