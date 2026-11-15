<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Dom\HTMLDocument;

uses(CreatesAdminUser::class)->group('admin');

it('renders no help button, panel or slide-over on a default install', function (callable $url): void {
    test()->actingAsAdmin();

    $response = $this->get($url());
    $response->assertOk();

    $html = (string) $response->getContent();
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    expect($document->querySelectorAll('[data-admin-help], [data-admin-help-trigger], [data-admin-help-answers], [data-admin-help-glossary]')->length)->toBe(0)
        ->and($html)->not->toContain('Help for this screen')
        ->and($html)->not->toContain('data-admin-help');
})->with([
    'dashboard' => [fn (): string => route('filament.admin.pages.capell-dashboard')],
    'pages' => [fn (): string => route('filament.admin.resources.pages.index')],
    'page editor' => [fn (): string => PageResource::getUrl('edit', ['record' => Page::factory()->withTranslations()->createOne()])],
    'media' => [fn (): string => route('filament.admin.resources.media.index')],
    'users' => [fn (): string => route('filament.admin.resources.users.index')],
    'settings' => [fn (): string => route('filament.admin.pages.settings')],
]);
