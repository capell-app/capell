<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Media;

enum MediaCompositionCropBehaviour: string
{
    /** The image fills the frame; edges outside the preset ratio are cropped around the focal point. */
    case Cover = 'cover';

    /** The whole image stays visible inside the frame; no content is cropped. */
    case Contain = 'contain';
}
