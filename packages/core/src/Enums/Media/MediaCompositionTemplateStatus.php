<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Media;

enum MediaCompositionTemplateStatus: string
{
    case Available = 'available';
    case Missing = 'missing';
    case UnknownPreset = 'unknown_preset';
    case UnsupportedFormat = 'unsupported_format';
    case DimensionMismatch = 'dimension_mismatch';
    case InvalidQuietRegion = 'invalid_quiet_region';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
