<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Data\Rendering\FrontendResourceContextData;
use Capell\Frontend\Data\Assets\FrontendResourceSelectionData;

/**
 * Select declarations and declared hints before graph validation and resolution.
 * Tag implementations with TAG. Policies must not query or render output.
 * Dependencies of retained resources must also be retained; missing dependencies
 * remain graph errors rather than being silently removed or reintroduced.
 */
interface FrontendResourceSelectionPolicy
{
    public const string TAG = 'capell.frontend.resource-selection-policy';

    public function select(FrontendResourceContextData $context, FrontendResourceSelectionData $selection): FrontendResourceSelectionData;
}
