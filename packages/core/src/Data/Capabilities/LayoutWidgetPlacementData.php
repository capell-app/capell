<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use Spatie\LaravelData\Data;

/**
 * One widget the current page renders: where it sits and its resolved meta.
 */
final class LayoutWidgetPlacementData extends Data
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $containerKey,
        public string $widgetKey,
        public int $occurrence,
        public array $meta = [],
    ) {}

    public function metaString(string $key): ?string
    {
        $value = $this->meta[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
