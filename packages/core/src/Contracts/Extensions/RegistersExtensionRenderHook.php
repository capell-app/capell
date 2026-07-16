<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a render-hook manifest contribution.
 *
 * Hook name, placement, and public-output metadata stay in the manifest.
 * Capell validates this marker before Frontend registers the hook.
 */
interface RegistersExtensionRenderHook extends ExtensionContribution {}
