<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Admin\Data\Themes\ThemeEditorGroupData;
use Capell\Admin\Data\Themes\ThemeEditorOptionData;
use Capell\Admin\Data\Themes\ThemeEditorSchemaData;
use Capell\Admin\Data\Themes\ThemeEditorTokenData;
use Capell\Admin\Support\Themes\ThemeEditorLabelResolver;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use InvalidArgumentException;

final readonly class ResolveThemeEditorSchemaAction
{
    public function __construct(private ThemeEditorLabelResolver $labels) {}

    public function handle(ThemeDefinitionData $definition): ThemeEditorSchemaData
    {
        $editor = data_get($definition->frontend, 'editor', []);

        throw_unless(is_array($editor), InvalidArgumentException::class, 'Theme frontend.editor must be an array.');

        $declaredGroups = $editor['groups'] ?? [];
        $declaredTokens = $editor['tokens'] ?? [];

        throw_if(! is_array($declaredGroups) || ! is_array($declaredTokens), InvalidArgumentException::class, 'Theme frontend.editor groups and tokens must be arrays.');

        $tokens = $this->tokens($declaredTokens);
        $seenTokens = [];
        $groups = [];

        foreach ($declaredGroups as $groupKey => $groupTokens) {
            $groupKey = $this->key($groupKey, 'group');

            throw_if(! is_array($groupTokens) || $groupTokens === [], InvalidArgumentException::class, sprintf('Theme editor group [%s] must reference at least one token.', $groupKey));

            $resolvedTokens = [];

            foreach ($groupTokens as $tokenKey) {
                $tokenKey = $this->key($tokenKey, 'token');

                throw_unless(isset($tokens[$tokenKey]), InvalidArgumentException::class, sprintf('Theme editor group [%s] references unknown token [%s].', $groupKey, $tokenKey));

                throw_if(isset($seenTokens[$tokenKey]), InvalidArgumentException::class, sprintf('Theme editor token [%s] is declared in more than one group.', $tokenKey));

                $seenTokens[$tokenKey] = true;
                $resolvedTokens[] = $tokens[$tokenKey];
            }

            $groups[] = new ThemeEditorGroupData(
                key: $groupKey,
                label: $this->labels->resolve(null, $groupKey),
                tokens: $resolvedTokens,
            );
        }

        $ungroupedTokens = array_diff(array_keys($tokens), array_keys($seenTokens));

        if ($ungroupedTokens !== []) {
            throw new InvalidArgumentException('Theme editor tokens must belong to exactly one group: ' . implode(', ', $ungroupedTokens) . '.');
        }

        return new ThemeEditorSchemaData($groups);
    }

    /**
     * @param  array<mixed>  $declarations
     * @return array<string, ThemeEditorTokenData>
     */
    private function tokens(array $declarations): array
    {
        $tokens = [];

        foreach ($declarations as $tokenKey => $declaration) {
            $tokenKey = $this->key($tokenKey, 'token');

            throw_unless(is_array($declaration), InvalidArgumentException::class, sprintf('Theme editor token [%s] must be an array.', $tokenKey));

            $options = $declaration['options'] ?? null;

            throw_if(! is_array($options) || $options === [], InvalidArgumentException::class, sprintf('Theme editor token [%s] must declare allowed options.', $tokenKey));

            $resolvedOptions = collect($options)
                ->map(fn (mixed $option): ThemeEditorOptionData => $this->option($option))
                ->all();

            $tokens[$tokenKey] = new ThemeEditorTokenData(
                key: $tokenKey,
                label: $this->labels->resolve(null, $tokenKey),
                options: $resolvedOptions,
            );
        }

        return $tokens;
    }

    private function option(mixed $value): ThemeEditorOptionData
    {
        throw_if(! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $value) !== 1, InvalidArgumentException::class, 'Theme editor option values must be closed-set identifiers.');

        return new ThemeEditorOptionData(
            value: $value,
            label: $this->labels->resolve(null, $value),
        );
    }

    private function key(mixed $value, string $kind): string
    {
        throw_if(! is_string($value) || preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $value) !== 1, InvalidArgumentException::class, sprintf('Theme editor %s keys must be camel-case identifiers.', $kind));

        return $value;
    }
}
