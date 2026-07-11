<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

class PageVariationData extends Data
{
    public function __construct(
        public string $name,
        /** @var class-string<Model> */
        public string $model,
        public ?string $resourceName = null,
        public ?string $titleAttribute = null,
    ) {}
}
