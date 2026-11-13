<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use Spatie\LaravelData\Data;

final class FormReferenceData extends Data
{
    /**
     * @param  array<string, string>  $fields  field key => label
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
        public ?int $siteId = null,
        public array $fields = [],
    ) {}
}
