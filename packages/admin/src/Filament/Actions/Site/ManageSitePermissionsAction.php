<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Site;

use Capell\Admin\Actions\Sites\SyncSitePermissionsAction;
use Capell\Admin\Data\SitePermissions\SyncSitePermissionsData;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\Permission\Models\Role;

final class ManageSitePermissionsAction extends Action
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::button.manage_site_permissions'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->modalHeading(__('capell-admin::form.site_permissions'))
            ->modalDescription(__('capell-admin::generic.site_permissions_modal_description'))
            ->visible(fn (?Model $record): bool => $record instanceof Site
                && auth()->user()?->can('managePermissions', $record) === true)
            ->fillForm(fn (Site $record): array => [
                'assignments' => $this->assignmentsFor($record),
            ])
            ->schema([
                Repeater::make('assignments')
                    ->hiddenLabel()
                    ->columns()
                    ->schema([
                        Select::make('user_id')
                            ->label(__('capell-admin::form.site_permissions_user'))
                            ->options(fn (): array => $this->userOptions())
                            ->searchable()
                            ->required()
                            ->distinct(),
                        Select::make('role_ids')
                            ->label(__('capell-admin::form.site_permissions_roles'))
                            ->options(fn (): array => $this->roleOptions())
                            ->multiple()
                            ->required(),
                    ]),
            ])
            ->action(function (Site $record, array $data): void {
                $actor = auth()->user();

                throw_unless($actor instanceof User, AuthorizationException::class);

                /** @var array<string, mixed> $data */
                SyncSitePermissionsAction::run(
                    actor: $actor,
                    site: $record,
                    input: SyncSitePermissionsData::fromArray($data),
                );
            });
    }

    public static function getDefaultName(): string
    {
        return 'manage_site_permissions';
    }

    /**
     * @return array<int, array{user_id: int, role_ids: array<int>}>
     */
    private function assignmentsFor(Site $site): array
    {
        $userModel = $this->userModelClass();
        $modelHasRolesTable = $this->modelHasRolesTable();
        $teamColumn = $this->teamColumn();
        $modelTypes = array_values(array_unique([
            $userModel,
            (new $userModel)->getMorphClass(),
        ]));

        $rows = DB::table($modelHasRolesTable)
            ->where($teamColumn, $site->getKey())
            ->whereIn('model_type', $modelTypes)
            ->orderBy('model_id')
            ->get(['model_id', 'role_id']);

        $userIds = $rows
            ->pluck('model_id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->unique()
            ->values()
            ->all();

        if ($userIds === []) {
            return [];
        }

        $existingUserIds = $userModel::query()
            ->whereKey($userIds)
            ->pluck('id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->all();

        return $rows
            ->whereIn('model_id', $existingUserIds)
            ->groupBy('model_id')
            ->map(fn (Collection $roleRows, int $userId): array => [
                'user_id' => $userId,
                'role_ids' => $roleRows
                    ->pluck('role_id')
                    ->map(fn (mixed $roleId): int => (int) $roleId)
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function userOptions(): array
    {
        $userModel = $this->userModelClass();

        return $userModel::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [
                (int) $user->getKey() => sprintf('%s <%s>', $user->name, $user->email),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function roleOptions(): array
    {
        return Role::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, mixed $roleId): array => [
                (int) $roleId => $name,
            ])
            ->all();
    }

    private function modelHasRolesTable(): string
    {
        $tableNames = config('permission.table_names', []);

        return is_array($tableNames) && is_string($tableNames['model_has_roles'] ?? null)
            ? $tableNames['model_has_roles']
            : 'model_has_roles';
    }

    private function teamColumn(): string
    {
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        return is_string($teamColumn) && $teamColumn !== '' ? $teamColumn : 'team_id';
    }

    /**
     * @return class-string<User>
     */
    private function userModelClass(): string
    {
        $userModel = config('auth.providers.users.model', User::class);

        return is_string($userModel) && is_a($userModel, User::class, true) ? $userModel : User::class;
    }
}
