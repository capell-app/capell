<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BuildParentLanguageEligibilityAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)->group('page');

it('rechecks parent translations and site access against the current child languages', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $parent = Page::factory()->site($site)->withTranslations($english)->createOne();
    $input = [['language_id' => $english->id], ['language_id' => $french->id]];

    $before = BuildParentLanguageEligibilityAction::run($parent->id, $site->id, $input);
    expect($before->accessible)->toBeTrue()
        ->and($before->missingLanguages)->toBe([$french->name])
        ->and($before->editUrl)->not->toBeNull();

    $parent->translations()->create(['language_id' => $french->id, 'title' => 'French parent']);
    expect(BuildParentLanguageEligibilityAction::run($parent->id, $site->id, $input)->missingLanguages)->toBe([]);
    $parent->translations()->where('language_id', $french->id)->delete();
    expect(BuildParentLanguageEligibilityAction::run($parent->id, $site->id, $input)->missingLanguages)->toBe([$french->name])
        ->and(BuildParentLanguageEligibilityAction::run($parent->id, $site->id, [['language_id' => $english->id]])->missingLanguages)->toBe([]);

    $foreign = Site::factory()->createOne();
    $rejected = BuildParentLanguageEligibilityAction::run($parent->id, $foreign->id, $input);
    expect($rejected->accessible)->toBeFalse()->and($rejected->missingLanguages)->toBe([])->and($rejected->editUrl)->toBeNull();

    test()->actingAs(test()->createUser());
    $hidden = BuildParentLanguageEligibilityAction::run($parent->id, $site->id, $input);
    expect($hidden->accessible)->toBeFalse()->and($hidden->missingLanguages)->toBe([])->and($hidden->editUrl)->toBeNull();
});

it('explains a missing language without offering a parent edit handoff to a read only editor', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $parent = Page::factory()->site($site)->withTranslations($english)->createOne();
    Permission::findOrCreate('View:Page');
    $actor = test()->createUserWithPermission('View:Page');
    $actor->assignedSiteIds = collect([$site->id]);

    test()->actingAs($actor);

    $result = BuildParentLanguageEligibilityAction::run($parent->id, $site->id, [['language_id' => $french->id]]);
    expect($result->accessible)->toBeTrue()
        ->and($result->missingLanguages)->toBe([$french->name])
        ->and($result->editUrl)->toBeNull();
});

it('rejects forged child language identities without revealing foreign language names', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $foreign = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations($english)->createOne();
    $parent = Page::factory()->site($site)->withTranslations($english)->createOne();

    $result = BuildParentLanguageEligibilityAction::run($parent->id, $site->id, [['language_id' => $foreign->id]]);
    expect($result->compatible)->toBeFalse()
        ->and($result->missingLanguages)->toBe([]);
});
