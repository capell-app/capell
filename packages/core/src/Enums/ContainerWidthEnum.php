<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ContainerWidthEnum: string
{
    case Default = 'container';

    case Full = 'full';

    case Small = 'sm';

    case Medium = 'md';

    case Large = 'lg';

    case ExtraLarge = 'xl';

    case TwoExtraLarge = '2xl';

    case ThreeExtraLarge = '3xl';

    case FourExtraLarge = '4xl';

    case FiveExtraLarge = '5xl';

    public function getContainerClass(?string $padding = 'px-[6%]'): string
    {
        $class = '';

        if (filled($padding)) {
            $class .= $padding . ' ';
        }

        return $class . match ($this) {
            ContainerWidthEnum::Full => 'w-full',
            ContainerWidthEnum::Small => 'sm:container',
            ContainerWidthEnum::Medium => 'md:container',
            ContainerWidthEnum::Large => 'lg:container',
            ContainerWidthEnum::ExtraLarge => 'xl:container',
            ContainerWidthEnum::TwoExtraLarge => '2xl:container',
            ContainerWidthEnum::ThreeExtraLarge => '3xl:container',
            ContainerWidthEnum::FourExtraLarge => '4xl:container',
            ContainerWidthEnum::FiveExtraLarge => '5xl:container',
            default => 'container',
        };
    }
}
