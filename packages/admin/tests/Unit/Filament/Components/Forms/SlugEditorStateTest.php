<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\SlugInput;
use Capell\Admin\Filament\Components\Forms\TitleWithSlugInput;
use Capell\Admin\Tests\Fixtures\Autoload\SlugEditorClientForTest;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Schemas\Schema;
use Illuminate\Support\MessageBag;
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

it('discards cancelled typing and restores the opening form value and manual lock', function (string $interaction, bool $manual): void {
    $client = SlugEditorClientForTest::update(mountedSlugEditorForTest('original-url')->toHtml(), 'data.meta.slug', 'discard-me', $interaction, $manual);

    expect($client['slug'])->toBe('original-url')
        ->and($client['manual'])->toBe($manual)
        ->and($client['modified'])->toBeFalse()
        ->and($client['editing'])->toBeFalse();
})->with(['cancel', 'escape'])->with([false, true]);

it('preserves an earlier applied change when the next edit is cancelled', function (string $interaction): void {
    $client = SlugEditorClientForTest::sequence(mountedSlugEditorForTest('original-url')->toHtml(), 'data.meta.slug', [
        ['slug' => 'Change A', 'interaction' => 'ok'],
        ['slug' => 'change-b', 'interaction' => $interaction],
    ]);

    expect($client['slug'])->toBe('change-a')
        ->and($client['manual'])->toBeTrue()
        ->and($client['modified'])->toBeTrue()
        ->and($client['editing'])->toBeFalse();
})->with(['cancel', 'escape']);

it('commits the normalised draft through Apply or Enter', function (string $interaction): void {
    $client = SlugEditorClientForTest::update(mountedSlugEditorForTest()->toHtml(), 'data.meta.slug', '  Crème Brûlée!  ', $interaction);

    expect($client['slug'])->toBe('creme-brulee')
        ->and($client['manual'])->toBeTrue()
        ->and($client['editing'])->toBeFalse();
})->with(['ok', 'enter']);

it('keeps the saved visit address truthful while an applied permalink is pending', function (): void {
    $component = mountedSlugEditorForTest('original-url')
        ->slugInputRecordSlug(fn (): string => 'original-url')
        ->slugInputVisitLinkRoute(fn (): string => 'https://capell.test/original-url');
    $client = SlugEditorClientForTest::update($component->toHtml(), 'data.meta.slug', 'pending-url', 'ok');

    expect($client['slug'])->toBe('pending-url')
        ->and($client['modified'])->toBeTrue()
        ->and($client['visitUrl'])->toBe('https://capell.test/original-url')
        ->and($client['visitText'])->toBe('https://capell.test/original-url');
});

it('applies a reset to the original URL without unlocking it', function (): void {
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

it('names the permalink controls and returns focus to the edit button after Apply or Cancel', function (): void {
    $html = mountedSlugEditorForTest()->toHtml();

    new AssertElement($html)->find('input', function (AssertElement $input): void {
        $input->has('aria-label', __('capell-admin::generic.permalink_input_label'));
    });
    new AssertElement($html)->find('button.page-title-with-slug-edit-slug', function (AssertElement $button): void {
        $button->has('x-ref', 'editButton')
            ->has('aria-label', __('capell-admin::generic.permalink_action_edit_label'));
    });

    expect($html)->toContain('returnFocusToEditButton()')
        ->and(substr_count($html, 'this.returnFocusToEditButton()'))->toBe(2)
        ->and($html)->toContain('this.$refs.editButton?.focus()');
});

it('ties a validation error to the permalink input so it is announced', function (): void {
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag(['data.meta.slug' => ['Use lower-case letters, numbers and hyphens only.']]));

    view()->share('errors', $errors);

    $html = mountedSlugEditorForTest()->toHtml();

    new AssertElement($html)->find('input', function (AssertElement $input): void {
        $input->has('aria-invalid', 'true');
    });
    expect($html)->toMatch('/aria-describedby="([^"]+)-error".*<p\s+id="\1-error"\s+role="alert"/s');
    new AssertElement($html)->find('p[role="alert"]', function (AssertElement $error): void {
        $error->containsText('Use lower-case letters, numbers and hyphens only.');
    });
});

it('explains an address clash and a badly formatted address in plain words', function (): void {
    $messages = mountedSlugEditorForTest()->getValidationMessages();

    expect($messages['unique'])->toBe(__('capell-admin::form.slug_taken'))
        ->and($messages['regex'])->toBe(__('capell-admin::form.slug_format'))
        ->and($messages['unique'])->toContain('under the same parent')
        ->and($messages['regex'])->toContain('lower-case letters, numbers and hyphens');
});

it('shows the locked address helper only when the address cannot be edited', function (): void {
    expect(mountedSlugEditorForTest(readonly: true)->getHelperText())->toBe(__('capell-admin::form.slug_lock_helper'))
        ->and(mountedSlugEditorForTest()->getHelperText())->toBeNull();
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
