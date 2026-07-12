<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;
use RuntimeException;

final class FakeExtensionCatalogueMetadataProvider implements ExtensionCatalogueMetadataProvider
{
    /**
     * @param  array<string, ExtensionCatalogueMetadataData>  $metadata
     */
    public function __construct(
        private readonly array $metadata = [],
        private readonly bool $unavailable = false,
    ) {}

    public function metadataForComposerNames(array $composerNames): array
    {
        throw_if($this->unavailable, RuntimeException::class, 'Catalogue unavailable.');

        return array_intersect_key($this->metadata, array_flip($composerNames));
    }
}
