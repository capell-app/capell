<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Closure;
use Spatie\LaravelData\Data;

final class CapellOverviewStatDefinitionData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string|Closure $label,
        public readonly int|string|Closure $value,
        public readonly string|Closure $group,
        public readonly null|string|Closure $description = null,
        public readonly null|string|Closure $url = null,
        public readonly ?string $color = null,
        public readonly int $sort = 100,
        public readonly bool $defaultEnabled = false,
        public readonly ?string $settingsKey = null,
        public readonly null|string|Closure $settingsLabel = null,
        public readonly null|string|Closure $settingsDescription = null,
    ) {}

    public function resolve(): CapellOverviewStatData
    {
        return new CapellOverviewStatData(
            key: $this->key,
            label: $this->resolveString($this->label),
            value: $this->resolveValue(),
            group: $this->resolveString($this->group),
            description: $this->resolveNullableString($this->description),
            url: $this->resolveNullableString($this->url),
            color: $this->color,
            sort: $this->sort,
        );
    }

    /**
     * @return array{key: string, label: string, group: string, description?: string|null}
     */
    public function settingsEntry(): array
    {
        return [
            'key' => $this->settingsKey(),
            'label' => $this->resolveString($this->settingsLabel ?? $this->label),
            'group' => $this->resolveString($this->group),
            'description' => $this->resolveNullableString($this->settingsDescription ?? $this->description),
        ];
    }

    public function settingsKey(): string
    {
        return $this->settingsKey ?? $this->key;
    }

    private function resolveValue(): string
    {
        $value = $this->value instanceof Closure ? ($this->value)() : $this->value;

        return is_int($value) ? number_format($value) : $value;
    }

    private function resolveString(string|Closure $value): string
    {
        return $value instanceof Closure ? (string) $value() : $value;
    }

    private function resolveNullableString(null|string|Closure $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = $value instanceof Closure ? (string) $value() : $value;

        return $resolved === '' ? null : $resolved;
    }
}
