<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by an asset manifest contribution.
 *
 * Capell validates this marker before the frontend resource graph considers
 * the contribution. Asset handles, dependencies, and placement remain
 * manifest/runtime resource metadata.
 */
interface RegistersExtensionAsset extends ExtensionContribution {}
