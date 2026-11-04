<?php

declare(strict_types=1);

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\Install\ResolveInstallFirstPageStatusAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\Schema;

it('reports a missing first page while the pages table does not exist', function (): void {
    Schema::dropIfExists(TableName::resolve('pages'));

    expect(ResolveInstallFirstPageStatusAction::run())->toBe('missing');
});

it('reports a missing first page when no page has been created', function (): void {
    Page::query()->delete();

    expect(ResolveInstallFirstPageStatusAction::run())->toBe('missing');
});

it('reports an editable first page when Admin can open the page', function (): void {
    $page = Page::factory()->create();
    GetEditPageResourceUrlAction::mock()->shouldReceive('handle')->once()->andReturn('https://example.test/admin/pages/' . $page->getKey() . '/edit');

    expect(ResolveInstallFirstPageStatusAction::run())->toBe('editable');
});

it('reports an unverified first page when the page exists but has no edit URL', function (): void {
    Page::factory()->create();
    GetEditPageResourceUrlAction::mock()->shouldReceive('handle')->once()->andReturn(null);

    expect(ResolveInstallFirstPageStatusAction::run())->toBe('present_unverified');
});
