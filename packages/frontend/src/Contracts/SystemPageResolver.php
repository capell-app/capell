<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;

interface SystemPageResolver
{
    public const string TAG = 'capell-frontend:system-page-resolvers';

    public function resolve(string $type, Site $site, Language $language): ?Pageable;
}
