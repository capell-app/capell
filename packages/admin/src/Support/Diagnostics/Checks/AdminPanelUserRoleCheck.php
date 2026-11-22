<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Diagnostics\Checks;

use BezhanSalleh\FilamentShield\Support\Utils;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Override;

/**
 * Shield's panel user setting gives every user created in a web request a login to the admin
 * panel. That is unsafe wherever the users table also holds registrations or customer accounts.
 */
final class AdminPanelUserRoleCheck implements DoctorCheck
{
    #[Override]
    public function check(bool $installSummary = false): DoctorCheckResultData
    {
        $userModel = config('auth.providers.users.model');
        $usesTrait = is_string($userModel)
            && class_exists($userModel)
            && in_array(HasPanelShield::class, class_uses_recursive($userModel), true);
        $enabled = Utils::isPanelUserRoleEnabled();
        $unsafe = $usesTrait && $enabled;

        return new DoctorCheckResultData(
            label: __('capell-admin::message.panel_user_check_label'),
            passed: ! $unsafe,
            message: $unsafe
                ? __('capell-admin::message.panel_user_check_failed', ['role' => Utils::getPanelUserRoleName()])
                : __('capell-admin::message.panel_user_check_ok'),
            remediation: $unsafe ? (string) __('capell-admin::message.panel_user_check_remediation') : null,
            id: 'admin.panel_user.auto_assign',
            severity: $unsafe ? DoctorCheckSeverity::Critical : DoctorCheckSeverity::Info,
            evidence: ['user_model' => is_string($userModel) ? $userModel : null, 'uses_has_panel_shield' => $usesTrait, 'panel_user_enabled' => $enabled],
        );
    }
}
