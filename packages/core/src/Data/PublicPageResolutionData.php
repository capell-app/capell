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

class PublicPageResolutionData extends Data
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
    ) {}

    public function found(): bool
    {
        return $this->page instanceof Pageable;
    }
}
