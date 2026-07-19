<?php

declare(strict_types=1);

use Capell\Admin\Actions\Tokens\IssueApiTokenAction;
use Capell\Core\Enums\ApiTokenAbility;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Exceptions\UnauthorizedPublicationMutationException;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Testing\ExtensionTestHarness;
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Vendor\FakeAiPackage\Actions\CreateDraftPageAction;
use Vendor\FakeAiPackage\Providers\FakeAiPackageServiceProvider;

beforeEach(function (): void {
    require_once __DIR__ . '/../fixtures/FakeAiPackage/src/Providers/FakeAiPackageServiceProvider.php';
    require_once __DIR__ . '/../fixtures/FakeAiPackage/src/Actions/CreateDraftPageAction.php';

    $this->language = Language::factory()->english()->create();
    Blueprint::factory()->page()->default()->create();
    Layout::factory()->default()->create();
    $this->site = Site::factory()->language($this->language)->create();

    app()->register(FakeAiPackageServiceProvider::class);
    ExtensionTestHarness::forPath(__DIR__ . '/../Fixtures/FakeAiPackage')
        ->assertManifestValid();
});

it('lets an external package create pages that are structurally drafts', function (): void {
    $page = CreateDraftPageAction::run($this->site, $this->language, name: 'AI Draft');

    expect($page->publishVisibilityState())->toBe(PublishVisibilityStateEnum::draft)
        ->and($page->isDraftSentinel())->toBeTrue();
});

it('blocks an external package from publishing via any eloquent model write', function (): void {
    $page = CreateDraftPageAction::run($this->site, $this->language, name: 'AI Draft');

    expect(fn () => $page->update(['visible_from' => CarbonImmutable::now()]))
        ->toThrow(UnauthorizedPublicationMutationException::class)
        ->and(fn () => $page->forceFill(['visible_from' => CarbonImmutable::now()])->save())
        ->toThrow(UnauthorizedPublicationMutationException::class)
        ->and(fn () => Page::query()->whereKey($page->id)->update(['visible_from' => CarbonImmutable::now()]))
        ->toThrow(UnauthorizedPublicationMutationException::class);
});

it('keeps draft-write tokens outside the publish ability', function (): void {
    $user = User::factory()->create();

    IssueApiTokenAction::run($user, 'fake-ai', [ApiTokenAbility::ContentDraftWrite->value]);

    expect($user->tokens()->sole()->can(ApiTokenAbility::ContentPublish->value))->toBeFalse();
});
