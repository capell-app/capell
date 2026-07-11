<?php

declare(strict_types=1);

use Capell\Admin\Actions\GetMaxUploadSizeInBytes;
use Illuminate\Support\Facades\Config;

it('returns configured max upload size', function (): void {
    Config::set('files.max_upload_mb', 5);

    $bytes = GetMaxUploadSizeInBytes::run();

    expect($bytes)->toBe(5 * 1024 * 1024);
});

it('falls back to default when missing', function (): void {
    Config::set('files.max_upload_mb');

    $bytes = GetMaxUploadSizeInBytes::run();

    expect($bytes)->toBeInt()->toBeGreaterThan(0);
});
