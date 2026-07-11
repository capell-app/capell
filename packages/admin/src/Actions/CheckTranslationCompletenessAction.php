<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Contracts\Translatable as TranslatableContract;
use Capell\Core\Models\Translation;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Calculates the completeness percentage (0-100) of a translation compared to its default translation.
 *
 * Returns null if there are no translatable fields or if all default values are blank.
 *
 * @method static int|null run(Translation $translation, array<string, array<int, string>|null> $keys)
 */
class CheckTranslationCompletenessAction
{
    use AsObject;

    /**
     * Calculate the completeness percentage (0-100) of a translation.
     *
     * @param  Translation  $translation  The translation to check.
     * @param  array<string, array<int, string>|null>  $keys  The keys/fields to check for completeness. Each key may map to a list of sub-keys for nested JSON or null for single field.
     * @return int|null Percentage of completeness (0-100), or null if not computable.
     */
    public function handle(Translation $translation, array $keys): ?int
    {
        $translation->loadMissing('translatable');

        $defaultTranslation = $this->getDefaultTranslation($translation);
        if (! $defaultTranslation instanceof Translation) {
            return null;
        }

        $defaultAttributes = $defaultTranslation->getAttributes();
        $translatedAttributes = $translation->getAttributes();

        [$filled, $total] = $this->calculateCompleteness($defaultAttributes, $translatedAttributes, $keys);

        if ($total === 0) {
            return null;
        }

        if ($filled === $total) {
            return 100;
        }

        return (int) round(($filled / $total) * 100);
    }

    /**
     * @param  array<string, mixed>  $defaultAttributes
     * @param  array<string, mixed>  $translatedAttributes
     * @param  array<string, array<int, string>|null>  $keys
     * @return array{0:int,1:int} Tuple of [filled, total]
     */
    private function calculateCompleteness(array $defaultAttributes, array $translatedAttributes, array $keys): array
    {
        $filled = 0;
        $total = 0;

        foreach ($keys as $key => $subKeys) {
            if (! array_key_exists($key, $defaultAttributes)) {
                continue;
            }

            if (! array_key_exists($key, $translatedAttributes)) {
                continue;
            }

            $defaultValue = $defaultAttributes[$key];
            if ($defaultValue === null) {
                continue;
            }

            if ($defaultValue === '') {
                continue;
            }

            if ($defaultValue === []) {
                continue;
            }

            $translatedValue = $translatedAttributes[$key];

            if (is_array($subKeys)) {
                [$subFilled, $subTotal] = $this->calculateNestedJsonCompleteness($defaultValue, $translatedValue, $subKeys);
                $filled += $subFilled;
                $total += $subTotal;
            } else {
                [$singleFilled, $singleTotal] = $this->calculateSingleValueCompleteness($defaultValue, $translatedValue);
                $filled += $singleFilled;
                $total += $singleTotal;
            }
        }

        return [$filled, $total];
    }

    private function getDefaultTranslation(Translation $translation): ?Translation
    {
        $translatable = $translation->translatable;
        if (! $translatable instanceof TranslatableContract) {
            return null;
        }

        $defaultTranslation = $translatable
            ->translations()
            ->whereRelation('language', 'default', true)
            ->first();

        return $defaultTranslation instanceof Translation ? $defaultTranslation : null;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function calculateSingleValueCompleteness(mixed $defaultValue, mixed $translatedValue): array
    {
        if (in_array($defaultValue, [null, '', []], true)) {
            return [0, 0];
        }

        if (in_array($translatedValue, [null, '', []], true)) {
            return [0, 1];
        }

        return [1, 1];
    }

    /**
     * @param  array<mixed>|null  $value
     * @return array<string|int, mixed>
     */
    private function asArray(string|array|null $value): array
    {
        $attempts = 0;
        while (is_string($value) && $attempts < 3) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $value = $decoded;
            $attempts++;
        }

        return $value ?? [];
    }

    /**
     * @param  array<mixed>|string|null  $defaultValue
     * @param  array<mixed>|string|null  $translatedValue
     * @param  array<int|string, string|array<int, string>>  $subKeys  List of sub-keys (nested) to check
     * @return array{0:int,1:int}
     */
    private function calculateNestedJsonCompleteness(string|array|null $defaultValue, string|array|null $translatedValue, array $subKeys): array
    {
        $defaultJson = $this->asArray($defaultValue);
        $translatedJson = $this->asArray($translatedValue);

        $filled = 0;
        $total = 0;
        foreach ($subKeys as $subKey => $subSubKeys) {
            if (is_int($subKey) && is_string($subSubKeys)) {
                $subKey = $subSubKeys;
                $subSubKeys = null;
            }

            if (! array_key_exists($subKey, $defaultJson)) {
                continue;
            }

            $defVal = $defaultJson[$subKey];
            if ($defVal === null) {
                continue;
            }

            if ($defVal === '') {
                continue;
            }

            if ($defVal === []) {
                continue;
            }

            $transVal = $translatedJson[$subKey] ?? '';
            if (is_array($subSubKeys)) {
                /** @var array<string, array<int, string>|null> $nestedKeys */
                $nestedKeys = array_fill_keys($subSubKeys, null);

                [$jsonFilled, $jsonTotal] = $this->calculateCompleteness($defVal, $transVal, $nestedKeys);
                $filled += $jsonFilled;
                $total += $jsonTotal;
            } else {
                [$singleFilled, $singleTotal] = $this->calculateSingleValueCompleteness($defVal, $transVal);
                $filled += $singleFilled;
                $total += $singleTotal;
            }
        }

        return [$filled, $total];
    }
}
