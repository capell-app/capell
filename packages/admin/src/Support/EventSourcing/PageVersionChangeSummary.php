<?php

declare(strict_types=1);

namespace Capell\Admin\Support\EventSourcing;

use Capell\Admin\Data\Activity\ActivityFieldDiffData;
use Capell\Admin\Data\Activity\ActivityNestedFieldDiffData;
use Illuminate\Support\Str;

/**
 * Turns the field diffs of one version into the words an editor reads in the
 * Versions timeline: "Title and Content changed". Uses the deepest named
 * field of each change, so nested translation content reads as "Title", not
 * "Translations".
 */
final class PageVersionChangeSummary
{
    private const int MAX_NAMED_FIELDS = 3;

    /**
     * @param  list<ActivityFieldDiffData>  $fieldDiffs
     * @return list<string>
     */
    public function fieldNames(array $fieldDiffs): array
    {
        $names = [];

        foreach ($fieldDiffs as $fieldDiff) {
            $paths = $fieldDiff->nestedChanges === []
                ? [$fieldDiff->path]
                : array_map(static fn (ActivityNestedFieldDiffData $nested): string => $nested->path, $fieldDiff->nestedChanges);

            foreach ($paths as $path) {
                $names[] = $this->nameFor($path);
            }
        }

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }

    /**
     * @param  list<ActivityFieldDiffData>  $fieldDiffs
     */
    public function summary(array $fieldDiffs): string
    {
        $names = $this->fieldNames($fieldDiffs);

        if ($names === []) {
            return (string) __('capell-admin::event-sourcing.change_revision');
        }

        $named = array_slice($names, 0, self::MAX_NAMED_FIELDS);
        $remaining = count($names) - count($named);
        $list = (string) Str::of(implode(', ', $named))->replaceLast(', ', ' ' . __('capell-admin::event-sourcing.and') . ' ');

        return $remaining > 0
            ? (string) __('capell-admin::event-sourcing.changed_fields_more', ['fields' => implode(', ', $named), 'count' => $remaining])
            : (string) __('capell-admin::event-sourcing.changed_fields', ['fields' => $list]);
    }

    private function nameFor(string $path): string
    {
        $segments = array_values(array_filter(
            explode('.', $path),
            static fn (string $segment): bool => $segment !== '' && ! ctype_digit($segment) && ! Str::isUuid($segment),
        ));

        $last = $segments === [] ? '' : $segments[array_key_last($segments)];

        return Str::headline($last);
    }
}
