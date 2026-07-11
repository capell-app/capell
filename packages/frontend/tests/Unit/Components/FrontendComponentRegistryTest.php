<?php

declare(strict_types=1);

use Capell\Frontend\Support\Components\FrontendComponentRegistry;

it('resolves registered component keys and aliases to their blade component', function (): void {
    $registry = new FrontendComponentRegistry;

    $registry->register(
        key: 'section.block',
        component: 'capell-content::section.block',
        aliases: ['capell-content-sections::section.block'],
        props: ['asset', 'title'],
    );

    expect($registry->resolve('section.block'))->toBe('capell-content::section.block')
        ->and($registry->resolve('capell-content-sections::section.block'))->toBe('capell-content::section.block')
        ->and($registry->resolve('capell-content::section.block'))->toBe('capell-content::section.block')
        ->and($registry->get('section.block')->props)->toBe(['asset', 'title']);
});

it('passes through unknown components for backwards compatibility', function (): void {
    $registry = new FrontendComponentRegistry;

    expect($registry->resolve('app::custom.card'))->toBe('app::custom.card');
});
