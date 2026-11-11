<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Contracts\SiteSpec\SiteSpecPresetProvider;
use Capell\Core\Data\SiteSpec\SiteSpecPresetData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Contracts\Container\Container;
use JsonException;
use LogicException;

/** @extends AbstractKeyedRegistry<SiteSpecPresetData> */
final class SiteSpecPresetRegistry extends AbstractKeyedRegistry
{
    private bool $coreLoaded = false;

    private int $taggedProviderCount = 0;

    public function __construct(private readonly Container $container) {}

    public function register(SiteSpecPresetData $preset): void
    {
        // Core's own presets must be in place first, so a clash with one is caught
        // whichever order a package registers in.
        $this->loadCorePresets();

        throw_if($preset->key === '', LogicException::class, 'Site spec preset keys must not be empty.');
        throw_if($this->hasItem($preset->key), LogicException::class, sprintf('A site spec preset is already registered for [%s].', $preset->key));

        $this->setItem($preset->key, $preset);
    }

    public function has(string $key): bool
    {
        $this->discover();

        return $this->hasItem($key);
    }

    public function get(string $key): ?SiteSpecPresetData
    {
        $this->discover();

        return $this->getItem($key);
    }

    /**
     * Every preset, ordered by key so guided setup lists them the same way each time.
     *
     * @return list<SiteSpecPresetData>
     */
    public function all(): array
    {
        $this->discover();

        $presets = $this->allItems();
        ksort($presets);

        return array_values($presets);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (SiteSpecPresetData $preset): string => $preset->key, $this->all());
    }

    private function discover(): void
    {
        $this->loadCorePresets();

        $tagged = iterator_to_array($this->container->tagged(SiteSpecPresetProvider::TAG));

        foreach (array_slice($tagged, $this->taggedProviderCount) as $provider) {
            if ($provider instanceof SiteSpecPresetProvider) {
                foreach ($provider->presets() as $preset) {
                    $this->register($preset);
                }
            }

            $this->taggedProviderCount++;
        }
    }

    private function loadCorePresets(): void
    {
        if ($this->coreLoaded) {
            return;
        }

        $this->coreLoaded = true;
        $files = glob(dirname(__DIR__, 3) . '/resources/site-spec/presets/*.json');

        foreach ($files === false ? [] : $files as $file) {
            $this->register($this->presetFromFile($file));
        }
    }

    private function presetFromFile(string $file): SiteSpecPresetData
    {
        $contents = file_get_contents($file);

        throw_if($contents === false, LogicException::class, sprintf('Could not read the site spec preset file [%s].', basename($file)));

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new LogicException(sprintf('The site spec preset file [%s] is not valid JSON: %s', basename($file), $jsonException->getMessage()), $jsonException->getCode(), $jsonException);
        }

        throw_unless(is_array($payload), LogicException::class, sprintf('The site spec preset file [%s] must hold a JSON object.', basename($file)));

        return SiteSpecPresetData::from($payload);
    }
}
