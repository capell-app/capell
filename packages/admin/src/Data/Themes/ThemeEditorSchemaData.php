<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Spatie\LaravelData\Data;

final class ThemeEditorSchemaData extends Data
{
    /** @param array<int, ThemeEditorGroupData> $groups */
    public function __construct(public array $groups) {}

    /** @return array<string, ThemeEditorTokenData> */
    public function tokensByKey(): array
    {
        return collect($this->groups)
            ->flatMap(fn (ThemeEditorGroupData $group): array => $group->tokens)
            ->mapWithKeys(fn (ThemeEditorTokenData $token): array => [$token->key => $token])
            ->all();
    }
}
