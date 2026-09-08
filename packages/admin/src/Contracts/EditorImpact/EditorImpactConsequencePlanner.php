<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\EditorImpact;

use Capell\Core\Data\EditorImpact\EditorImpactConsequenceData;
use Illuminate\Database\Eloquent\Model;

interface EditorImpactConsequencePlanner
{
    public const string TAG = 'capell-admin:editor-impact-consequence-planner';

    /**
     * Read-only planning for an authorised record. Implementations must also
     * restrict affected surfaces to the current actor's accessible sites.
     *
     * @return list<EditorImpactConsequenceData>
     */
    public function plan(Model $record): array;
}
