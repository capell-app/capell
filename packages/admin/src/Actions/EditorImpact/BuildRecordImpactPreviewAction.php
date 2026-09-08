<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\EditorImpact;

use Capell\Core\Actions\ContentGraph\BuildContentImpactPreviewAction;
use Capell\Core\Data\ContentGraph\ContentImpactPreviewData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildRecordImpactPreviewAction
{
    use AsObject;

    public function handle(Model $record): ?ContentImpactPreviewData
    {
        if (! auth()->check() || ! Gate::allows('update', $record)) {
            return null;
        }

        $preview = BuildContentImpactPreviewAction::run(
            $record,
            fn (Model $dependency): bool => Gate::allows('view', $dependency),
        );
        $preview->consequences = BuildEditorImpactConsequencesAction::run($record);

        return $preview;
    }
}
