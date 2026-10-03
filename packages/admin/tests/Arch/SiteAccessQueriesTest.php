<?php

declare(strict_types=1);

use Capell\Admin\Tests\Support\SiteAccessQueryAllowList;
use Capell\Admin\Tests\Support\SiteAccessQueryGuard;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;

it('requires site sensitive Filament and report queries to start at SiteAccess', function (): void {
    $root = dirname(__DIR__, 4);
    expect(SiteAccessQueryGuard::scan($root, SiteAccessQueryAllowList::entries()))->toBe([]);
});

it('keeps every exact exception necessary rather than accumulating a query baseline', function (): void {
    $root = dirname(__DIR__, 4);
    foreach (SiteAccessQueryAllowList::entries() as $path => $entries) {
        $source = File::get($root . '/' . $path);
        $unscoped = count(SiteAccessQueryGuard::violations($source));
        foreach ($entries as $entry) {
            Assert::assertLessThan($unscoped, count(SiteAccessQueryGuard::violations($source, [$entry])), $path . '|' . $entry);
        }
    }
});

it('scans authoring entry points beyond Filament with exact file exceptions', function (string $directory): void {
    $root = sys_get_temp_dir() . '/site-access-guard-' . bin2hex(random_bytes(8));
    $path = $directory . '/Probe.php';
    File::makeDirectory($root . '/' . $directory, 0755, true);
    try {
        File::put($root . '/' . $path, '<?php class Probe { function read() { ' . Page::class . '::query()->count(); } }');
        expect(SiteAccessQueryGuard::scan($root))->toHaveCount(1)
            ->and(SiteAccessQueryGuard::scan($root, [$path => ['read|' . Page::class . '::query()->count()']]))->toBe([])
            ->and(SiteAccessQueryGuard::scan($root, [$directory . '/Other.php' => ['read|' . Page::class . '::query()->count()']]))->toHaveCount(1);
        File::put($root . '/' . $path, '<?php ' . SiteAccess::class . '::current()->query(' . Page::class . '::class)->count();');
        expect(SiteAccessQueryGuard::scan($root))->toBe([]);
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'packages/admin/src/Livewire', 'packages/admin/src/Macros', 'packages/admin/src/Http/Agent',
    'packages/admin/src/Http/Middleware', 'packages/admin/src/Jobs', 'packages/admin/src/Providers',
    'packages/admin/src/Data', 'packages/marketplace/src/Support', 'packages/marketplace/src/Http',
    'packages/marketplace/src/Livewire',
]);

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

it('protects runtime model aliases outside the Core namespace', function (): void {
    if (! class_exists('SiteAccessVariationProbe', autoload: false)) {
        class_alias(Page::class, 'SiteAccessVariationProbe');
    }

    expect(SiteAccessQueryGuard::violations('<?php $model = "SiteAccessVariationProbe"; $model::query()->get();'))->not->toBeEmpty();
});

it('protects site-owned graph, snapshot and metric query origins', function (): void {
    foreach (['ContentGraphEdge', 'LayoutContentSnapshot', 'MetricEvent', 'ExtensionHealthAlert'] as $model) {
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

it('covers the declared site-owned and polymorphic Core relationship origins', function (): void {
    /** @var list<string> $models */
    $models = new ReflectionClass(SiteAccessQueryGuard::class)->getConstant('MODELS');
    foreach ($models as $model) {
        /** @var class-string<Model> $class */
        $class = 'Capell\\Core\\Models\\' . $model;
        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            if (! is_a($type->getName(), Relation::class, true)) {
                continue;
            }

            preg_match('/@return\s+[\\\\\w]+<([^,>]+)/', $method->getDocComment() ?: '', $target);
            $targetModel = basename(str_replace('\\', '/', $target[1] ?? ''));
            if (is_a($type->getName(), MorphTo::class, true)
                || in_array($targetModel, [...$models, 'Activity', 'TMedia'], true)) {
                expect(SiteAccessQueryGuard::violations('<?php $record->' . $method->getName() . '()->cursor();'))->not->toBeEmpty();
            }
        }
    }
});

it('detects Eloquent query shortcuts including soft deleted queries', function (string $method): void {
    expect(SiteAccessQueryGuard::violations('<?php ' . Page::class . '::' . $method . '();'))->toHaveCount(1);
})->with(['onlyTrashed', 'withTrashed', 'whereIn', 'firstWhere', 'latest']);

it('reports raw relation instance dynamic and indirect ownership bypasses', function (string $source): void {
    expect(SiteAccessQueryGuard::violations('<?php ' . $source))->not->toBeEmpty();
})->with([
    'global facade alias' => "DB::table('pages')->get();",
    'connection table' => "use Illuminate\\Support\\Facades\\DB; DB::connection()->table('pages')->get();",
    'raw pages' => "use Illuminate\\Support\\Facades\\DB; DB::table('pages')->get();",
    'aliased raw pages' => "use Illuminate\\Support\\Facades\\DB; DB::table('pages as p')->cursor();",
    'qualified raw pages' => 'use Illuminate\\Support\\Facades\\DB; DB::table("schema.\"pages\" p")->get();',
    'raw indirect' => "use Illuminate\\Support\\Facades\\DB; DB::table('translations')->cursor();",
    'raw site health alerts' => "use Illuminate\\Support\\Facades\\DB; DB::table('capell_extension_health_alerts')->get();",
    'dynamic raw' => 'use Illuminate\\Support\\Facades\\DB; DB::table($table)->get();',
    'relation cursor' => '$site->pages()->cursor();',
    'relation chunk' => '$site->pages()->chunk(10, fn ($pages) => null);',
    'singular owner relation' => '$record->site()->cursor();',
    'media owner relation' => '$media->model()->get();',
    'sibling relation' => '$page->siblings()->cursor();',
    'revision relation' => '$page->pageRevisions()->chunk(10, fn ($rows) => null);',
    'relation assigned' => '$query = $site->pages(); $query->get();',
    'relation with arguments' => '$site->pages(["*"])->cursor();',
    'scope after execution' => 'use Capell\\Core\\Support\\Permissions\\SiteAccess; use Capell\\Core\\Models\\Page; SiteAccess::current()->scope(Page::query()->get());',
    'scope after mutation' => 'use Capell\\Core\\Support\\Permissions\\SiteAccess; SiteAccess::current()->scope($site->pages()->delete());',
    'literal class string' => '$model = "Capell\\\\Core\\\\Models\\\\Page"; $model::query()->get();',
    'static model' => 'use Capell\\Core\\Models\\Page; class R { protected static $model = Page::class; public function records() { return static::$model::query()->get(); } }',
    'static default reassigned' => 'use Capell\\Core\\Models\\Page; use Capell\\Core\\Models\\Language; class R { protected static $model = Language::class; public function records() { static::$model = Page::class; return static::$model::query()->get(); } }',
    'unrelated static receiver' => 'use Capell\\Core\\Models\\Language; class R { protected static $model = Language::class; public function records() { return $other::$model::query()->get(); } }',
    'mutable global default' => 'use Capell\\Core\\Models\\Language; class R { protected static $model = Language::class; public function records() { return static::$model::query()->get(); } }',
    'unknown dynamic model' => '$model::query()->cursor();',
    'unknown literal model' => '$model = "Unregistered\\\\Cms\\\\Page"; $model::query()->get();',
    'new query' => 'use Capell\\Core\\Models\\Page; (new Page)->newQuery()->get();',
    'new model query' => 'use Capell\\Core\\Models\\Page; (new Page)->newModelQuery()->chunk(10, fn ($pages) => null);',
    'instance assigned' => 'use Capell\\Core\\Models\\Page; $page = new Page; $page->newQuery()->get();',
    'unknown instance' => '$record->newModelQuery()->get();',
    'base model instance' => 'use Illuminate\\Database\\Eloquent\\Model; function rows(Model $record) { return $record->newQuery()->cursor(); }',
    'interface instance' => 'use Capell\\Core\\Contracts\\Pageable; function rows(Pageable $record) { return $record->newModelQuery()->get(); }',
    'translation' => 'use Capell\\Core\\Models\\Translation; Translation::query()->get();',
    'term property' => 'use Capell\\Core\\Models\\TermPropertyValue; TermPropertyValue::query()->get();',
    'revision' => 'use Capell\\Core\\Models\\PageRevision; PageRevision::query()->get();',
    'workflow' => 'use Capell\\Core\\Models\\PageWorkflowState; PageWorkflowState::query()->get();',
    'activity' => 'use Spatie\\Activitylog\\Models\\Activity; Activity::query()->get();',
    'lock' => 'use Capell\\Core\\Models\\ContentLock; ContentLock::query()->get();',
    'vendor media model' => 'use Spatie\\MediaLibrary\\MediaCollections\\Models\\Media; Media::query()->get();',
    'vendor media instance' => 'use Spatie\\MediaLibrary\\MediaCollections\\Models\\Media; function read(Media $record) { return $record->newQuery()->get(); }',
]);

it('does not let an exact system exception authorise another query or a reassigned access variable', function (): void {
    $source = '<?php class Validator { public function validateDelete($record) { $record->pages()->exists(); $record->pages()->get(); } }';
    expect(SiteAccessQueryGuard::violations($source, ['validateDelete|$record->pages()->exists()']))->toHaveCount(1);
    $reassigned = '<?php use Capell\\Core\\Support\\Permissions\\SiteAccess; use Capell\\Core\\Models\\Page; class Reader { public function read() { $access = SiteAccess::current(); $access = new stdClass; return $access->scope(Page::query()); } }';
    expect(SiteAccessQueryGuard::violations($reassigned))->toHaveCount(1);
});

it('keeps legitimate visible relation and instance scopes accepted', function (): void {
    $source = '<?php use Capell\\Core\\Support\\Permissions\\SiteAccess; use Capell\\Core\\Models\\Page; SiteAccess::current()->scope($site->pages()->getQuery())->cursor(); SiteAccess::current()->scope((new Page)->newQuery())->get();';
    expect(SiteAccessQueryGuard::violations($source))->toBe([]);
});

it('detects lazy properties and string relation arguments before they can bypass the access boundary', function (string $expression): void {
    $source = '<?php use Capell\\Core\\Models\\Layout; function read(Layout $record) { return ' . $expression . '; }';
    expect(SiteAccessQueryGuard::violations($source))->not->toBeEmpty();
})->with([
    '$record->pages->count()', '$record->pages', '$record?->pages',
    '$record->withCount("pages")', '$record->with("pages")', '$record->has("pages")',
    '$record->whereHas("pages")', '$record->load("pages")', '$record->loadCount("pages")',
    '$record->getRelationValue("pages")', '$record->load(["pages.translations"])',
    '$record->withCount(["pages as total_pages"])',
    '$record->with(["pages" => fn ($query) => $query->count()])',
    '$record->with("site", "pages")',
    'SiteAccess::current()->scope($record->load("pages"))',
]);

it('keeps scalar properties and unrelated relations accepted but exceptions exact', function (): void {
    expect(SiteAccessQueryGuard::violations('<?php use Capell\\Core\\Models\\Layout; function read(Layout $record) { return [$record->name, $record->load("blueprint")]; }'))->toBe([]);
    $source = '<?php use Capell\\Core\\Models\\Layout; class Reader { function read(Layout $record) { $record->pages->count(); $record->pages; } }';
    expect(SiteAccessQueryGuard::violations($source, ['read|$record->pages->count()']))->toHaveCount(1);
});

it('accepts relations bounded by a single site without confusing them with shared parent reads', function (string $source): void {
    expect(SiteAccessQueryGuard::violations('<?php ' . $source))->toBe([]);
})->with([
    'page translations' => 'use Capell\\Core\\Models\\Page; function read(Page $record) { return $record->translations->count(); }',
    'site domains' => 'use Capell\\Core\\Models\\Site; function read(Site $record) { return $record->siteDomains; }',
    'page eager load' => 'use Capell\\Core\\Models\\Page; function read(Page $record) { return $record->load("translations"); }',
    'site eager load' => 'use Capell\\Core\\Models\\Site; function read(Site $record) { return $record->load("siteDomains"); }',
    'taxonomy terms' => 'use Capell\\Core\\Models\\Taxonomy; function read(Taxonomy $record) { return $record->terms; }',
    'term properties' => 'use Capell\\Core\\Models\\Term; function read(Term $record) { return $record->propertyValues; }',
    'global metadata' => 'use Capell\\Core\\Models\\Layout; function read(Layout $record) { return $record->load("blueprint"); }',
    'non model receiver' => 'function read(stdClass $record) { return $record->pages; }',
]);

it('detects shared relationships using model definitions and follows nested relation paths', function (string $source): void {
    expect(SiteAccessQueryGuard::violations('<?php ' . $source))->not->toBeEmpty();
})->with([
    'global language sites' => 'use Capell\\Core\\Models\\Language; function read(Language $record) { return $record->sites; }',
    'global language translations' => 'use Capell\\Core\\Models\\Language; function read(Language $record) { return $record->load("translations"); }',
    'shared media usage' => 'use Capell\\Core\\Models\\Media; function read(Media $record) { return $record->assetRelations->count(); }',
    'nested layout pages' => 'use Capell\\Core\\Models\\Page; function read(Page $record) { return $record->load("layout.pages"); }',
    'nested lazy layout pages' => 'use Capell\\Core\\Models\\Page; function read(Page $record) { return $record->layout->pages; }',
    'scoped layout still has foreign pages' => 'use Capell\\Core\\Models\\Layout; use Capell\\Core\\Support\\Permissions\\SiteAccess; $layout = SiteAccess::current()->query(Layout::class)->first(); $layout->pages->count();',
    'parent scope does not scope counts' => 'use Capell\\Core\\Models\\Layout; use Capell\\Core\\Support\\Permissions\\SiteAccess; SiteAccess::current()->scope(Layout::query()->withCount("pages"));',
    'derived language relation name' => 'use Capell\\Core\\Models\\Language; function read(Language $record) { return $record->loadCount("sitesLanguage"); }',
    'callback cannot execute before scope' => 'use Capell\\Core\\Models\\Layout; use Capell\\Core\\Support\\Permissions\\SiteAccess; Layout::query()->withCount(["pages" => fn ($query) => SiteAccess::current()->scope($query->get())]);',
    'callback cannot scope an unrelated query' => 'use Capell\\Core\\Models\\Layout; use Capell\\Core\\Support\\Permissions\\SiteAccess; Layout::query()->withCount(["pages" => fn ($query) => SiteAccess::current()->scope($other)]);',
    'unresolved builder model' => 'use Illuminate\\Database\\Eloquent\\Builder; function read(Builder $query) { return $query->withCount("pages"); }',
]);

it('accepts an explicitly scoped child aggregate without treating other strings as relations', function (string $source): void {
    expect(SiteAccessQueryGuard::violations('<?php ' . $source))->toBe([]);
})->with([
    'scoped count' => 'use Capell\\Core\\Models\\Theme; use Capell\\Core\\Support\\Permissions\\SiteAccess; Theme::query()->withCount(["sites" => fn ($query) => SiteAccess::current()->scope($query)]);',
    'scalar comparison' => 'use Capell\\Core\\Models\\Layout; function read(Layout $record) { return $record->whereRelation("blueprint", "name", "pages"); }',
    'callback string' => 'use Capell\\Core\\Models\\Layout; function read(Layout $record) { return $record->with(["blueprint" => fn ($query) => $query->where("name", "pages")]); }',
    'non eloquent method' => 'use Illuminate\\Support\\Facades\\Route; Route::has("admin.pages.index");',
    'authorised page child query' => 'use Capell\\Core\\Models\\Page; function read(Page $record) { return $record->pageUrls()->count(); }',
    'shared owner image' => 'use Capell\\Core\\Models\\Layout; function read(Layout $record) { return $record->image; }',
    'shared owner translations' => 'use Capell\\Core\\Models\\Media; function read(Media $record) { return $record->translations; }',
]);
