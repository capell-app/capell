<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Models\SecurityWeaknessFinding;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Undo a dismissal so the finding counts as open again. */
class ReopenSecurityWeaknessFindingAction
{
    use AsFake;
    use AsObject;

    public function handle(SecurityWeaknessFinding $finding): SecurityWeaknessFinding
    {
        $finding->fill([
            'status' => SecurityFindingStatus::Open,
            'dismissed_at' => null,
            'dismissed_by' => null,
            'dismissal_note' => null,
        ]);
        $finding->save();

        return $finding;
    }
}
