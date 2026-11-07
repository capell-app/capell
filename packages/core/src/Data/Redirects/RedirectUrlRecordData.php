<?php

declare(strict_types=1);

namespace Capell\Core\Data\Redirects;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Model;

/**
 * One former address that should now redirect to a page's current address.
 */
final readonly class RedirectUrlRecordData
{
    /**
     * @param  Pageable<covariant Model>&Model  $pageable
     */
    public function __construct(
        public Pageable&Model $pageable,
        public Language $language,
        public string $url,
    ) {}
}
