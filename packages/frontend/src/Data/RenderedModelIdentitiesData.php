<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

/**
 * Morph types and keys of the models one render declared. Internal cache
 * metadata: it is stored beside cached render data and never rendered.
 */
final readonly class RenderedModelIdentitiesData
{
    /** @var array<string, list<string>> */
    public array $keysByType;

    /** @param array<string, array<int, int|string>> $keysByType */
    public function __construct(array $keysByType = [])
    {
        $normalised = [];

        foreach ($keysByType as $modelType => $keys) {
            $keys = array_values(array_unique(array_map(strval(...), $keys)));

            if ($modelType !== '' && $keys !== []) {
                $normalised[$modelType] = $keys;
            }
        }

        $this->keysByType = $normalised;
    }

    public function isEmpty(): bool
    {
        return $this->keysByType === [];
    }
}
