<?php

declare(strict_types=1);

namespace Capell\Core\Support\Presentation;

use Capell\Core\Data\Presentation\PresentationPresetData;

final class PresentationPresetRegistry
{
    /** @var array<string, PresentationPresetData> */
    private array $presets = [];

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
        $this->presets[$preset->key] = $preset;
    }

    public function get(?string $key): ?PresentationPresetData
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->presets[$key] ?? null;
    }

    /**
     * @return array<string, PresentationPresetData>
     */
    public function all(): array
    {
        return $this->presets;
    }
}
