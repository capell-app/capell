<?php

declare(strict_types=1);

use Capell\Admin\Tests\Support\SiteAccessQueryGuard;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

it('requires site sensitive Filament and report queries to start at SiteAccess', function (): void {
    $root = dirname(__DIR__, 4);
    $violations = [];
    foreach (['packages/admin/src/Filament', 'packages/admin/src/Actions/Reports', 'packages/marketplace/src/Filament'] as $directory) {
        foreach (File::allFiles($root . '/' . $directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach (SiteAccessQueryGuard::violations($file->getContents()) as $violation) {
                $violations[] = $file->getRelativePathname() . ': ' . $violation;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('detects an unscoped query even with a model alias or an unused access import', function (): void {
    $source = '<?php use Capell\\Core\\Models\\Page as Content; use Capell\\Core\\Support\\Permissions\\SiteAccess; Content::query()->count();';

    expect(SiteAccessQueryGuard::violations($source))->toHaveCount(1)
        ->and(SiteAccessQueryGuard::violations('<?php use Capell\\Core\\Support\\Permissions\\SiteAccess; use Capell\\Core\\Models\\Page; SiteAccess::current()->query(Page::class)->count();'))->toBe([]);
});

it('detects dynamic model queries and resources relying on unscoped inherited queries', function (): void {
    expect(SiteAccessQueryGuard::violations('<?php use Capell\\Core\\Models\\Page; $model = Page::class; $model::query()->get();'))->toHaveCount(1)
        ->and(SiteAccessQueryGuard::violations('<?php use Capell\\Core\\Models\\Page; use Filament\\Resources\\Resource; class Pages extends Resource { protected static ?string $model = Page::class; }'))->toHaveCount(1)
        ->and(SiteAccessQueryGuard::violations('<?php use Capell\\Core\\Models\\Page; use Capell\\Admin\\Filament\\Resources\\SiteScopedResource; class Pages extends SiteScopedResource { protected static ?string $model = Page::class; }'))->toBe([]);
});

it('tracks reassigned class strings without mistaking global model queries for site queries', function (): void {
    $source = '<?php use Capell\\Core\\Models\\Page; use Capell\\Core\\Models\\Language; $model = Language::class; $model::query()->count();
$model = Page::class; $model::query()->count();';
    expect(SiteAccessQueryGuard::violations($source))->toHaveCount(1);
});

it('protects site-owned graph, snapshot and metric query origins', function (): void {
    foreach (['ContentGraphEdge', 'LayoutContentSnapshot', 'MetricEvent'] as $model) {
        expect(SiteAccessQueryGuard::violations('<?php \\Capell\\Core\\Models\\' . $model . '::query()->count();'))
            ->toHaveCount(1);
    }
});

it('covers every Core model declaring a local site ownership attribute', function (): void {
    $root = dirname(__DIR__, 4);
    foreach (File::allFiles($root . '/packages/core/src/Models') as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'Capell\\Core\\Models\\' . $file->getBasename('.php');
        if (! is_subclass_of($class, Model::class)) {
            continue;
        }

        $fillable = new ReflectionClass($class)->getDefaultProperties()['fillable'] ?? [];
        if (in_array('site_id', $fillable, true)) {
            expect(SiteAccessQueryGuard::violations('<?php \\' . $class . '::query()->count();'))->toHaveCount(1);
        }
    }
});

it('detects Eloquent query shortcuts including soft deleted queries', function (string $method): void {
    expect(SiteAccessQueryGuard::violations('<?php ' . Page::class . '::' . $method . '();'))->toHaveCount(1);
})->with(['onlyTrashed', 'withTrashed', 'whereIn', 'firstWhere', 'latest']);
