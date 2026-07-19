# Testing in Capell

## Test Infrastructure

- **Framework**: Pest 4 with Pest plugins for Laravel, Livewire, Architecture, Type Coverage
- **Coverage**: PCOV, minimum 90% threshold
- **Suites**: Architecture, Feature, Integration, Unit

Test directories:

```
tests/
├── Core/
│   ├── Arch/          # Architecture tests (namespace, conventions)
│   ├── Feature/       # Full workflow tests
│   ├── Integration/   # Integration with DB/cache/queue
│   └── Unit/          # Isolated logic tests
├── Admin/             # Admin panel & Filament tests
├── Frontend/          # Frontend rendering & cache tests
└── Support/           # Shared test helpers & traits
```

## Running Tests

```bash
# Full suite (parallel) — primary command
composer test

# Specific suite
./vendor/bin/pest --testsuite=Feature
./vendor/bin/pest --testsuite=Unit
./vendor/bin/pest --testsuite=Architecture

# Specific package
./vendor/bin/pest tests/Core/
./vendor/bin/pest tests/Admin/
./vendor/bin/pest tests/Frontend/

# With coverage
./vendor/bin/pest --coverage --min=90

# Filter by test name
./vendor/bin/pest --filter="creates a page"
```

## Pest Conventions in Capell

### Action Boundaries

Test Actions directly with `MyAction::run($input)` when the Action owns the behavior.

When another surface only delegates to an Action, such as an Artisan command, controller, job, Filament page, or Livewire component, do not repeat the Action's full behavior there. Give the Action `Lorisleiva\Actions\Concerns\AsFake` when it needs to be faked in tests, then use the Laravel Actions helpers:

```php
MyAction::shouldRun()
    ->once()
    ->withArgs(fn (InputData $input): bool => $input->siteUrl === 'https://example.test')
    ->andReturn($result);

artisanCommand('capell:example')
    ->assertExitCode(Command::SUCCESS);
```

Use `shouldNotRun()` for early exits and `allowToRun()` when the test needs a spy while allowing the real implementation. Prefer these helpers over local fake classes and manual call counters unless the test needs a richer domain-specific double.

### Basic Test Structure

```php
<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

// Group related tests
describe('Page creation', function () {
    beforeEach(function () {
        $this->site = Site::factory()->createOne();
    });

    it('creates a page with valid data', function () {
        $page = Page::factory()
            ->for($this->site)
            ->create(['title' => 'Test Page']);

        expect($page)
            ->title->toBe('Test Page')
            ->site_id->toBe($this->site->id);
    });

    it('requires a title', function () {
        Page::factory()->createOne(['title' => null]);
    })->throws(\Exception::class);
});
```

### Using Factories

```php
// Core factories are in the core package's database/factories directory.
// Composer installs: vendor/capell/core/database/factories/
$site = Site::factory()->createOne();
$language = Language::factory()->for($site)->create();
$page = Page::factory()->for($site)->create();

// With states
$page = Page::factory()->published()->create();
$page = Page::factory()->draft()->create();
```

### Feature Tests (HTTP)

```php
it('renders the home page', function () {
    $site = Site::factory()->withDomain('example.com')->create();
    $page = Page::factory()->home()->for($site)->create();

    $response = $this->get('/');

    $response->assertOk()
             ->assertViewIs('frontend::page');
});

it('redirects to 404 for missing pages', function () {
    $response = $this->get('/non-existent-page');

    $response->assertNotFound();
});
```

### Admin/Filament Tests

```php
use Filament\Actions\DeleteAction;
use function Pest\Livewire\livewire;

it('can list pages in admin', function () {
    $admin = User::factory()->admin()->create();
    $pages = Page::factory()->count(3)->create();

    $this->actingAs($admin)
         ->get(route('filament.admin.resources.pages.index'))
         ->assertOk();
});

it('can create a page via Filament resource', function () {
    $admin = User::factory()->admin()->create();
    $site = Site::factory()->createOne();

    livewire(\Capell\Admin\Filament\Resources\Pages\Pages\CreatePage::class)
        ->actingAs($admin)
        ->fillForm([
            'title' => 'New Page',
            'site_id' => $site->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Page::where('title', 'New Page')->exists())->toBeTrue();
});
```

