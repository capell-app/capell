<?php

declare(strict_types=1);

namespace Capell\Core\Support\Properties;

/**
 * Remembers, for one request or job, which unchanged property breaches have
 * already been reported. A page save can announce itself more than once (the
 * model save, then the admin's authoring save), and each announcement re-runs
 * the promoted sync; the breach is still logged only once.
 */
final class PropertyBreachReports
{
    /** @var array<string, true> */
    private array $reported = [];

    /**
     * True the first time a breach is seen in this request or job.
     */
    public function first(int $pageId, int $definitionId, string $valueIdentity): bool
    {
        $key = $pageId . '|' . $definitionId . '|' . $valueIdentity;

        if (isset($this->reported[$key])) {
            return false;
        }

        $this->reported[$key] = true;

        return true;
    }
}
