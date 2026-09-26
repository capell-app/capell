<?php

declare(strict_types=1);

use Capell\Admin\Enums\EditorEnum;
use Capell\Admin\Filament\Components\Forms\ContentEditor;
use Capell\Admin\Filament\Components\Forms\Editor\RichEditor;
use Capell\Admin\Filament\Components\Forms\Editor\TinyEditor;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Schemas\Schema;

function richEditorStateTestComponent(EditorEnum $editor): RichEditor|TinyEditor
{
    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([ContentEditor::make('content', editor: $editor)]);
    $component = $schema->getComponents()[0] ?? null;

    throw_if(! $component instanceof RichEditor && ! $component instanceof TinyEditor, RuntimeException::class, 'The content editor component was not registered.');

    return $component;
}

it('does not serialize the default Filament text color palette for every editor instance', function (): void {
    expect(RichEditor::make('content')->getTextColorsForJs())->toBe([]);
});

it('preserves media-only html when editor state is dehydrated', function (EditorEnum $editor, string $html): void {
    $component = richEditorStateTestComponent($editor);
    $dehydrated = $component->getStateToDehydrate($html);

    expect($dehydrated)->toHaveCount(1)
        ->and(array_values($dehydrated))->toBe([$html]);
})->with([
    'rich editor image' => [EditorEnum::RichEditor, '<img src="/photo.jpg" alt="Photo">'],
    'TinyMCE image' => [EditorEnum::TinyMCE, '<img src="/photo.jpg" alt="Photo">'],
    'TinyMCE video' => [EditorEnum::TinyMCE, '<video controls src="/film.mp4"></video>'],
]);

it('still normalises empty editor placeholder markup to null', function (EditorEnum $editor): void {
    $component = richEditorStateTestComponent($editor);

    expect(array_values($component->getStateToDehydrate('<p><br></p>')))->toBe([null]);
})->with(EditorEnum::cases());
