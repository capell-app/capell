<?php

declare(strict_types=1);

use Capell\Admin\Support\Enums\EnumPresentationRegistry;
use Capell\Core\Enums\ImageSourceType;
use Capell\Core\Enums\RedirectStatusCodeEnum;

it('presents Core enum labels and options through an Admin contributor', function (): void {
    $registry = resolve(EnumPresentationRegistry::class);

    expect($registry->label(ImageSourceType::Media))->toBe(__('capell::media.image_source.media'))
        ->and($registry->options(RedirectStatusCodeEnum::class))->toMatchArray([
            301 => __('capell-core::generic.redirect_301'),
            302 => __('capell-core::generic.redirect_302'),
        ]);
});
