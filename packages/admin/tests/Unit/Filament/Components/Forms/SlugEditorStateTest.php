<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\SlugInput;
use Capell\Admin\Filament\Components\Forms\TitleWithSlugInput;
use Capell\Admin\Tests\Fixtures\Autoload\SlugEditorClientForTest;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Schemas\Schema;
use Illuminate\Support\ViewErrorBag;
use Sinnbeck\DomAssertions\Asserts\AssertElement;

beforeEach(function (): void {
    view()->share('errors', new ViewErrorBag);
});

it('locks only user supplied nonblank URL values before submit', function (string $slug): void {
    $component = mountedSlugEditorForTest();
    $client = SlugEditorClientForTest::update($component->toHtml(), 'data.meta.slug', $slug, 'submit');

    expect($client['slug'])->toBe($slug)
        ->and($client['manual'])->toBeTrue()
        ->and($client['editing'])->toBeTrue();
})->with(['deliberate-url', '/', 'under_score']);

it('releases automatic URL generation when the user clears the input', function (string $slug): void {
    $client = SlugEditorClientForTest::update(mountedSlugEditorForTest()->toHtml(), 'data.meta.slug', $slug, 'submit', manual: true);

    expect($client['manual'])->toBeFalse();
})->with(['', '   ']);

it('does not lock an automatic URL update', function (): void {
    $client = SlugEditorClientForTest::update(mountedSlugEditorForTest()->toHtml(), 'data.meta.slug', 'automatic-title-url', 'automatic');

    expect($client['slug'])->toBe('automatic-title-url')
        ->and($client['manual'])->toBeFalse();
});

it('retains the existing cancel behaviour and the chosen URL lock', function (): void {
    $client = SlugEditorClientForTest::update(mountedSlugEditorForTest()->toHtml(), 'data.meta.slug', 'chosen-before-cancel', 'cancel');

    expect($client['slug'])->toBe('chosen-before-cancel')
        ->and($client['manual'])->toBeTrue()
        ->and($client['editing'])->toBeFalse();
});

it('resets an edited URL to its original value without unlocking it', function (): void {
    $client = SlugEditorClientForTest::update(mountedSlugEditorForTest('original-url')->toHtml(), 'data.meta.slug', 'changed-url', 'reset', manual: true);

    expect($client['slug'])->toBe('original-url')
        ->and($client['manual'])->toBeTrue()
        ->and($client['modified'])->toBeFalse();
});

it('keeps standalone slug fields independent of a title auto update flag', function (): void {
    $component = SlugInput::make('slug')
        ->slugInputContext('create')
        ->slugInputBaseUrl('https://capell.test')
        ->slugInputRecordSlug(fn (): ?string => null)
        ->slugInputLabelPrefix(null);
    Schema::make(Livewire::make()->data(['slug' => '']))->statePath('data')->components([$component])->getComponents();
    $client = SlugEditorClientForTest::update($component->toHtml(), 'data.slug', 'standalone-choice', 'ok');

    expect($component->getAutoUpdateDisabledStatePath())->toBeNull()
        ->and($client['slug'])->toBe('standalone-choice')
        ->and($client['manual'])->toBeFalse();
});

it('binds the manual choice to native input and prevents Enter from submitting the surrounding form', function (): void {
    new AssertElement(mountedSlugEditorForTest()->toHtml())->find('input', function (AssertElement $input): void {
        $input->has('wire:model', 'data.meta.slug')
            ->has('x-on:input', 'updateAutoUpdateDisabled($event.target.value)')
            ->has('x-on:keydown.enter.prevent', 'submitModification()');
    });
});

it('renders a readonly URL without manual input controls', function (): void {
    new AssertElement(mountedSlugEditorForTest('/', readonly: true)->toHtml())->doesntContain('input');
});

function mountedSlugEditorForTest(string $slug = '', bool $readonly = false): SlugInput
{
    $group = TitleWithSlugInput::make(slugStatePath: 'meta.slug', slugIsReadonly: $readonly);
    Schema::make(Livewire::make()->data(['title' => '', 'meta' => ['slug' => $slug], 'slug_auto_update_disabled' => false]))
        ->operation('create')->statePath('data')->components([$group])->getComponents();
    $component = expectPresent($group->getChildSchema())->getComponents()[1];
    assert($component instanceof SlugInput);

    return $component;
}
