<?php

declare(strict_types=1);

use Capell\Core\Support\Dataset\DatasetPublisher;
use Illuminate\Support\Facades\File;

it('publishes an executable dataset with the supplied values', function (): void {
    $original = database_path();
    $directory = sys_get_temp_dir() . '/capell-dataset-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory . '/sitemap');
    app()->useDatabasePath($directory);

    try {
        resolve(DatasetPublisher::class)->publish('sitemap', ['url' => "https://example.test/a'b", 'enabled' => true]);

        expect(require $directory . '/sitemap/sitemap.php')
            ->toBe(['url' => "https://example.test/a'b", 'enabled' => true]);
    } finally {
        app()->useDatabasePath($original);
        File::deleteDirectory($directory);
    }
});

it('handles permissions error gracefully', function (): void {
    File::shouldReceive('put')->andThrow(new RuntimeException('Permission denied'));
    $publisher = resolve(DatasetPublisher::class);

    expect(fn () => $publisher->publish('sitemap', ['a' => 1]))
        ->toThrow(RuntimeException::class, 'Failed to write dataset: Permission denied');
});