### Livewire Component Tests

```php
use function Pest\Livewire\livewire;

it('renders the blog page component', function () {
    $articles = Article::factory()->published()->count(5)->create();

    livewire(\Capell\Blog\Livewire\BlogPage::class)
        ->assertSee($articles->first()->title)
        ->assertSeeHtml('<article');
});
```

### Architecture Tests

```php
// Ensure all models extend correct base
arch('models extend Eloquent Model')
    ->expect('Capell\Core\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

// Ensure strict blueprints in all files
arch('strict blueprints everywhere')
    ->expect('Capell')
    ->toUseStrictTypes();

// Actions follow convention
arch('actions use AsAction trait')
    ->expect('App\Actions')
    ->toUseTrait('Lorisleiva\Actions\Concerns\AsAction');
```

### Testing with Datasets

```php
// Test multiple scenarios without duplication
it('validates page status', function (string $status, bool $valid) {
    $data = ['status' => $status, 'title' => 'Test'];
    $validator = validator($data, ['status' => 'in:draft,published,scheduled']);

    expect($validator->passes())->toBe($valid);
})->with([
    'draft is valid' => ['draft', true],
    'published is valid' => ['published', true],
    'invalid status' => ['deleted', false],
]);
```

### Testing HTML Cache

```php
it('caches the page HTML', function () {
    $page = Page::factory()->published()->create();

    // Disable cache for test setup
    config(['capell-frontend.html_cache' => true]);

    $this->get($page->url)
         ->assertOk();

    // Check cache file was created
    expect(Storage::disk('public')->exists("cache/{$page->url}.html"))
        ->toBeTrue();
});

it('purges cache when page is updated', function () {
    $page = Page::factory()->published()->create();
    // ... create cache file

    $page->update(['title' => 'Updated Title']);

    // Cache should be gone
    expect(Storage::disk('public')->exists("cache/{$page->url}.html"))
        ->toBeFalse();
});
```

### Mocking / Faking Services

```php
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;

it('dispatches cache generation job', function () {
    Queue::fake();

    $page = Page::factory()->published()->create();
    event(new PagePublished($page));

    Queue::assertDispatched(\Capell\Frontend\Jobs\GeneratePageHtml::class, function ($job) use ($page) {
        return $job->page->id === $page->id;
    });
});
```

### Testing Multi-Site

```php
it('resolves correct site from domain', function () {
    $site1 = Site::factory()->withDomain('site1.com')->create();
    $site2 = Site::factory()->withDomain('site2.com')->create();

    // Simulate request from site1.com
    $this->withServerVariables(['HTTP_HOST' => 'site1.com'])
         ->get('/')
         ->assertOk();

    // The resolved site should be site1
    expect(app('capell.current_site')->id)->toBe($site1->id);
});
```

## Test Helpers & Traits

Check `tests/Support/` for shared test utilities. Common patterns:

- `RefreshDatabase` is required for most tests
- `actingAs($admin)` for authenticated admin tests
- Factory states (`.published()`, `.draft()`, `.home()`) are in core factories

## phpunit.xml Configuration

```xml
<testsuites>
    <testsuite name="Architecture">
        <directory suffix="Test.php">tests/*/Arch</directory>
    </testsuite>
    <testsuite name="Feature">
        <directory suffix="Test.php">tests/*/Feature</directory>
    </testsuite>
    <testsuite name="Integration">
        <directory suffix="Test.php">tests/*/Integration</directory>
    </testsuite>
    <testsuite name="Unit">
        <directory suffix="Test.php">tests/*/Unit</directory>
    </testsuite>
</testsuites>
```

## Code Quality

Run before committing (batch related slices, defer until end-of-phase commit):

```bash
composer preflight   # PHPStan + changed-file Pint
composer test        # Pest (parallel), must be 100% pass
```
