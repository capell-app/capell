<?php

declare(strict_types=1);

use Capell\Core\Support\Subscriber\Contracts\Subscriber;
use Capell\Core\Support\Subscriber\Contracts\ValidatingSubscriber;

it('declares Subscriber contract with handle method', function (): void {
    $reflection = new ReflectionClass(Subscriber::class);
    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('handle'))->toBeTrue();
});

it('declares ValidatingSubscriber as subtype of Subscriber with validate method', function (): void {
    expect(is_subclass_of(ValidatingSubscriber::class, Subscriber::class))->toBeTrue();
    $reflection = new ReflectionClass(ValidatingSubscriber::class);
    expect($reflection->hasMethod('validate'))->toBeTrue();
});
