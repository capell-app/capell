<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Makers;

use Capell\Admin\Support\AdminSurfaceLookup;
use ReflectionClass;

class ConfiguratorSourceResolver
{
    public const string BLANK_SOURCE_KEY = '__blank';

    /**
     * @return array<int, array{key:string,class:?string,path:?string,sourcePackage:string}>
     */
    public function candidates(string $configuratorType): array
    {
        $candidates = [];

        foreach (AdminSurfaceLookup::configurators($configuratorType) as $configuratorClass) {
            $reflection = class_exists($configuratorClass) ? new ReflectionClass($configuratorClass) : null;
            $key = $configuratorClass::getKey();

            $candidates[] = [
                'key' => $key,
                'class' => $configuratorClass,
                'path' => $reflection?->getFileName() !== false ? $reflection?->getFileName() : null,
                'sourcePackage' => str_starts_with($configuratorClass, 'App\\') ? 'host-app' : 'package',
            ];
        }

        if ($candidates === []) {
            $candidates[] = [
                'key' => 'default',
                'class' => null,
                'path' => null,
                'sourcePackage' => 'generated',
            ];
        }

        return $candidates;
    }

    /** @return array{key:string,class:?string,path:?string,sourcePackage:string} */
    public function resolve(string $configuratorType, ?string $sourceKey): array
    {
        if ($sourceKey === self::BLANK_SOURCE_KEY) {
            return [
                'key' => 'blank',
                'class' => null,
                'path' => null,
                'sourcePackage' => 'generated',
            ];
        }

        $candidates = $this->candidates($configuratorType);

        foreach ($candidates as $candidate) {
            if ($sourceKey === null || $sourceKey === '' || $candidate['key'] === $sourceKey) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}
