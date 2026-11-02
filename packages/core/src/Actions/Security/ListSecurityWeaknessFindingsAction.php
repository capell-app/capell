<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Security;

use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Models\SecurityWeaknessFinding;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Findings for display: most severe first, then most recently seen. */
final class ListSecurityWeaknessFindingsAction
{
    use AsFake;
    use AsObject;

    /** @return Collection<int, SecurityWeaknessFinding> */
    public function handle(bool $includeDismissed = false, bool $includeResolved = false): Collection
    {
        $statuses = [SecurityFindingStatus::Open->value, SecurityFindingStatus::Remediated->value];

        if ($includeDismissed) {
            $statuses[] = SecurityFindingStatus::Dismissed->value;
        }

        if ($includeResolved) {
            $statuses[] = SecurityFindingStatus::Resolved->value;
        }

        return SecurityWeaknessFinding::query()
            ->whereIn('status', $statuses)
            ->orderByRaw("case severity when 'critical' then 4 when 'high' then 3 when 'medium' then 2 else 1 end desc")
            ->latest('last_detected_at')
            ->get();
    }
}
