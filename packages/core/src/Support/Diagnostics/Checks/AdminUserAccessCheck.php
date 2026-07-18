<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Actions\Diagnostics\CheckAdminPanelAccessAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;

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
