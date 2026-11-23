<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\CreateCustomThemeAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)->group('theme');

function themeCreatorForOneSite(): User
{
    $user = User::factory()->createOne();
    $user->assignedSiteIds = collect([(int) Site::factory()->createOne()->getKey()]);

    foreach (['view_any', 'view', 'create'] as $affix) {
        $user->givePermissionTo(Permission::findOrCreate(ResourceEnum::Theme->permission($affix), 'web'));
    }

    expect($user->isGlobalAdmin())->toBeFalse();

    return $user;
}

it('does not let a site-scoped theme creator take over the installation default theme', function (): void {
    Blueprint::factory()->theme()->default()->create();
    $current = Theme::factory()->createOne(['default' => true]);
    test()->actingAs(themeCreatorForOneSite());

    // The field is hidden, and a forged value for it is ignored by the form.
    Livewire::test(ManageThemes::class)
        ->mountAction('createTheme')
        ->assertFormFieldHidden('custom_default')
        ->setActionData(['custom_name' => 'Scoped brand', 'custom_key' => 'scoped-brand', 'custom_default' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Theme::query()->where('key', 'scoped-brand')->firstOrFail()->default)->toBeFalse()
        ->and($current->fresh()->default)->toBeTrue();

    // The action refuses the same value when it is reached without the form.
    expect(fn () => CreateCustomThemeAction::run(name: 'Forged', key: 'forged', default: true))
        ->toThrow(AuthorizationException::class)
        ->and(Theme::query()->where('key', 'forged')->exists())->toBeFalse()
        ->and($current->fresh()->default)->toBeTrue();
});

it('lets an administrator with unrestricted site access set the default theme when creating one', function (): void {
    Blueprint::factory()->theme()->default()->create();
    $current = Theme::factory()->createOne(['default' => true]);
    test()->actingAsAdmin();

    Livewire::test(ManageThemes::class)
        ->mountAction('createTheme')
        ->assertFormFieldVisible('custom_default')
        ->setActionData(['custom_name' => 'Global brand', 'custom_key' => 'global-brand', 'custom_default' => true])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Theme::query()->where('key', 'global-brand')->firstOrFail()->default)->toBeTrue()
        ->and($current->fresh()->default)->toBeFalse();
});
