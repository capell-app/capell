<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Extensions;

use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Data\Extensions\ExtensionCatalogueMetadataData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class EnrichExtensionTableRecordsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public function handle(array $records): array
    {
        $composerNames = [];

        foreach ($records as $record) {
            $composerName = $this->composerName($record);

            if ($composerName !== null) {
                $composerNames[$composerName] = $composerName;
            }
        }

        $metadata = $this->catalogueMetadata(array_values($composerNames));

        foreach ($records as &$record) {
            $composerName = $this->composerName($record);
            $catalogueMetadata = $composerName !== null
                ? ($metadata[$composerName] ?? new ExtensionCatalogueMetadataData)
                : new ExtensionCatalogueMetadataData;

            $record = [
                ...$record,
                ...$catalogueMetadata->toTableRecord(),
            ];
        }

        unset($record);

        return $records;
    }

    /**
     * @param  list<string>  $composerNames
     * @return array<string, ExtensionCatalogueMetadataData>
     */
    private function catalogueMetadata(array $composerNames): array
    {
        if ($composerNames === []) {
            return [];
        }

        $metadata = [];

        try {
            foreach (app()->tagged(ExtensionCatalogueMetadataProvider::TAG) as $provider) {
                if (! $provider instanceof ExtensionCatalogueMetadataProvider) {
                    continue;
                }

                try {
                    $providedMetadata = $provider->metadataForComposerNames($composerNames);
                } catch (Throwable) {
                    continue;
                }

                foreach ($providedMetadata as $composerName => $extensionMetadata) {
                    if (! is_string($composerName)) {
                        continue;
                    }

                    if (! $extensionMetadata instanceof ExtensionCatalogueMetadataData) {
                        continue;
                    }

                    $metadata[$composerName] = $extensionMetadata->withSafeFallbacks();
                }
            }
        } catch (Throwable) {
            return $metadata;
        }

        return $metadata;
    }

    /** @param array<string, mixed> $record */
    private function composerName(array $record): ?string
    {
        $composerName = $record['packageName'] ?? null;

        return is_string($composerName) && $composerName !== '' ? $composerName : null;
    }
}
