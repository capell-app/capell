<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

/** The structured-data components and custom JSON-LD a site adds to every public document. */
final class PublicSiteSchemaData extends Data implements PublicRenderData
{
    /** @param  list<string>  $schemaComponents */
    public function __construct(
        public readonly array $schemaComponents,
        public readonly ?string $customSchema,
    ) {}
}
