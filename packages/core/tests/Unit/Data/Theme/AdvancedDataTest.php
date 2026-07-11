<?php

declare(strict_types=1);

use Capell\Core\Data\Theme\AdvancedData;

it('hydrates advanced settings from flat meta', function (): void {
    $data = AdvancedData::from([
        'rounded_images' => true,
        'main_class' => 'prose',
        'custom_css' => '.foo { color: red; }',
        'meta_tags' => '<meta name="robots" content="index">',
    ]);

    expect($data->roundedImages)->toBeTrue()
        ->and($data->mainClass)->toBe('prose')
        ->and($data->customCss)->toBe('.foo { color: red; }')
        ->and($data->metaTags)->toBe('<meta name="robots" content="index">');
});

it('defaults to safe empty values', function (): void {
    $data = AdvancedData::from([]);

    expect($data->roundedImages)->toBeFalse()
        ->and($data->mainClass)->toBeNull()
        ->and($data->customCss)->toBeNull()
        ->and($data->metaTags)->toBeNull();
});
