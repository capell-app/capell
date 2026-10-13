<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;

enum BlueprintRenderingMetadataString: string
{
    case Component = '  rendered.component  ';
    case Blank = '   ';
}

enum BlueprintRenderingMetadataInteger: int
{
    case Component = 42;
    case Zero = 0;
}

enum BlueprintRenderingMetadataUnit
{
    case Component;
}

it('persists rendering metadata values without leaking them back into metadata', function (mixed $value, ?string $expected): void {
    $record = Blueprint::factory()->create();
    $record->meta = [
        'component' => $value,
        'component_item' => $value,
        'view_file' => $value,
        'livewire' => true,
        'retained' => 'saved metadata',
    ];
    $record->save();

    $reloaded = Blueprint::query()->findOrFail($record->getKey());

    expect($reloaded->component)->toBe($expected)
        ->and($reloaded->component_item)->toBe($expected)
        ->and($reloaded->view_file)->toBe($expected)
        ->and($reloaded->is_livewire)->toBeTrue()
        ->and($reloaded->meta)->toBe(['retained' => 'saved metadata']);

})->with([
    'string backed enum' => [BlueprintRenderingMetadataString::Component, 'rendered.component'],
    'blank backed enum' => [BlueprintRenderingMetadataString::Blank, null],
    'integer backed enum' => [BlueprintRenderingMetadataInteger::Component, '42'],
    'zero backed enum' => [BlueprintRenderingMetadataInteger::Zero, '0'],
    'scalar string' => ['  rendered.component  ', 'rendered.component'],
    'scalar integer' => [42, '42'],
    'scalar zero' => [0, '0'],
    'scalar float' => [12.5, '12.5'],
    'scalar true' => [true, '1'],
    'scalar false' => [false, null],
    'null' => [null, null],
    'blank string' => ['   ', null],
    'unit enum' => [BlueprintRenderingMetadataUnit::Component, null],
    'array' => [['component'], null],
    'arbitrary object' => [static fn (): stdClass => new stdClass, null],
    'stringable object' => [static fn (): Stringable => new class implements Stringable
    {
        #[Override]
        public function __toString(): string
        {
            return 'rendered.component';
        }
    }, null],
]);

it('extracts distinct rendering fields and leaves no empty rendering metadata behind', function (): void {
    $record = Blueprint::factory()->create(['meta' => [
        'component' => BlueprintRenderingMetadataString::Component,
        'component_item' => BlueprintRenderingMetadataInteger::Component,
        'view_file' => '  cards/article.blade.php  ',
        'livewire' => false,
    ]]);
    $reloaded = Blueprint::query()->whereKey($record->getKey())->sole();

    expect($reloaded->component)->toBe('rendered.component')
        ->and($reloaded->component_item)->toBe('42')
        ->and($reloaded->view_file)->toBe('cards/article.blade.php')
        ->and($reloaded->is_livewire)->toBeFalse()
        ->and($reloaded->meta)->toBeNull();
});
