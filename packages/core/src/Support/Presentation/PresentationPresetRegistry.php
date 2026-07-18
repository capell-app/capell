<?php

declare(strict_types=1);

namespace Capell\Core\Support\Presentation;

use Capell\Core\Data\Presentation\PresentationPresetData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;

/** @extends AbstractKeyedRegistry<PresentationPresetData> */
final class PresentationPresetRegistry extends AbstractKeyedRegistry
{
    public function __construct()
    {
        $this->register(new PresentationPresetData(
            key: 'standard',
            label: 'Standard',
            icon: 'heroicon-o-rectangle-stack',
            description: 'Server-rendered default presentation.',
        ));
    }

    public function register(PresentationPresetData $preset): void
    {
        $this->setItem($preset->key, $preset);
    }

    public function get(?string $key): ?PresentationPresetData
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->getItem($key);
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    /**
     * @return array<string, PresentationPresetData>
     */
    public function all(): array
    {
        return $this->allItems();
    }

    public function clear(): void
    {
        $this->clearItems();
    }
}
