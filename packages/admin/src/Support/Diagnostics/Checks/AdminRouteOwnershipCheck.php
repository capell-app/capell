<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Diagnostics\Checks;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Override;

/**
 * Reports a route cache built before routes recorded their ownership (every admin route is refused until
 * it is rebuilt), routes the ownership refuses, and host routes that answer on an admin panel's path.
 */
final readonly class AdminRouteOwnershipCheck implements DoctorCheck
{
    public function __construct(private InstalledPanelRuntime $runtime) {}

    #[Override]
    public function check(bool $installSummary = false): DoctorCheckResultData
    {
        $unrecorded = $this->runtime->unrecordedRoutes();
        $refused = $this->runtime->refusedRoutes();
        $collisions = $this->runtime->panelPathCollisions();
        $pages = $this->runtime->pagesRequiringGuestDeclaration();
        $messages = [];
        $remediation = [];
        if ($unrecorded !== []) {
            $messages[] = __('capell-admin::message.route_ownership_check_unrecorded', ['count' => count($unrecorded)]);
            $remediation[] = __('capell-admin::message.route_ownership_check_remediation_cache');
        }

        if ($refused !== []) {
            $messages[] = __('capell-admin::message.route_ownership_check_refused', ['routes' => implode(', ', $refused)]);
        }

        if ($collisions !== []) {
            $messages[] = __('capell-admin::message.route_ownership_check_collisions', ['routes' => implode(', ', $collisions)]);
        }

        if ($refused !== [] || $collisions !== []) {
            $remediation[] = __('capell-admin::message.route_ownership_check_remediation_routes');
        }

        if ($pages !== []) {
            $messages[] = __('capell-admin::message.route_pages_require_authentication', ['routes' => implode(', ', $pages)]);
            $remediation[] = __('capell-admin::message.route_pages_guest_remediation');
        }

        $passed = $messages === [];

        return new DoctorCheckResultData(
            label: __('capell-admin::message.route_ownership_check_label'),
            passed: $passed,
            message: $passed ? __('capell-admin::message.route_ownership_check_ok') : implode(' ', $messages),
            remediation: $passed ? null : implode(' ', $remediation),
            id: 'admin.routes.ownership',
            severity: $unrecorded !== [] || $refused !== [] ? DoctorCheckSeverity::Critical : DoctorCheckSeverity::Warning,
            evidence: ['unrecorded' => count($unrecorded), 'refused' => $refused, 'collisions' => $collisions, 'pages_requiring_guest_declaration' => $pages],
        );
    }
}
