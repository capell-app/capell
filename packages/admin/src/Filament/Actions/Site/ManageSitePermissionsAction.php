<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Site;

use Capell\Admin\Actions\Sites\SyncSitePermissionsAction;
use Capell\Admin\Data\SitePermissions\SitePermissionsReviewData;
use Capell\Admin\Data\SitePermissions\SyncSitePermissionsData;
use Capell\Core\Models\Site;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
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
                'baseline' => resolve(SyncSitePermissionsAction::class)->baseline($record),
            ])
            ->steps([
                Step::make(__('capell-admin::site_permissions.assignments'))
                    ->schema([
                        Hidden::make('baseline'),
                        Hidden::make('review_token'),
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
                                    ->options(fn (Site $record): array => $this->roleOptions($record))
                                    ->multiple()
                                    ->required(),
                            ]),
                    ])
                    ->afterValidation(function (Site $record, Get $get, Set $set): void {
                        $actor = auth()->user();
                        throw_unless($actor instanceof User, AuthorizationException::class);
                        $review = $this->withFormErrors(fn (): SitePermissionsReviewData => resolve(SyncSitePermissionsAction::class)->review(
                            $actor,
                            $record,
                            SyncSitePermissionsData::fromArray(['assignments' => $get('assignments')]),
                            baseline: (string) $get('baseline'),
                        ));
                        $set('review_token', $review->confirmationToken());
                        $set('confirm_revocations', false);
                    }),
                Step::make(__('capell-admin::site_permissions.review'))
                    ->schema([
                        View::make('capell-admin::components.site-permissions-review')
                            ->viewData(fn (Get $get): array => [
                                'review' => filled($get('review_token'))
                                    ? SitePermissionsReviewData::fromConfirmationToken((string) $get('review_token'))
                                    : null,
                            ]),
                        Checkbox::make('confirm_revocations')
                            ->label(__('capell-admin::site_permissions.confirm_revocations'))
                            ->accepted()
                            ->required()
                            ->visible(fn (Get $get): bool => filled($get('review_token'))
                                && SitePermissionsReviewData::fromConfirmationToken((string) $get('review_token'))->revocations !== []),
                    ]),
            ])
            ->action(function (Site $record, array $data): void {
                $actor = auth()->user();

                throw_unless($actor instanceof User, AuthorizationException::class);

                /** @var array<string, mixed> $data */
                $this->withFormErrors(fn () => SyncSitePermissionsAction::run(
                    actor: $actor,
                    site: $record,
                    input: SyncSitePermissionsData::fromArray($data),
                    confirmationToken: is_string($data['review_token'] ?? null) ? $data['review_token'] : null,
                ));
            });
    }

    #[Override]
    public static function getDefaultName(): string
    {
        return 'manage_site_permissions';
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    private function withFormErrors(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $validationException) {
            $livewire = $this->getHasActionsLivewire();
            throw_if(! $livewire instanceof HasActions, LogicException::class, 'Site permission form errors require a mounted action.');
            $schemaName = $livewire->getMountedActionSchemaName();
            /** @var Schema $schema */
            $schema = $livewire->{$schemaName};
            $statePath = $schema->getStatePath();

            throw ValidationException::withMessages(collect($validationException->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$statePath . '.' . $key => $messages])
                ->all());
        }
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
    private function roleOptions(Site $site): array
    {
        return Role::query()
            ->where('name', '!=', $this->superAdminRoleName())
            ->where('guard_name', 'web')
            ->where(fn ($query) => $query->whereNull($this->teamColumn())->orWhere($this->teamColumn(), $site->getKey()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, mixed $roleId): array => [
                (int) $roleId => $name,
            ])
            ->all();
    }

    private function superAdminRoleName(): string
    {
        return (string) config('filament-shield.super_admin.name', 'super_admin');
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
