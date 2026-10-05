<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Models\Page;

trait PageNestedSet
{
    public static function bootNodeTrait(): void
    {
        static::saving(static function (Page $model): void {
            $model->callPendingAction();
        });

        static::deleting(static function (Page $model): void {
            if ($model->deletingAsDescendant) {
                return;
            }

            if (! static::usesSoftDelete() || $model->isForceDeleting()) {
                $model->refreshNode();
            }

            $model->deleteDescendants();
        });

        // RestorePageCascadeRecordsAction owns restoration through recorded membership.
        // Omit the unused timestamp restore hooks: their mutable-only state rejects immutable dates.
        // Keep this boot method on a trait to preserve listener order relative to the other model hooks.
    }
}
