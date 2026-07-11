<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback\Support;

use Capell\Core\EventSourcing\Rollback\RollbackChangeType;
use Capell\Core\EventSourcing\Rollback\RollbackFieldChangeData;
use Illuminate\Support\Str;

/**
 * Compares two serialised state blobs (current vs rollback target) section by
 * section. Diffing happens at the top level only; nested rendering is left to
 * the admin presenter, which already knows how to drill into the raw before /
 * after values carried on each change. Core stays presentation-neutral.
 */
final class StateDiffer
{
    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $target
     * @param  bool  $includeUnchanged  emit Unchanged entries too (default: only changed)
     * @return list<RollbackFieldChangeData>
     */
    public function diff(array $current, array $target, bool $includeUnchanged = false): array
    {
        $keys = array_values(array_unique([
            ...array_keys($current),
            ...array_keys($target),
        ]));

        $changes = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            $before = $current[$key] ?? null;
            $after = $target[$key] ?? null;
            $changeType = $this->classify($current, $target, $key, $before, $after);

            if ($changeType === RollbackChangeType::Unchanged && ! $includeUnchanged) {
                continue;
            }

            $changes[] = new RollbackFieldChangeData(
                path: $key,
                label: Str::headline($key),
                before: $before,
                after: $after,
                changeType: $changeType,
            );
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $target
     */
    private function classify(array $current, array $target, string $key, mixed $before, mixed $after): RollbackChangeType
    {
        $inCurrent = array_key_exists($key, $current);
        $inTarget = array_key_exists($key, $target);

        // The rollback restores the target, so "before" is current and "after"
        // is what the section would become.
        if ($inCurrent && ! $inTarget) {
            return RollbackChangeType::Removed;
        }

        if (! $inCurrent && $inTarget) {
            return RollbackChangeType::Added;
        }

        return $this->valuesEqual($before, $after)
            ? RollbackChangeType::Unchanged
            : RollbackChangeType::Modified;
    }

    private function valuesEqual(mixed $before, mixed $after): bool
    {
        return json_encode($before) === json_encode($after);
    }
}
