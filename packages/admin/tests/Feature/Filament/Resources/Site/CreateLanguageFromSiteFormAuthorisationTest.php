<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Sites\Pages\CreateSite;
use Capell\Admin\Tests\Fixtures\Models\SiteScopedFormTestUser;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** @param list<string> $permissions */
function siteFormActor(array $permissions): SiteScopedFormTestUser
{
    $site = Site::factory()->createOne();
    config()->set('auth.providers.users.model', SiteScopedFormTestUser::class);

    $actor = new SiteScopedFormTestUser;
    $actor->forceFill(User::factory()->raw());
    $actor->save();
    SiteScopedFormTestUser::rememberAssignedSiteIds((int) $actor->getKey(), collect([$site->id]));

    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

/** @return array<string, mixed> */
function createLanguageFormData(): array
{
    return [
        'name' => 'Klingon',
        'code' => 'tlh',
        'locale' => 'tlh',
        'flag' => 'tlh',
        'status' => true,
        'default' => false,
        'order' => 5,
    ];
}

it('refuses to create a language from the site form without Create:Language', function (): void {
    test()->actingAs(siteFormActor(['ViewAny:Site', 'View:Site', 'Create:Site']));

    $component = Livewire::test(CreateSite::class)->assertSuccessful();

    $component
        ->call('mountAction', 'createLanguage', [], ['schemaComponent' => 'form.languages::data::section'])
        ->set('mountedActions.0.data', createLanguageFormData())
        ->call('callMountedAction');

    expect(Language::query()->where('code', 'tlh')->exists())->toBeFalse();
});

it('offers the create language action only when Create:Language is granted', function (): void {
    $action = TestAction::make('createLanguage')->schemaComponent('languages::data::section', schema: 'form');

    test()->actingAs(siteFormActor(['ViewAny:Site', 'View:Site', 'Create:Site']));
    Livewire::test(CreateSite::class)->assertSuccessful()->assertActionDoesNotExist($action);

    test()->actingAs(siteFormActor(['ViewAny:Site', 'View:Site', 'Create:Site', 'Create:Language']));
    Livewire::test(CreateSite::class)->assertSuccessful()->assertActionVisible($action);
});
