<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicSiteSchemaData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicSiteSchemaDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendContextReader $frontend) {}

    public function handle(): PublicSiteSchemaData
    {
        $meta = $this->frontend->site()?->meta;
        $meta = is_array($meta) ? $meta : [];

        $components = data_get($meta, 'meta_schema');
        $customSchema = data_get($meta, 'custom_meta_schema');

        return new PublicSiteSchemaData(
            schemaComponents: is_array($components)
                ? array_values(array_filter($components, static fn (mixed $component): bool => is_string($component) && $component !== ''))
                : [],
            customSchema: is_scalar($customSchema) && (bool) $customSchema ? (string) $customSchema : null,
        );
    }
}
