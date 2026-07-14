<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Diagnostics;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class CheckAdminPanelAccessAction
{
    use AsAction;

    public function handle(): DoctorCheckResultData
    {
        $userModel = config('auth.providers.users.model');
        $guard = (string) config('auth.defaults.guard', 'web');
        $roleModel = config('permission.models.role');
        $roleName = (string) config('filament-shield.super_admin.name', config('capell.roles.super_admin', 'super_admin'));
        $panelId = (string) config('capell-admin.panel.id', 'admin');

        $baseEvidence = [
            'user_model' => is_string($userModel) ? $userModel : null,
            'role_model' => is_string($roleModel) ? $roleModel : null,
            'guard' => $guard,
            'panel_id' => $panelId,
            'role_name' => $roleName,
        ];

        if (! is_string($userModel) || ! is_a($userModel, Authenticatable::class, true)) {
            return $this->failed('The configured auth provider user model is invalid.', $baseEvidence);
        }

        $userPrototype = new $userModel;
        if (! Schema::hasTable($userPrototype->getTable())) {
            return $this->failed('The users table does not exist.', $baseEvidence);
        }

        $panelRegistered = true;
        try {
            $panel = Filament::getPanel($panelId);
        } catch (Throwable) {
            $panel = Panel::make()->id($panelId);
            $panelRegistered = false;
        }

        /** @var list<Model&Authenticatable> $users */
        $users = $userModel::query()->limit(250)->get()->all();
        $accessibleUsers = 0;

        foreach ($users as $user) {
            if (! $user instanceof FilamentUser) {
                continue;
            }

            try {
                if ($user->canAccessPanel($panel)) {
                    $accessibleUsers++;
                }
            } catch (Throwable) {
                // A broken role/guard/pivot configuration is evidence of no proven access.
            }
        }

        $roleCount = 0;
        $assignmentCount = 0;
        try {
            $userMorphType = $userPrototype->getMorphClass();
        } catch (Throwable) {
            $userMorphType = $userModel;
        }

        if (is_string($roleModel) && is_a($roleModel, Model::class, true)) {
            /** @var Model $rolePrototype */
            $rolePrototype = new $roleModel;
            $roleTable = $rolePrototype->getTable();
            $pivotTable = (string) config('permission.table_names.model_has_roles', 'model_has_roles');

            if (Schema::hasTable($roleTable)) {
                $roleIds = $roleModel::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $guard)
                    ->pluck($rolePrototype->getKeyName());
                $roleCount = $roleIds->count();

                if (Schema::hasTable($pivotTable) && $roleIds->isNotEmpty()) {
                    $assignmentCount = DB::table($pivotTable)
                        ->whereIn('role_id', $roleIds)
                        ->where('model_type', $userMorphType)
                        ->count();
                }
            }
        }

        $evidence = [
            ...$baseEvidence,
            'user_count' => count($users),
            'matching_role_count' => $roleCount,
            'matching_assignment_count' => $assignmentCount,
            'accessible_user_count' => $accessibleUsers,
            'panel_registered' => $panelRegistered,
        ];

        if ($accessibleUsers === 0) {
            return $this->failed(
                match (true) {
                    count($users) === 0 => 'No users exist.',
                    $assignmentCount === 0 => 'Users exist but no role assignments were found.',
                    default => 'No configured user can access the Filament admin panel.',
                },
                $evidence,
            );
        }

        return new DoctorCheckResultData(
            label: 'Admin user access',
            passed: true,
            message: sprintf('%d configured user(s) have effective admin panel access.', $accessibleUsers),
            id: 'core.admin.access',
            severity: DoctorCheckSeverity::Critical,
            evidence: $evidence,
        );
    }

    /** @param array<string, mixed> $evidence */
    private function failed(string $message, array $evidence): DoctorCheckResultData
    {
        return new DoctorCheckResultData(
            label: 'Admin user access',
            passed: false,
            message: $message,
            remediation: 'Create a user with the configured guard and grant effective access to the Filament admin panel.',
            id: 'core.admin.access',
            severity: DoctorCheckSeverity::Critical,
            evidence: $evidence,
        );
    }
}
