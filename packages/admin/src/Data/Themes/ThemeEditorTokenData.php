<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Spatie\LaravelData\Data;

final class ThemeEditorTokenData extends Data
{
    /** @param array<int, ThemeEditorOptionData> $options */
    public function __construct(
        public string $key,
        public string $label,
        public array $options,
        public ?string $default = null,
    ) {}

    /** @return array<string, string> */
    public function optionsByValue(): array
    {
        return collect($this->options)
            ->mapWithKeys(fn (ThemeEditorOptionData $option): array => [$option->value => $option->label])
            ->all();
    }

    public function accepts(mixed $value): bool
    {
        return is_string($value) && array_key_exists($value, $this->optionsByValue());
    }
}
