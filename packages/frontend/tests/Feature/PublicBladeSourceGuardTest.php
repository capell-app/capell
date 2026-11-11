<?php

declare(strict_types=1);

use Capell\Frontend\Support\Render\PublicBladeSourceGuard;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir() . '/capell-public-blade-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->directory . '/public');
    File::ensureDirectoryExists($this->directory . '/private');
    File::put($this->directory . '/public/clean.blade.php', '<p>{{ $title }}</p>');
    File::put($this->directory . '/public/queries.blade.php', '<p>{{ \App\Models\User::query()->count() }}</p>');
    File::put($this->directory . '/public/lazy.blade.php', '<p>{{ $page->load("author")->author }}</p>');
    File::put($this->directory . '/private/queries.blade.php', '<p>{{ \App\Models\User::query()->count() }}</p>');
    View::addNamespace('guardpublic', $this->directory . '/public');
    View::addNamespace('guardprivate', $this->directory . '/private');
    resolve(PublicBladeSourceGuard::class)->registerRoot($this->directory . '/public');
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('rejects a query call in a registered public view when it is compiled', function (): void {
    expect(fn (): string => view('guardpublic::queries')->render())
        ->toThrow(RuntimeException::class, 'Eloquent query builder');
});

it('rejects lazy relationship loading in a registered public view', function (): void {
    expect(fn (): string => view('guardpublic::lazy')->render())
        ->toThrow(RuntimeException::class, 'lazy relationship loading');
});

it('compiles a clean registered public view', function (): void {
    expect(view('guardpublic::clean', ['title' => 'Hello'])->render())->toBe('<p>Hello</p>');
});

it('leaves views outside registered public roots alone', function (): void {
    expect(Blade::compileString(File::get($this->directory . '/private/queries.blade.php')))->toContain('query()');
    expect(resolve(PublicBladeSourceGuard::class)->inspect('{{ DB::table("x") }}', $this->directory . '/private/queries.blade.php'))
        ->toBe('{{ DB::table("x") }}');
});

it('logs instead of throwing in log mode and skips the check when disabled', function (): void {
    Log::shouldReceive('warning')->once();
    config()->set('capell-frontend.public_view_query_guard.mode', 'log');
    $path = $this->directory . '/public/queries.blade.php';

    expect(resolve(PublicBladeSourceGuard::class)->inspect('{{ DB::table("x") }}', $path))->toBe('{{ DB::table("x") }}');

    config()->set('capell-frontend.public_view_query_guard.mode', 'exception');
    config()->set('capell-frontend.public_view_query_guard.enabled', false);

    expect(resolve(PublicBladeSourceGuard::class)->inspect('{{ DB::table("x") }}', $path))->toBe('{{ DB::table("x") }}');
});
