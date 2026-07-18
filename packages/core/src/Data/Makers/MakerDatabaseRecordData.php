<?php

declare(strict_types=1);

namespace Capell\Core\Data\Makers;

use Spatie\LaravelData\Data;

final class MakerDatabaseRecordData extends Data
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $model,
        public string $operation,
        public array $attributes,
        public ?string $snippet = null,
    ) {}
}
