<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Security;

/**
 * Where a finding came from, which decides how much it may do on its own.
 *
 * Only a `Builtin` check is deterministic enough to drive an automatic fix.
 * An `Advisory` names a vulnerable package version and may trigger an update
 * path that is itself health-checked. An `Analyser` finding (an AI or other
 * heuristic reviewer) is advice to a person: it is recorded and shown, never
 * acted on unattended.
 */
enum SecurityFindingSource: string
{
    case Builtin = 'builtin';
    case Advisory = 'advisory';
    case Analyser = 'analyser';

    public function mayRemediateAutomatically(): bool
    {
        return $this !== self::Analyser;
    }
}
