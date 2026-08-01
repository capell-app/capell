<?php

declare(strict_types=1);

namespace Capell\Admin\Support\RecordState;

use Capell\Admin\Data\RecordStateData;
use Illuminate\Support\Collection;

final class RecordStateComposer
{
    /**
     * @param  iterable<RecordStateData>  $states
     * @return Collection<int, RecordStateData>
     */
    public static function compose(iterable $states, bool $exceptionalOnly = false): Collection
    {
        $states = collect($states);

        if ($exceptionalOnly) {
            $states = $states->filter(static fn (RecordStateData $state): bool => $state->isExceptional);
        }

        return self::ordered($states);
    }

    /**
     * @param  iterable<RecordStateData>  $states
     * @return Collection<int, RecordStateData>
     */
    public static function ordered(iterable $states): Collection
    {
        return collect($states)
            ->sortBy(static fn (RecordStateData $state): int => $state->priority)
            ->values();
    }
}
