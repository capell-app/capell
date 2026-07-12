<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('requires populated page history and reversible recovery proof', function (): void {
    $root = dirname(__DIR__, 2);
    $manifest = json_decode(File::get($root . '/docs/screenshots.json'), true, flags: JSON_THROW_ON_ERROR);
    $entries = collect($manifest['entries'])->keyBy('id');

    foreach (['page-history-timeline', 'page-history-rollback-preview'] as $id) {
        $entry = $entries->get($id);

        expect($entry)->not->toBeNull()
            ->and($entry['required'])->toBeTrue()
            ->and($root . '/../../' . $entry['output'])->toBeFile()
            ->and(filesize($root . '/../../' . $entry['output']))->toBeGreaterThan(10_000)
            ->and(strtolower((string) $entry['notes']))->not->toContain('optional', 'empty state', 'fixture');
    }

    $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);

    expect($encoded)
        ->not->toMatch('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i')
        ->not->toContain('http://localhost', 'https://localhost', '__(', '{{', 'translation missing');
});
