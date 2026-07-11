<?php

declare(strict_types=1);

use Capell\Frontend\Facades\Frontend;

it('stores and retrieves global frontend data via the Frontend facade', function (): void {
    expect(Frontend::getFrontendData())->toBeArray()->toBeEmpty();

    Frontend::setFrontendData('has_primary_heading', true);
    Frontend::setFrontendData('layout_variant', 'compact');

    expect(Frontend::getFrontendData('has_primary_heading'))->toBeTrue();
    expect(Frontend::getFrontendData('layout_variant'))->toBe('compact');

    $all = Frontend::getFrontendData();
    expect($all)->toHaveKeys(['has_primary_heading', 'layout_variant']);
});

it('returns null for undefined keys and supports overwrite semantics', function (): void {
    expect(Frontend::getFrontendData('undefined_key'))->toBeNull();

    Frontend::setFrontendData('theme_name', 'alpha');
    expect(Frontend::getFrontendData('theme_name'))->toBe('alpha');

    Frontend::setFrontendData('theme_name', 'beta');
    expect(Frontend::getFrontendData('theme_name'))->toBe('beta');
});
