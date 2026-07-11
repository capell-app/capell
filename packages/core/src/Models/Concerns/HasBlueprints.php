<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

trait HasBlueprints
{
    /**
     * @return array<int|string, string|null>
     */
    public static function getTypes(): array
    {
        return self::query()
            ->select('blueprint_id')
            ->withWhereHas('blueprint')
            ->groupBy('blueprint_id')
            ->get()
            ->mapWithKeys(fn (self $item): array => [$item->blueprint_id => $item->blueprint->name])
            ->all();
    }
}
