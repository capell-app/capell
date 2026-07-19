<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Data\Activity\ActivityChangedFieldData;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Admin\Data\Activity\ActivityFieldDiffData;
use Capell\Admin\Data\Activity\ActivityNestedFieldDiffData;
use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class ActivityChangeDetailsPresenter
{
    private const int MAX_NESTED_CHANGES = 25;

    /**
     * @return list<ActivityFieldDiffData>
     */
    public function fields(ActivityChangeSetData $changeSet): array
    {
        return array_map(
            $this->field(...),
            $changeSet->fields,
        );
    }

    private function field(ActivityChangedFieldData $field): ActivityFieldDiffData
    {
        $nestedChanges = $this->nestedChanges($field);

        return new ActivityFieldDiffData(
            path: $field->path,
            label: $this->fieldLabel($field->label, $field->path),
            status: $field->status,
            reversible: $field->reversible,
            skipReason: $field->skipReason,
            beforeSummary: $this->summary($field->beforeValue),
            afterSummary: $this->summary($field->afterValue),
            beforeDetail: $this->detail($field->beforeValue),
            afterDetail: $this->detail($field->afterValue),
            nestedChanges: array_slice($nestedChanges, 0, self::MAX_NESTED_CHANGES),
            hiddenNestedChangeCount: max(count($nestedChanges) - self::MAX_NESTED_CHANGES, 0),
        );
    }

    /**
     * @return list<ActivityNestedFieldDiffData>
     */
    private function nestedChanges(ActivityChangedFieldData $field): array
    {
        if (! is_array($field->beforeValue) && ! is_array($field->afterValue)) {
            return [];
        }

        $beforeValues = $this->flatten($field->beforeValue);
        $afterValues = $this->flatten($field->afterValue);
        $paths = array_values(array_unique(array_merge(array_keys($beforeValues), array_keys($afterValues))));
        $changes = [];

        foreach ($paths as $path) {
            $hasBeforeValue = array_key_exists($path, $beforeValues);
            $hasAfterValue = array_key_exists($path, $afterValues);
            $beforeValue = $hasBeforeValue ? $beforeValues[$path] : null;
            $afterValue = $hasAfterValue ? $afterValues[$path] : null;

            if ($hasBeforeValue && $hasAfterValue && $beforeValue === $afterValue) {
                continue;
            }

            $changes[] = new ActivityNestedFieldDiffData(
                path: $field->path . '.' . $path,
                label: $this->pathLabel($field->path . '.' . $path),
                status: $this->status($hasBeforeValue, $hasAfterValue),
                beforeSummary: $hasBeforeValue ? $this->summary($beforeValue) : (string) __('capell-admin::generic.none'),
                afterSummary: $hasAfterValue ? $this->summary($afterValue) : (string) __('capell-admin::generic.none'),
                beforeDetail: $hasBeforeValue ? $this->detail($beforeValue) : (string) __('capell-admin::generic.none'),
                afterDetail: $hasAfterValue ? $this->detail($afterValue) : (string) __('capell-admin::generic.none'),
            );
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function flatten(mixed $value, string $prefix = ''): array
    {
        if (! is_array($value)) {
            return $prefix === '' ? ['value' => $value] : [$prefix => $value];
        }

        if ($value === []) {
            return $prefix === '' ? ['value' => []] : [$prefix => []];
        }

        $flattened = [];

        foreach ($value as $key => $childValue) {
            $childPath = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($childValue) && $childValue !== []) {
                $flattened = array_merge($flattened, $this->flatten($childValue, $childPath));

                continue;
            }

            $flattened[$childPath] = $childValue;
        }

        return $flattened;
    }

    private function fieldLabel(?string $label, string $path): string
    {
        return filled($label) ? $label : $this->pathLabel($path);
    }

    private function pathLabel(string $path): string
    {
        return collect(explode('.', $path))
            ->map(function (string $segment): string {
                if (ctype_digit($segment)) {
                    return '#' . ((int) $segment + 1);
                }

                return Str::of($segment)
                    ->replace('_', ' ')
                    ->headline()
                    ->toString();
            })
            ->implode(' / ');
    }

    private function status(bool $hasBeforeValue, bool $hasAfterValue): string
    {
        if (! $hasBeforeValue) {
            return 'created';
        }

        if (! $hasAfterValue) {
            return 'deleted';
        }

        return 'updated';
    }

    private function summary(mixed $value): string
    {
        if ($value === null) {
            return (string) __('capell-admin::generic.none');
        }

        if (is_bool($value)) {
            return $value ? (string) __('capell-admin::generic.yes') : (string) __('capell-admin::generic.no');
        }

        if (is_scalar($value)) {
            return Str::limit((string) $value, 160);
        }

        if (is_array($value)) {
            if ($value === []) {
                return (string) __('capell-admin::activity.empty_array_value');
            }

            return trans_choice('capell-admin::activity.array_item_count', count($value), [
                'count' => count($value),
            ]);
        }

        return Str::limit(JsonCodec::encodeOrDefault($value, flags: JSON_UNESCAPED_SLASHES), 160);
    }

    private function detail(mixed $value): string
    {
        if (is_array($value)) {
            return JsonCodec::encodeOrDefault(
                $this->normalizeArray($value),
                flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        }

        return $this->summary($value);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function normalizeArray(array $value): array
    {
        return Arr::map(
            $value,
            fn (mixed $childValue): mixed => is_array($childValue) ? $this->normalizeArray($childValue) : $childValue,
        );
    }
}
