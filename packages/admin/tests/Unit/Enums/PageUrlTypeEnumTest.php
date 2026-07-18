<?php

declare(strict_types=1);

use Capell\Admin\Enums\PageUrlTypeEnum;
use Capell\Core\Enums\UrlTypeEnum;

it('adds the default sentinel to the core URL type values and provides labelled options', function (): void {
    expect(array_slice(array_column(PageUrlTypeEnum::cases(), 'value'), 1))
        ->toBe(array_column(UrlTypeEnum::cases(), 'value'))
        ->and(PageUrlTypeEnum::options())->toBe([
            'default' => 'Default',
            'alias' => 'Alias',
            'redirect' => 'Redirect',
        ]);
});
