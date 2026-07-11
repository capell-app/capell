<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum MediaCollectionEnum: string
{
    case Image = 'image';

    case Video = 'video';

    case Logo = 'logo';

    case LogoInverted = 'logo_inverted';

    case BackgroundImage = 'background_image';

    case SocialImage = 'social_image';
}
