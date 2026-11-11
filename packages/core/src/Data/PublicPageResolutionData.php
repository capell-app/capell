<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;

final class PublicPageResolutionData extends Data
{
    /**
     * @param  Pageable<Model>|null  $page
     */
    public function __construct(
        #[Hidden]
        public ?Pageable $page,
        #[Hidden]
        public ?Site $site,
        #[Hidden]
        public ?Language $language,
        #[Hidden]
        public ?Layout $layout,
        public PublicPageFieldsData $fields,
        #[Hidden]
        public ?TranslationResolutionData $translation = null,
    ) {}

    /**
     * True when the page is served in a fallback language because it has no
     * translation in the requested one. Such pages are not real alternates.
     */
    public function servesFallback(): bool
    {
        return $this->translation?->isFallback() === true;
    }

    public function found(): bool
    {
        return $this->page instanceof Pageable;
    }
}
