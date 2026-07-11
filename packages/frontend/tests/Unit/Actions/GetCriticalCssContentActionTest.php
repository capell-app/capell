<?php

declare(strict_types=1);

use Capell\Frontend\Actions\GetCriticalCssContentAction;
use Illuminate\Support\Facades\Vite;

it('reads critical css through the vite local content api', function (): void {
    Vite::shouldReceive('content')
        ->once()
        ->with('resources/css/critical.css', 'build')
        ->andReturn('body { color: red; }');

    expect(GetCriticalCssContentAction::run('resources/css/critical.css', 'build'))->toBe('body { color: red; }');
});

it('removes validator hostile css from inline critical css without dropping the critical path', function (): void {
    Vite::shouldReceive('content')
        ->once()
        ->with('resources/css/critical.css', 'build')
        ->andReturn(implode('', [
            '@supports (((-webkit-hyphens:none)) and (not (margin-trim: inline))) {',
            '*{--tw-border-style:solid}',
            '}',
            '.\\@container, .\\[container-type\\:inline-size\\] { container-type: inline-size; }',
            '.hero{display:grid}',
        ]));

    expect(GetCriticalCssContentAction::run('resources/css/critical.css', 'build'))
        ->toContain('@supports (((-webkit-hyphens:none)) and (not (color:rgb(from red r g b))))')
        ->toContain('*{--tw-border-style:solid}')
        ->toContain('.hero{display:grid}')
        ->not->toContain('margin-trim')
        ->not->toContain('container-type');
});
