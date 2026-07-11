<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Makers;

use Capell\Admin\Contracts\RegistryInspectorInterface;
use Capell\Admin\Data\Diagnostics\RegistrySourceData;

class ComponentSourceResolver
{
    public const string BLANK_SOURCE_KEY = '__blank';

    /**
     * @return array<int, array{key: string, label: string, path: string|null, sourcePackage: string}>
     */
    public function candidates(string $componentType): array
    {
        return resolve(RegistryInspectorInterface::class)
            ->components($componentType)
            ->filter(fn (mixed $source): bool => $source instanceof RegistrySourceData)
            ->map(fn (RegistrySourceData $source): array => [
                'key' => $source->key,
                'label' => $source->label,
                'path' => $source->path,
                'sourcePackage' => $source->sourcePackage,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, label: string, path: string|null, sourcePackage: string}
     */
    public function resolve(string $componentType, ?string $sourceKey): array
    {
        if (in_array($sourceKey, [null, '', self::BLANK_SOURCE_KEY], true)) {
            return [
                'key' => 'blank',
                'label' => 'Blank',
                'path' => null,
                'sourcePackage' => 'generated',
            ];
        }

        foreach ($this->candidates($componentType) as $source) {
            if ($source['key'] === $sourceKey) {
                return $source;
            }
        }

        return [
            'key' => 'blank',
            'label' => 'Blank',
            'path' => null,
            'sourcePackage' => 'generated',
        ];
    }
}
