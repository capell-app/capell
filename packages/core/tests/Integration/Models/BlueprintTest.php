<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Exceptions\UnknownBlueprintSubjectException;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRoleRestriction;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

it('has many pages', function (): void {
    $blueprint = Blueprint::factory()->page()->create();

    Page::factory()->createOne(['blueprint_id' => $blueprint->id]);

    expect($blueprint->refresh())
        ->pages->toHaveCount(1);
});

it('has many sites', function (): void {
    $blueprint = Blueprint::factory()->site()->create();

    Site::factory()->createOne(['blueprint_id' => $blueprint->id]);

    expect($blueprint->refresh())
        ->sites->toHaveCount(1);
});

it('has many themes', function (): void {
    $blueprint = Blueprint::factory()->theme()->create();

    Theme::factory()->createOne(['blueprint_id' => $blueprint->id]);

    expect($blueprint->refresh())
        ->themes->toHaveCount(1);
});

it('can get groups', function (): void {
    Blueprint::factory()
        ->count(3)
        ->sequence(
            ['group' => 'first'],
            ['group' => 'second'],
            ['group' => 'first'],
        )
        ->create();

    expect(Blueprint::getGroups())
        ->toBe([
            'first' => 'first (2)',
            'second' => 'second (1)',
        ]);
});

it('can scope admin page asset excluding system', function (): void {
    Blueprint::factory(3)
        ->page()
        ->sequence(
            ['group' => 'article'],
            ['group' => BlueprintGroupEnum::System->value],
            ['group' => null],
        )
        ->create();

    $result = Blueprint::query()->adminResource('default')->get();

    expect($result)->toHaveCount(2);
});

it('can scope hidden system group', function (): void {
    Blueprint::factory()->createOne(['group' => BlueprintGroupEnum::System->value]);
    Blueprint::factory()->createOne(['group' => 'custom']);

    $result = Blueprint::query()->hiddenSystemGroup()->get();

    expect($result)->toHaveCount(1);
});

it('can scope page type', function (): void {
    Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Page]);
    Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Site]);

    $result = Blueprint::query()->pageType()->get();

    expect($result)->toHaveCount(1);
});

it('can scope site type', function (): void {
    Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Site]);
    Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Page]);

    $result = Blueprint::query()->siteType()->get();

    expect($result)->toHaveCount(1);
});

it('can scope theme type', function (): void {
    Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Theme]);
    Blueprint::factory()->createOne(['type' => BlueprintSubjectEnum::Page]);

    $result = Blueprint::query()->themeType()->get();

    expect($result)->toHaveCount(1);
});

it('rejects unregistered blueprint subject scopes', function (): void {
    expect(fn (): mixed => Blueprint::query()->type('vendor.missing.subject')->get())
        ->toThrow(UnknownBlueprintSubjectException::class, 'is not registered');
});

it('can scope sorted', function (): void {
    Blueprint::factory()->createOne(['order' => 2]);
    Blueprint::factory()->createOne(['order' => 1]);
    Blueprint::factory()->createOne(['order' => 3]);

    $result = Blueprint::query()->ordered()->pluck('order')->all();

    expect($result)->toBe([1, 2, 3]);
});

it('can scope listable', function (): void {
    Blueprint::factory()->createOne(['meta' => ['listable' => true]]);
    Blueprint::factory()->createOne(['meta' => ['listable' => false]]);
    Blueprint::factory()->createOne(['meta' => null]);
    // meta present but without the listable key: must be treated as listable.
    // On MySQL this is the case the JSON_CONTAINS path-null bug excluded.
    Blueprint::factory()->createOne(['meta' => ['sitemap' => true]]);

    $result = Blueprint::query()->listable()->get();

    expect($result)->toHaveCount(3);
});

it('can scope visible', function (): void {
    Blueprint::factory()->createOne(['meta' => ['hidden' => true]]);
    Blueprint::factory()->createOne(['meta' => ['hidden' => false]]);
    Blueprint::factory()->createOne(['meta' => null]);

    $result = Blueprint::query()->visible()->get();

    expect($result)->toHaveCount(2);
});

it('can scope accessible', function (): void {
    Blueprint::factory()->createOne(['meta' => ['accessible' => false]]);
    Blueprint::factory()->createOne(['meta' => ['accessible' => true]]);
    Blueprint::factory()->createOne(['meta' => null]);
    // meta present but without the accessible key: must be treated as accessible.
    // This is the seeded home blueprint's shape ({"sitemap":true,"listable":false})
    // that MySQL's JSON_CONTAINS path-null behaviour wrongly excluded, 404ing the
    // home page on every MySQL-backed (cloud) install while passing on SQLite.
    Blueprint::factory()->createOne(['meta' => ['sitemap' => true, 'listable' => false]]);

    $result = Blueprint::query()->accessible()->get();

    expect($result)->toHaveCount(3);
});

// --- HasPagePermissions ---

