<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a `builder-block` manifest contribution.
 *
 * A builder block is a Filament Builder block that editors add to page content
 * in the admin. It is not a dashboard widget and has no dashboard placement;
 * rendering on the public site stays with the block's own frontend component.
 */
interface RegistersExtensionBuilderBlock extends ExtensionContribution {}
