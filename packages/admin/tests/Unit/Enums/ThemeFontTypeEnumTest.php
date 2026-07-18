<?php

declare(strict_types=1);

use Capell\Admin\Enums\ThemeFontTypeEnum;
use Capell\Core\Enums\FontTypeEnum;

it('mirrors the core font type values and provides labelled options', function (): void {
    expect(array_column(ThemeFontTypeEnum::cases(), 'value'))
        ->toBe(array_column(FontTypeEnum::cases(), 'value'))
        ->and(ThemeFontTypeEnum::options())->toBe([
            'url' => 'Hosted URL',
            'local' => 'Uploaded file',
        ]);
});
