<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks a manifest class as an extension health-check contribution.
 *
 * Declare the class under the manifest's health-check contribution surface;
 * Capell validates this marker before the owning diagnostics subsystem
 * resolves the implementation.
 */
interface ChecksExtensionHealth extends ExtensionContribution {}
