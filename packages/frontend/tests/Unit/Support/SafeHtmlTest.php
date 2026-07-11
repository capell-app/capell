<?php

declare(strict_types=1);

use Capell\Frontend\Support\SafeHtml;
use Illuminate\Contracts\Support\Htmlable;

it('can only be constructed through a sanitizer', function (): void {
    $safeHtml = SafeHtml::sanitize(
        '<p onclick="alert(1)">Hello</p>',
        static fn (string $html): string => str_replace(' onclick="alert(1)"', '', $html),
    );

    expect($safeHtml)->toBeInstanceOf(Htmlable::class)
        ->and($safeHtml->toHtml())->toBe('<p>Hello</p>')
        ->and($safeHtml->isEmpty())->toBeFalse()
        ->and((string) $safeHtml)->toBe('<p>Hello</p>')
        ->and((new ReflectionClass(SafeHtml::class))->getConstructor()?->isPrivate())->toBeTrue();
});
