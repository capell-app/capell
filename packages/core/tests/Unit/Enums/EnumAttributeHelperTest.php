<?php

declare(strict_types=1);

use Capell\Core\Enums\Attribute\Component;
use Capell\Core\Tests\Support\Fixtures\Autoload\AttributeHelperTestEnum;

it('reads component attributes from enum cases', function (): void {
    expect(AttributeHelperTestEnum::Hero->getCaseAttribute(Component::class))
        ->toEqual(new Component('hero-component'))
        ->and(AttributeHelperTestEnum::getAllCaseAttributes(Component::class))
        ->toEqual([
            'hero' => new Component('hero-component'),
            'plain' => null,
        ]);
});
