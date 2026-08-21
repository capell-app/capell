<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Diagnostics\Checks;

use Capell\Admin\Actions\Diagnostics\CheckAdminPanelAccessAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\Checks\AbstractDoctorCheck;

final class AdminUserAccessCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.admin.access';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        return CheckAdminPanelAccessAction::run();
    }
}
