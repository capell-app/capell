<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

/**
 * Marks the class declared by a Filament-widget manifest contribution.
 *
 * Dashboard placement and other presentation metadata remain in the manifest;
 * this contract is the class-compatibility boundary checked before Admin
 * resolves the widget.
 */
interface RegistersExtensionFilamentWidget extends ExtensionContribution {}
