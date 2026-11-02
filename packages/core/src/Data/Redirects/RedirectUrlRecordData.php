<?php

declare(strict_types=1);

namespace Capell\Core\Data\Redirects;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Model;

/**
 * One redirect to record: the old URL for a page and language, with the page's current URL when the caller
 * already holds it, so the recorder does not look it up again.
 *
 * @template TDeclaringModel of Model
 */
final readonly class RedirectUrlRecordData
{
    /** @param  Pageable<TDeclaringModel>  $pageable */
    public function __construct(
        public Pageable $pageable,
        public Language $language,
        public string $url,
        public ?string $targetUrl = null,
    ) {}
}
