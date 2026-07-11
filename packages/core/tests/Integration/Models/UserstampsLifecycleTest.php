<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User as FixtureUser;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;

it('records creator editor and destroyer users through the page lifecycle', function (): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, User::class));
    assert(is_a($userModel, FixtureUser::class, true));

    $creator = $userModel::factory()->createOne();
    $editor = $userModel::factory()->createOne();
    $destroyer = $userModel::factory()->createOne();
    $language = Language::factory()->english()->create();
    $site = Site::factory()->withTranslations($language)->create([
        'language_id' => $language->getKey(),
    ]);

    Auth::login($creator);

    $page = Page::factory()->site($site)->createOne([
        'created_by' => null,
        'updated_by' => null,
        'deleted_by' => null,
    ]);

    expect($page->created_by)->toBe($creator->getKey())
        ->and($page->updated_by)->toBe($creator->getKey())
        ->and($page->created_at)->not->toBeNull()
        ->and($page->updated_at)->not->toBeNull()
        ->and($page->creatorUser())->toBeNull();

    Auth::login($editor);
    $page->update(['name' => 'Edited']);

    Auth::login($destroyer);
    $page->delete();
    $page->load(['creator', 'editor', 'destroyer']);

    expect($page->creatorUser()?->is($creator))->toBeTrue()
        ->and($page->editorUser()?->is($editor))->toBeTrue()
        ->and($page->destroyerUser()?->is($destroyer))->toBeTrue()
        ->and($page->deleted_at)->not->toBeNull();

    $page->restore();
    $page->refresh();

    expect($page->deleted_by)->toBeNull()
        ->and($page->deletedAt())->toBeNull();
});

it('can temporarily disable userstamping on model writes', function (): void {
    $userModel = config('auth.providers.users.model');
    assert(is_string($userModel) && is_subclass_of($userModel, User::class));
    assert(is_a($userModel, FixtureUser::class, true));

    $editor = $userModel::factory()->createOne();
    $language = Language::factory()->english()->create();
    $site = Site::factory()->withTranslations($language)->create([
        'language_id' => $language->getKey(),
    ]);

    Auth::login($editor);

    $page = Page::factory()->site($site)->make([
        'created_by' => null,
        'updated_by' => null,
    ]);
    $page->stopUserstamping();
    $page->save();

    expect($page->isUserstamping())->toBeFalse()
        ->and($page->created_by)->toBeNull()
        ->and($page->updated_by)->toBeNull();

    $page->startUserstamping();
    $page->update(['name' => 'Restamped']);

    expect($page->isUserstamping())->toBeTrue()
        ->and($page->updated_by)->toBe($editor->getKey());
});
