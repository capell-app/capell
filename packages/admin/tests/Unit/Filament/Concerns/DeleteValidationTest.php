<?php

declare(strict_types=1);

use Capell\Admin\Actions\ContentGraph\ValidateContentDeleteImpactAction;
use Capell\Admin\Data\ContentGraph\DeleteImpactValidationData;
use Capell\Admin\Tests\Unit\Filament\Concerns\Fixtures\BlueprintDeleteValidationHarness;
use Capell\Admin\Tests\Unit\Filament\Concerns\Fixtures\PageDeleteValidationHarness;
use Capell\Core\Data\ContentGraph\ContentImpactPreviewData;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Translation\Translator;

it('blocks page deletion for non-deletable page types and canonical dependants', function (): void {
    $protectedType = Blueprint::factory()->page()->create([
        'name' => 'Protected Type',
        'admin' => ['deletable' => false],
    ]);
    $protectedPage = Page::factory()->type($protectedType)->create(['name' => 'Protected Page']);

    $canonicalType = Blueprint::factory()->page()->create(['name' => 'Canonical Type']);
    $canonicalPage = Page::factory()->type($canonicalType)->create(['name' => 'Canonical Page']);
    Page::factory()->type($canonicalType)->canonicalPage($canonicalPage)->create();

    $validator = new PageDeleteValidationHarness;

    expect($validator->validateDelete($protectedPage))->toBeFalse()
        ->and($validator->validateDelete($canonicalPage))->toBeFalse();
});

it('uses content graph delete impact checks for otherwise deletable pages', function (): void {
    $type = Blueprint::factory()->page()->create(['name' => 'Article']);
    $page = Page::factory()->type($type)->create(['name' => 'Article Page']);
    $preview = new ContentImpactPreviewData(
        blocked: true,
        strongCount: 2,
        weakCount: 0,
        informationalCount: 0,
        groups: [],
    );

    bindFakeAction(ValidateContentDeleteImpactAction::class, new DeleteImpactValidationData(
        allowed: false,
        blockingCount: 2,
        warningCount: 0,
        preview: $preview,
    ));

    expect((new PageDeleteValidationHarness)->validateDelete($page))->toBeFalse();

    bindFakeAction(ValidateContentDeleteImpactAction::class, new DeleteImpactValidationData(
        allowed: true,
        blockingCount: 0,
        warningCount: 0,
        preview: new ContentImpactPreviewData(false, 0, 0, 0, []),
    ));

    expect((new PageDeleteValidationHarness)->validateDelete($page))->toBeTrue();
});

it('blocks blueprint deletion when records still use the blueprint', function (): void {
    $type = Blueprint::factory()->page()->create(['name' => 'Landing Page Type']);
    Page::factory()->type($type)->create();

    $validator = new BlueprintDeleteValidationHarness;

    expect($validator->validateDelete($type))->toBeFalse()
        ->and($validator->errors)->toHaveKey('data.type');
});

it('keeps blueprint delete protection global while exposing only accessible page counts', function (): void {
    $assigned = Site::factory()->create();
    $foreign = Site::factory()->create();
    $type = Blueprint::factory()->page()->create();
    Page::factory()->type($type)->site($assigned)->create();
    Page::factory()->type($type)->site($foreign)->create();
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([(int) $assigned->getKey()]);

    test()->actingAs($actor);
    resolve(Translator::class)->addLines(['message.page_type_not_deletable_info' => 'Accessible pages: :count'], 'en', 'capell-admin');

    expect((new BlueprintDeleteValidationHarness)->validateDelete($type))->toBeFalse();
    $notifications = session('filament.notifications');
    $notification = Notification::fromArray(end($notifications));
    expect($notification->getBody())->toBe('Accessible pages: 1');
});
