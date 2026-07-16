<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a scheduled-job manifest contribution.
 *
 * Schedule and execution metadata remain in the manifest. Capell validates
 * this marker before the test harness or runtime scheduler accepts the class.
 */
interface RunsScheduledExtensionJob extends ExtensionContribution {}
