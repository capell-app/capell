<?php

declare(strict_types=1);

use Capell\Core\Support\Dataset\DatasetPublisher;
use Illuminate\Support\Facades\File;

it('publishes dataset to filesystem', function (): void {
    File::spy();

    $publisher = resolve(DatasetPublisher::class);
    $publisher->publish('sitemap', ['a' => 1]);

    $expectedPath = database_path('sitemap/sitemap.php');
    File::shouldHaveReceived('put')
        ->with($expectedPath, Mockery::type('string'));
});

it('handles permissions error gracefully', function (): void {
    File::shouldReceive('put')->andThrow(new RuntimeException('Permission denied'));
    $publisher = resolve(DatasetPublisher::class);

    expect(fn () => $publisher->publish('sitemap', ['a' => 1]))
        ->toThrow(RuntimeException::class, 'Failed to write dataset: Permission denied');
});
