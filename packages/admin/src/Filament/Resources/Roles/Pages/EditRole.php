<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Roles\Pages;

use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Actions\Shield\BuildRolePermissionChangeSetAction;
use Capell\Admin\Actions\Shield\LogRolePermissionChangesAction;
use Capell\Admin\Actions\Shield\ResetRoleToDefaultPermissionsAction;
use Capell\Admin\Data\Shield\RolePermissionChangeSetData;
use Capell\Admin\Filament\Resources\Roles\Pages\Concerns\HasTopFormActions;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Override;
use RuntimeException;
use Spatie\Permission\Models\Role;

class EditRole extends ShieldEditRole
{
    use HasTopFormActions;

    protected static string $resource = RoleResource::class;

    /**
     * @var list<string>|null
     */
    private ?array $permissionNamesBeforeSave = null;

    private ?string $guardNameBeforeSave = null;

    #[Override]
    public function getFormActionsContentComponent(): Component
    {
        $component = parent::getFormActionsContentComponent();

        if (! $component instanceof Actions) {
            return $component;
        }

        return $component->aboveContent(
            Text::make(fn (): string => $this->permissionChangePreview())
                ->color('gray'),
        );
    }

    public function permissionChangePreview(): string
    {
        $changeSet = $this->previewPermissionChangeSet();

        if (! $changeSet->hasChanges()) {
            return (string) __('capell-admin::generic.role_permissions_no_changes');
        }

        return (string) __('capell-admin::generic.role_permissions_change_summary', [
            'added' => count($changeSet->added),
            'removed' => count($changeSet->removed),
        ]);
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    #[Override]
    protected function getActions(): array
    {
        return [
            Action::make('resetRolePermissions')
                ->label(__('capell-admin::button.reset_role_permissions'))
                ->icon(Heroicon::ArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('capell-admin::generic.reset_role_permissions_heading'))
                ->modalDescription(__('capell-admin::generic.reset_role_permissions_description'))
                ->visible(fn (): bool => $this->isBuiltInRole())
                ->authorize('update')
                ->action(function (): void {
                    $actor = auth()->user();

                    ResetRoleToDefaultPermissionsAction::run(
                        $this->getRecord(),
                        $actor instanceof Model ? $actor : null,
                    );

                    $this->fillForm();

                    Notification::make()
                        ->title(__('capell-admin::generic.reset_role_permissions_success'))
                        ->success()
                        ->send();
                }),
            ...parent::getActions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->guardNameBeforeSave = $this->roleRecord()->guard_name;
        $this->permissionNamesBeforeSave = $this->permissionNamesForRole();

        return parent::mutateFormDataBeforeSave($data);
    }

    #[Override]
    protected function afterSave(): void
    {
        parent::afterSave();

        if ($this->permissionNamesBeforeSave !== null && $this->guardNameBeforeSave !== null) {
            $this->getRecord()->refresh()->load('permissions');
            $guardNameAfterSave = $this->roleRecord()->guard_name;

            LogRolePermissionChangesAction::run(
                $this->getRecord(),
                $this->buildPermissionChangeSet(
                    $this->permissionIdentities($this->permissionNamesBeforeSave, $this->guardNameBeforeSave, $guardNameAfterSave),
                    $this->permissionIdentities($this->permissionNamesForRole(), $guardNameAfterSave, $this->guardNameBeforeSave),
                ),
                auth()->user() instanceof Model ? auth()->user() : null,
            );
        }
    }

    private function isBuiltInRole(): bool
    {
        return in_array($this->roleRecord()->name, ['editor', 'admin', 'super_admin'], true);
    }

    /**
     * @return list<string>
     */
    private function permissionNamesForRole(): array
    {
        return $this->normalizePermissionNames($this->roleRecord()->permissions()->pluck('name'));
    }

    private function roleRecord(): Role
    {
        $record = $this->getRecord();

        throw_unless($record instanceof Role, RuntimeException::class, 'Shield role editor record must be a role.');

        return $record;
    }

    private function previewPermissionChangeSet(): RolePermissionChangeSetData
    {
        $formState = is_array($this->data) ? $this->data : [];
        $changeSet = BuildRolePermissionChangeSetAction::run($this->getRecord(), $formState);
        $guardNameBeforeSave = $this->roleRecord()->guard_name;
        $guardNameAfterSave = $this->guardNameFromFormState($formState);
        $after = $this->selectedPermissionNamesFromFormState($formState);

        return $this->buildPermissionChangeSet(
            $this->permissionIdentities($changeSet->before, $guardNameBeforeSave, $guardNameAfterSave),
            $this->permissionIdentities($after, $guardNameAfterSave, $guardNameBeforeSave),
        );
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function buildPermissionChangeSet(array $before, array $after): RolePermissionChangeSetData
    {
        return new RolePermissionChangeSetData(
            before: $before,
            after: $after,
            added: array_values(array_diff($after, $before)),
            removed: array_values(array_diff($before, $after)),
            unchanged: array_values(array_intersect($before, $after)),
        );
    }

    /**
     * @param  list<string>  $permissionNames
     * @return list<string>
     */
    private function permissionIdentities(array $permissionNames, string $guardName, string $comparisonGuardName): array
    {
        if ($guardName === $comparisonGuardName) {
            return $permissionNames;
        }

        return array_map(
            fn (string $permissionName): string => $guardName . ':' . $permissionName,
            $permissionNames,
        );
    }

    /**
     * @param  Collection<int, string>|array<int, string>  $permissionNames
     * @return list<string>
     */
    private function normalizePermissionNames(Collection|array $permissionNames): array
    {
        return array_values(collect($permissionNames)
            ->filter(fn (mixed $permissionName): bool => is_string($permissionName) && $permissionName !== '')
            ->unique()
            ->sort()
            ->values()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $formState
     */
    private function guardNameFromFormState(array $formState): string
    {
        $guardName = $formState['guard_name'] ?? null;

        return is_string($guardName) && $guardName !== ''
            ? $guardName
            : $this->roleRecord()->guard_name;
    }

    /**
     * @param  array<string, mixed>  $formState
     * @return list<string>
     */
    private function selectedPermissionNamesFromFormState(array $formState): array
    {
        return array_values(collect($formState)
            ->except(['name', 'guard_name', 'select_all', Utils::getTenantModelForeignKey()])
            ->filter(fn (mixed $value): bool => is_array($value))
            ->flatten()
            ->filter(fn (mixed $permissionName): bool => is_string($permissionName) && $permissionName !== '')
            ->unique()
            ->sort()
            ->values()
            ->all());
    }
}
