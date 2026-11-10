<?php

declare(strict_types=1);

namespace Workbench\App\Support\Schemas;

use Capell\Admin\Support\Schemas\AbstractPageSchemaExtender;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Override;

/** Focus these screenshot records on the editor, without unrelated relation tables. */
final class FocusCustomPageFieldsScreenshot extends AbstractPageSchemaExtender
{
    #[Override]
    public function extendRelationManagers(Model $record, array $relationManagers): array
    {
        if ($record instanceof Page
            && $record->blueprint?->key === 'case-study'
            && str_starts_with($record->name, 'Case study custom fields - ')) {
            return [];
        }

        return $relationManagers;
    }
}
