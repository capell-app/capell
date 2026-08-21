<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Enums;

use BackedEnum;
use Capell\Admin\Contracts\EnumPresentationContributor;
use Capell\Admin\Data\EnumPresentationData;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Enums\CacheTime;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\ExtensionReleaseKindEnum;
use Capell\Core\Enums\HeaderPositionEnum;
use Capell\Core\Enums\ImageSourceType;
use Capell\Core\Enums\InteractionTargetType;
use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Enums\MediaAlignment;
use Capell\Core\Enums\MenuAlignmentEnum;
use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class CoreEnumPresentationContributor implements EnumPresentationContributor
{
    public function present(UnitEnum $enum): ?EnumPresentationData
    {
        $labelKey = match ($enum::class) {
            CacheTime::class => 'capell::generic.' . $enum->value,
            ExtensionAutoUpdatePolicyEnum::class => 'capell-core::extensions.auto_update_policies.' . $enum->value,
            ExtensionReleaseKindEnum::class => 'capell-core::extensions.release_kinds.' . $enum->value,
            HeaderPositionEnum::class => 'capell-admin::form.header_position_' . match ($enum) {
                HeaderPositionEnum::Static_ => 'disabled', HeaderPositionEnum::Fixed => 'fixed', HeaderPositionEnum::Sticky => 'sticky', HeaderPositionEnum::ScrollUp => 'scroll_up',
            },
            ImageSourceType::class => 'capell::media.image_source.' . $enum->value,
            InteractionTargetType::class => 'capell::generic.' . $enum->value,
            LayoutEnum::class => 'capell::layout.' . $enum->value,
            MediaAlignment::class => 'capell::media.alignment.' . $enum->value,
            MenuAlignmentEnum::class => 'capell-admin::generic.' . $enum->value,
            PublishStatusEnum::class => 'capell::generic.' . strtolower((string) $enum->value),
            PublishVisibilityStateEnum::class => 'capell::generic.' . $enum->value,
            RedirectStatusCodeEnum::class => 'capell-core::generic.redirect_' . $enum->value,
            UrlTypeEnum::class => 'capell::generic.' . $enum->value,
            AssetEnum::class => 'capell::generic.' . $enum->value,
            default => null,
        };

        if ($labelKey === null) {
            return null;
        }

        return new EnumPresentationData(
            label: (string) __($labelKey),
            color: $this->color($enum),
            icon: $this->icon($enum),
            description: $this->description($enum),
        );
    }

    private function color(UnitEnum $enum): ?string
    {
        return match ($enum) {
            PublishStatusEnum::pending => 'warning', PublishStatusEnum::published => 'success', PublishStatusEnum::deleted => 'danger', PublishStatusEnum::expired, PublishStatusEnum::disabled => 'gray',
            RedirectStatusCodeEnum::Permanent => 'success', RedirectStatusCodeEnum::Temporary => 'warning',
            UrlTypeEnum::Alias => 'secondary', UrlTypeEnum::Redirect => 'info',
            AssetEnum::Page => (string) config('capell-admin.assets.page.color', 'primary'),
            default => null,
        };
    }

    private function icon(UnitEnum $enum): string|BackedEnum|null
    {
        return match ($enum) {
            PublishStatusEnum::pending => Heroicon::Clock, PublishStatusEnum::published => Heroicon::CheckCircle, PublishStatusEnum::expired => Heroicon::ExclamationTriangle, PublishStatusEnum::deleted => Heroicon::XCircle, PublishStatusEnum::disabled => Heroicon::ShieldExclamation,
            RedirectStatusCodeEnum::Permanent => Heroicon::ArrowRight, RedirectStatusCodeEnum::Temporary => Heroicon::OutlinedArrowRight,
            UrlTypeEnum::Alias => Heroicon::Link, UrlTypeEnum::Redirect => Heroicon::OutlinedArrowPathRoundedSquare,
            AssetEnum::Page => config('capell-admin.assets.page.icon', Heroicon::OutlinedRectangleStack),
            default => null,
        };
    }

    private function description(UnitEnum $enum): ?string
    {
        return match ($enum) {
            PublishStatusEnum::pending => (string) __('capell::generic.pending_description'), PublishStatusEnum::published => (string) __('capell::generic.published_description'), PublishStatusEnum::expired => (string) __('capell::generic.expired_description'), PublishStatusEnum::deleted => (string) __('capell::generic.deleted_description'), PublishStatusEnum::disabled => (string) __('capell::generic.disabled_description'),
            RedirectStatusCodeEnum::Permanent => (string) __('capell-core::generic.redirect_permanent_description'), RedirectStatusCodeEnum::Temporary => (string) __('capell-core::generic.redirect_temporary_description'),
            UrlTypeEnum::Alias => (string) __('capell::generic.alias_description'), UrlTypeEnum::Redirect => (string) __('capell::generic.redirect_description'),
            default => null,
        };
    }
}
