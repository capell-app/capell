<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;
use Override;

/**
 * The fixed sidebar groups, in display order. Core and packages choose one of
 * these cases; neither can add a group of its own. A package that needs a new
 * area asks for a case to be added here in a reviewed change.
 */
enum NavigationGroupEnum: string implements HasLabel
{
    case Website = 'website';
    case Media = 'media';
    case Design = 'design';
    case Marketing = 'marketing';
    case Business = 'business';
    case Reports = 'reports';
    case Users = 'users';
    case Settings = 'settings';
    case Developer = 'developer';

    #[Override]
    public function getLabel(): string
    {
        // Literal keys keep every group label visible to the translation drift check.
        return (string) match ($this) {
            self::Website => __('capell-admin::navigation.group_website'),
            self::Media => __('capell-admin::navigation.group_media'),
            self::Design => __('capell-admin::navigation.group_design'),
            self::Marketing => __('capell-admin::navigation.group_marketing'),
            self::Business => __('capell-admin::navigation.group_business'),
            self::Reports => __('capell-admin::navigation.group_reports'),
            self::Users => __('capell-admin::navigation.group_users'),
            self::Settings => __('capell-admin::navigation.group_settings'),
            self::Developer => __('capell-admin::navigation.group_developer'),
        };
    }

    public function translationKey(): string
    {
        return 'capell-admin::navigation.group_' . $this->value;
    }

    public function isCollapsedByDefault(): bool
    {
        return ! in_array($this, [self::Website, self::Media], true);
    }
}
