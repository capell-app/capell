<?php

declare(strict_types=1);

use Capell\Core\Support\Slug\SlugGenerator;

it('generates a Laravel-compatible slug', function (): void {
    expect(SlugGenerator::slug('Crème brûlée & Coffee'))->toBe('creme-brulee-coffee');
});

it('emits a JS expression that slugifies state into the target path', function (): void {
    $js = SlugGenerator::slugifyState("\$state ?? ''", 'meta.slug');

    expect($js)
        ->toContain("(\$state ?? '')")
        ->toContain(".normalize('NFD')")
        ->toContain('.toLowerCase()')
        ->toContain('/[^a-z0-9]+/g')
        ->toContain("\$set('meta.slug', slug);");
});