it('checks page type role restrictions without lazy loading', function (bool $checkRoleIdsFirst): void {
    $unrestricted = Blueprint::factory()->page()->create();
    $restricted = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $restricted->roleRestrictions()->create(['role_id' => $role->id]);
    $preventedLazyLoading = Model::preventsLazyLoading();
    Model::preventLazyLoading();

    try {
        // Hydrating multiple rows applies strict lazy loading to each existing model.
        $types = Blueprint::query()->whereKey([$unrestricted->id, $restricted->id])->get();

        expect($types)->toHaveCount(2);

        foreach ($types as $type) {
            $isRestricted = $type->id === $restricted->id;
            $roleIds = $isRestricted ? [$role->id] : [];

            expect($type->preventsLazyLoading)->toBeTrue()
                ->and($type->relationLoaded('roleRestrictions'))->toBeFalse();

            if ($checkRoleIdsFirst) {
                expect($type->getRestrictedRoleIds()->all())->toBe($roleIds);
            } else {
                expect($type->isRoleRestricted())->toBe($isRestricted);
            }

            expect($type->isRoleRestricted())->toBe($isRestricted)
                ->and($type->getRestrictedRoleIds()->all())->toBe($roleIds)
                ->and($type->relationLoaded('roleRestrictions'))->toBeTrue();

            $type->syncRoleRestrictions($isRestricted ? [] : [$role->id]);

            expect($type->relationLoaded('roleRestrictions'))->toBeFalse()
                ->and($type->isRoleRestricted())->toBe(! $isRestricted)
                ->and($type->getRestrictedRoleIds()->all())->toBe($isRestricted ? [] : [$role->id]);
        }
    } finally {
        Model::preventLazyLoading($preventedLazyLoading);
    }
})->with([
    'restriction status first' => [false],
    'restricted role IDs first' => [true],
]);

it('checks page type access without lazy loading', function (bool $siteScoped): void {
    $unrestricted = Blueprint::factory()->page()->create();
    $restricted = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $restricted->roleRestrictions()->create(['role_id' => $role->id]);
    $allowedUser = User::factory()->createOne()->assignRole($role);
    $deniedUser = User::factory()->createOne();
    $site = $siteScoped ? Site::factory()->createOne() : null;
    $preventedLazyLoading = Model::preventsLazyLoading();
    Model::preventLazyLoading();

    try {
        $types = Blueprint::query()->whereKey([$unrestricted->id, $restricted->id])->get();
        $users = User::query()->whereKey([$allowedUser->id, $deniedUser->id])->get();

        expect($types)->toHaveCount(2)
            ->and($users)->toHaveCount(2);

        foreach ($types as $type) {
            foreach ($users as $user) {
                $type->unsetRelation('roleRestrictions');
                $user->unsetRelation('roles');

                expect($type->preventsLazyLoading)->toBeTrue()
                    ->and($user->preventsLazyLoading)->toBeTrue()
                    ->and($type->relationLoaded('roleRestrictions'))->toBeFalse()
                    ->and($user->relationLoaded('roles'))->toBeFalse()
                    ->and($type->isAccessibleByUser($user, $site))
                    ->toBe($type->id === $unrestricted->id || $user->id === $allowedUser->id)
                    ->and($type->isRoleRestricted())->toBe($type->id === $restricted->id);
            }
        }
    } finally {
        Model::preventLazyLoading($preventedLazyLoading);
    }
})->with([
    'user roles' => [false],
    'site-scoped roles' => [true],
]);

it('is not role restricted when it has no role restrictions', function (): void {
    $type = Blueprint::factory()->page()->create();

    expect($type->isRoleRestricted())->toBeFalse();
});

it('is role restricted when it has role restrictions', function (): void {
    $type = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $type->roleRestrictions()->create(['role_id' => $role->id]);

    expect($type->refresh()->isRoleRestricted())->toBeTrue();
});

it('is accessible to any user when not role restricted', function (): void {
    $type = Blueprint::factory()->page()->create();
    $user = User::factory()->createOne();

    expect($type->isAccessibleByUser($user))->toBeTrue();
});

it('is accessible to user with a matching role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $user = User::factory()->createOne()->assignRole($role);

    expect($type->refresh()->isAccessibleByUser($user))->toBeTrue();
});

it('is not accessible to user without a matching role', function (): void {
    $type = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $type->roleRestrictions()->create(['role_id' => $role->id]);

    $user = User::factory()->createOne();

    expect($type->refresh()->isAccessibleByUser($user))->toBeFalse();
});

it('syncs role restrictions — adds new, removes old', function (): void {
    $type = Blueprint::factory()->page()->create();
    $roleA = Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $roleB = Role::create(['name' => 'author', 'guard_name' => 'web']);

    $type->syncRoleRestrictions([$roleA->id]);
    expect(PageRoleRestriction::query()->where('restrictable_id', $type->id)->pluck('role_id')->all())
        ->toBe([$roleA->id]);

    $type->syncRoleRestrictions([$roleB->id]);
    expect(PageRoleRestriction::query()->where('restrictable_id', $type->id)->pluck('role_id')->all())
        ->toBe([$roleB->id]);
});

it('syncs role restrictions — clearing all when given empty array', function (): void {
    $type = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $type->syncRoleRestrictions([$role->id]);
    $type->syncRoleRestrictions([]);

    expect(PageRoleRestriction::query()->where('restrictable_id', $type->id)->count())->toBe(0);
});
