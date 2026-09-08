<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\EditorImpact;

use Capell\Admin\Contracts\EditorImpact\EditorImpactConsequencePlanner;
use Capell\Core\Data\EditorImpact\EditorImpactConsequenceData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildEditorImpactConsequencesAction
{
    use AsObject;

    /** @return list<EditorImpactConsequenceData> */
    public function handle(Model $record): array
    {
        if (! auth()->check() || ! Gate::allows('update', $record)) {
            return [];
        }

        $consequences = [];

        foreach (app()->tagged(EditorImpactConsequencePlanner::TAG) as $planner) {
            if ($planner instanceof EditorImpactConsequencePlanner) {
                $consequences = [...$consequences, ...$planner->plan($record)];
            }
        }

        return $consequences;
    }
}
