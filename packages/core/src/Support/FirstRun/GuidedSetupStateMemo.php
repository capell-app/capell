<?php

declare(strict_types=1);

namespace Capell\Core\Support\FirstRun;

use Capell\Core\Data\FirstRun\GuidedSetupStateData;

/**
 * Keeps what guided setup is owed to each person for the rest of the request, so the
 * dashboard redirect, page access and the page itself do not each rebuild it. Bound as
 * a scoped singleton, so every request starts empty; anything that changes the answer
 * (finishing, skipping, going live) calls flush().
 */
final class GuidedSetupStateMemo
{
    /** @var array<string, GuidedSetupStateData> */
    private array $states = [];

    public function remember(string $actorKey, callable $resolve): GuidedSetupStateData
    {
        return $this->states[$actorKey] ??= $resolve();
    }

    public function flush(): void
    {
        $this->states = [];
    }
}
