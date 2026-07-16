<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a migration manifest contribution.
 *
 * Migration command, phase, and ordering metadata are manifest-owned. Capell
 * checks this marker before install or upgrade orchestration accepts the
 * contribution.
 */
interface RunsExtensionMigration extends ExtensionContribution {}
