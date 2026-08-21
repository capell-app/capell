<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Media;

use Capell\Core\Data\Media\MediaUploadConfigurationData;

/**
 * Builds neutral media-upload configuration for a UI adapter.
 *
 * The configuration deliberately contains no Filament component or callback;
 * Admin and other consumers translate it at their own UI boundary.
 */
interface MediaUploadConfigurationFactory
{
    public function make(string $name): MediaUploadConfigurationData;
}
