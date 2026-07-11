<?php

declare(strict_types=1);

use Capell\Core\Enums\ContainerWidthEnum;
use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Filament\Support\Icons\Heroicon;

it('provides presentation metadata for publish statuses', function (): void {
    expect(PublishStatusEnum::pending->getColor())->toBe('warning')
        ->and(PublishStatusEnum::published->getColor())->toBe('success')
        ->and(PublishStatusEnum::deleted->getColor())->toBe('danger')
        ->and(PublishStatusEnum::expired->getColor())->toBe('gray')
        ->and(PublishStatusEnum::disabled->getColor())->toBe('gray')
        ->and(PublishStatusEnum::pending->getIcon())->toBe(Heroicon::Clock)
        ->and(PublishStatusEnum::published->getIcon())->toBe(Heroicon::CheckCircle)
        ->and(PublishStatusEnum::expired->getIcon())->toBe(Heroicon::ExclamationTriangle)
        ->and(PublishStatusEnum::deleted->getIcon())->toBe(Heroicon::XCircle)
        ->and(PublishStatusEnum::disabled->getIcon())->toBe(Heroicon::ShieldExclamation)
        ->and(PublishStatusEnum::pending->getLabel())->toBe(__('capell::generic.pending'))
        ->and(PublishStatusEnum::published->getDescription())->toBe(__('capell::generic.published_description'));
});

it('provides presentation metadata for redirect status codes', function (): void {
    expect(RedirectStatusCodeEnum::Permanent->getColor())->toBe('success')
        ->and(RedirectStatusCodeEnum::Temporary->getColor())->toBe('warning')
        ->and(RedirectStatusCodeEnum::Permanent->getIcon())->toBe(Heroicon::ArrowRight)
        ->and(RedirectStatusCodeEnum::Temporary->getIcon())->toBe(Heroicon::OutlinedArrowRight)
        ->and(__('capell-core::generic.redirect_301'))->toBe('301 Permanent')
        ->and(RedirectStatusCodeEnum::Permanent->getLabel())->toBe('301 Permanent')
        ->and(RedirectStatusCodeEnum::Temporary->getDescription())->toBe('Temporarily redirects to the target URL');
});

it('builds stable container width classes with optional padding', function (): void {
    expect(ContainerWidthEnum::Default->getContainerClass())->toBe('px-[6%] container')
        ->and(ContainerWidthEnum::Full->getContainerClass(null))->toBe('w-full')
        ->and(ContainerWidthEnum::Small->getContainerClass('px-4'))->toBe('px-4 sm:container')
        ->and(ContainerWidthEnum::Medium->getContainerClass(''))->toBe('md:container')
        ->and(ContainerWidthEnum::Large->getContainerClass(null))->toBe('lg:container')
        ->and(ContainerWidthEnum::ExtraLarge->getContainerClass(null))->toBe('xl:container')
        ->and(ContainerWidthEnum::TwoExtraLarge->getContainerClass(null))->toBe('2xl:container')
        ->and(ContainerWidthEnum::ThreeExtraLarge->getContainerClass(null))->toBe('3xl:container')
        ->and(ContainerWidthEnum::FourExtraLarge->getContainerClass(null))->toBe('4xl:container')
        ->and(ContainerWidthEnum::FiveExtraLarge->getContainerClass(null))->toBe('5xl:container');
});
