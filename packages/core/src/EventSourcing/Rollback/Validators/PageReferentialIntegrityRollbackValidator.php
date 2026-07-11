<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback\Validators;

use Capell\Core\EventSourcing\Rollback\Contracts\RollbackValidator;
use Capell\Core\EventSourcing\Rollback\RollbackIssueData;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * Blocks a rollback whose restored state references a blueprint, layout or
 * parent page that no longer exists — restoring it would otherwise fail on a
 * foreign-key constraint or leave a dangling reference.
 */
final class PageReferentialIntegrityRollbackValidator implements RollbackValidator
{
    public function validate(Model $model, array $targetState): array
    {
        $attributes = $targetState['attributes'] ?? [];
        $issues = [];

        $blueprintId = $attributes['blueprint_id'] ?? null;
        if ($blueprintId !== null && ! Blueprint::query()->whereKey($blueprintId)->exists()) {
            $issues[] = RollbackIssueData::blocking(
                code: 'missing_blueprint',
                message: 'The blueprint this revision used no longer exists.',
                path: 'attributes.blueprint_id',
            );
        }

        $layoutId = $attributes['layout_id'] ?? null;
        if ($layoutId !== null && ! Layout::query()->whereKey($layoutId)->exists()) {
            $issues[] = RollbackIssueData::blocking(
                code: 'missing_layout',
                message: 'The layout this revision used no longer exists.',
                path: 'attributes.layout_id',
            );
        }

        $parentId = $attributes['parent_id'] ?? null;
        if ($parentId !== null && ! Page::query()->whereKey($parentId)->exists()) {
            $issues[] = RollbackIssueData::blocking(
                code: 'missing_parent',
                message: 'The parent page this revision sat under no longer exists.',
                path: 'attributes.parent_id',
            );
        }

        return $issues;
    }
}
