<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

final class MediaCropPresetRepository
{
    /**
     * @return array<string, array{label: string, ratio: string, width: int, height: int}>
     */
    public function all(): array
    {
        $configured = config('capell.media.crop_presets', []);

        if (! is_array($configured)) {
            return [];
        }

        $presets = [];

        foreach ($configured as $name => $preset) {
            if (! is_string($name)) {
                continue;
            }

            if (! is_array($preset)) {
                continue;
            }

            $width = (int) ($preset['width'] ?? 0);
            $height = (int) ($preset['height'] ?? 0);
            $ratio = $preset['ratio'] ?? null;
            if ($width < 1) {
                continue;
            }

            if ($height < 1) {
                continue;
            }

            if (! is_string($ratio)) {
                continue;
            }

            if ($ratio === '') {
                continue;
            }

            $presets[$name] = [
                'label' => is_string($preset['label'] ?? null) && $preset['label'] !== '' ? $preset['label'] : str($name)->headline()->toString(),
                'ratio' => $ratio,
                'width' => $width,
                'height' => $height,
            ];
        }

        return $presets;
    }

    /**
     * @return array{label: string, ratio: string, width: int, height: int}|null
     */
    public function find(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        return collect($this->all())
            ->mapWithKeys(fn (array $preset, string $name): array => [$name => sprintf('%s (%s)', $preset['label'], $preset['ratio'])])
            ->all();
    }

    /**
     * @return list<string|null>
     */
    public function aspectRatioOptions(bool $includeFreeCrop = true): array
    {
        $ratios = array_values(collect($this->all())
            ->pluck('ratio')
            ->filter(fn (mixed $ratio): bool => is_string($ratio) && $ratio !== '')
            ->unique()
            ->values()
            ->all());

        return $includeFreeCrop ? [null, ...$ratios] : $ratios;
    }
}
