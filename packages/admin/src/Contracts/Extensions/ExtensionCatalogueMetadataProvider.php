<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;

interface ExtensionCatalogueMetadataProvider
{
    public const string TAG = 'capell-admin:extension-catalogue-metadata-provider';

    /**
     * @param  list<string>  $composerNames
     * @return array<string, ExtensionCatalogueMetadataData>
     */
    public function metadataForComposerNames(array $composerNames): array;
}
