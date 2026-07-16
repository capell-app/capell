<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a frontend-component contribution.
 *
 * Capell validates the marker during manifest loading before the Frontend
 * package makes the component available to public rendering.
 */
interface RegistersExtensionFrontendComponent extends ExtensionContribution {}
