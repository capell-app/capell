<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

/** Why a person cannot be signed in with an admin sign-in link. */
enum AdminLoginLinkRefusal: string
{
    case NoPanelAccess = 'no_panel_access';
    case TwoFactorEnabled = 'two_factor_enabled';

    public function message(): string
    {
        return (string) __('capell-admin::login_links.refusals.' . $this->value);
    }
}
