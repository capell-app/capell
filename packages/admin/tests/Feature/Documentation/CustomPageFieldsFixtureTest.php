<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\View\Factory;
use Illuminate\Translation\Translator;
use Livewire\Livewire;
use Workbench\App\Support\CustomPageFieldsFixture;

uses(CreatesAdminUser::class)->group('admin');

beforeEach(function (): void {
    CustomPageFieldsFixture::registerSchemaExtenders();
    resolve(Translator::class)->addNamespace('capell-extension-cookbook', dirname(__DIR__, 5) . '/workbench/fixtures/extension-cookbook/lang');
    resolve(Translator::class)->addNamespace('custom-page-fields', dirname(__DIR__, 5) . '/workbench/lang/custom-page-fields');
    resolve(Factory::class)->addNamespace('custom-page-fields', dirname(__DIR__, 5) . '/workbench/resources/views/custom-page-fields');
    require dirname(__DIR__, 5) . '/workbench/routes/screenshot-fixtures.php';
    Page::factory()->withTranslations()->createOne();
    CustomPageFieldsFixture::initialize('http://127.0.0.1:8197');
});

it('composes independent fields and persists a changed client when the editor is reopened', function (): void {
    test()->actingAsAdmin();
    $page = CustomPageFieldsFixture::page('reopened');

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertFormFieldExists('meta.case_study.client_name')
        ->assertSee('Reference entry fields are package-owned; this note is presentation-only.')
        ->fillForm(['meta' => ['case_study' => ['client_name' => 'Field Notes Studio']]])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertFormSet(['meta.case_study.client_name' => 'Field Notes Studio']);
    expect(data_get($page->refresh()->meta, 'case_study.client_name'))->toBe('Field Notes Studio');
});

it('rejects an invalid client without changing persisted data', function (?string $name, string $rule): void {
    test()->actingAsAdmin();
    $page = CustomPageFieldsFixture::page('validation');

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['meta' => ['case_study' => ['client_name' => $name]]])
        ->call('save')
        ->assertHasFormErrors(['meta.case_study.client_name' => $rule]);

    expect(data_get($page->refresh()->meta, 'case_study.client_name'))->toBe('Field Notes');
})->with([[null, 'required'], [str_repeat('x', 121), 'max']]);

it('keeps custom fields off unrelated blueprint editors', function (): void {
    test()->actingAsAdmin();
    $page = Page::query()->where('name', 'not like', 'Case study custom fields%')->firstOrFail();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertFormFieldDoesNotExist('meta.case_study.client_name');
});

it('reopens saved records without resetting their values', function (): void {
    test()->actingAsAdmin();
    $page = CustomPageFieldsFixture::page('editor');
    $page->update(['meta' => ['case_study' => ['client_name' => 'Retained change']]]);

    $this->get('/screenshot-fixtures/custom-page-fields/editor')->assertRedirect(CustomPageFieldsFixture::editUrl('editor'));

    expect(data_get($page->refresh()->meta, 'case_study.client_name'))->toBe('Retained change');
});
