<?php

declare(strict_types=1);

use Capell\Frontend\Actions\RenderFallbackPublicViewAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

it('does not render a nested non-allowlisted app view via the path fallback', function (): void {
    $viewDirectory = resource_path('views/admin');
    $viewPath = $viewDirectory . '/users.blade.php';

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '<h1>Secret admin user list</h1>');

    $request = Request::create('/admin/users');
    app()->instance('request', $request);

    try {
        $response = RenderFallbackPublicViewAction::run($request);
    } finally {
        File::delete($viewPath);
        File::deleteDirectory($viewDirectory);
    }

    // The view exists in the shared finder but its name ("admin.users") is a
    // multi-segment name whose leading segment is not allowlisted, so the
    // fallback must refuse to render it.
    expect($response)->toBeNull();
});

it('still renders a legitimate single-segment public fallback view', function (): void {
    $viewDirectory = resource_path('views');
    $viewPath = $viewDirectory . '/about.blade.php';

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '<h1>About our store</h1>');

    $request = Request::create('/about');
    app()->instance('request', $request);

    try {
        $response = RenderFallbackPublicViewAction::run($request);
        $content = $response?->getContent();
    } finally {
        File::delete($viewPath);
    }

    expect($response)->toBeInstanceOf(Response::class)
        ->and($content)->toContain('About our store');
});

it('renders a nested view only when its leading segment is an allowlisted prefix', function (): void {
    config()->set('capell-frontend.fallback_public_views', [
        'view_names' => [],
        'prefixes' => ['pages'],
    ]);

    $viewDirectory = resource_path('views/pages');
    $viewPath = $viewDirectory . '/contact.blade.php';

    File::ensureDirectoryExists($viewDirectory);
    File::put($viewPath, '<h1>Contact page</h1>');

    $request = Request::create('/pages/contact');
    app()->instance('request', $request);

    try {
        $response = RenderFallbackPublicViewAction::run($request);
        $content = $response?->getContent();
    } finally {
        File::delete($viewPath);
        File::deleteDirectory($viewDirectory);
    }

    expect($response)->toBeInstanceOf(Response::class)
        ->and($content)->toContain('Contact page');
});
