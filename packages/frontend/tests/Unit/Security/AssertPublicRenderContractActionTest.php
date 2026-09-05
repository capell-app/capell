<?php

declare(strict_types=1);

use Capell\Core\Exceptions\SchemaProbeFailedException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Capell\Frontend\Actions\AssertPublicRenderContractAction;
use Capell\Frontend\Actions\RecordPublicRenderContractEventAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Exceptions\PublicRenderContractViolationException;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    CapellCore::clearPackages();
    app()->instance(CapellPackageRegistry::class, new CapellPackageRegistry);
    PublicRenderContractEvent::query()->delete();
});

it('rejects package internals in anonymous public html', function (): void {
    $response = new Response('<main data-source="/vendor/capell-app/frontend-authoring/resources/views/edit.blade.php">Edit</main>', Response::HTTP_OK, ['Content-Type' => 'text/html']);

    expect(fn () => AssertPublicRenderContractAction::run($response))
        ->toThrow(RuntimeException::class, 'Capell internal marker');
});

it('rejects unsafe render hook output in anonymous public html', function (): void {
    $registry = new RenderHookRegistry;
    $registry->register(
        RenderHookLocation::HeadClose,
        fn (): string => '<script>window.capellPreview = {"model_id":42}</script>',
    );

    $response = new Response(
        '<html><head>' . $registry->renderAll(RenderHookLocation::HeadClose) . '</head><body>Public</body></html>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html'],
    );

    expect(fn () => AssertPublicRenderContractAction::run($response))
        ->toThrow(RuntimeException::class, 'Public HTML contains an authoring marker.');
});

it('rejects render hook output carrying an undocumented data-capell attribute', function (): void {
    // Render hooks emit HTML at runtime, bypassing the Blade-source arch test.
    // An extension that smuggles an undocumented `data-capell-*` attribute into
    // public output must be rejected by the post-render contract.
    $registry = new RenderHookRegistry;
    $registry->register(
        RenderHookLocation::FooterAfter,
        fn (): string => '<div data-capell-internal-id="42">Promo</div>',
    );

    $response = new Response(
        '<html><body>Public' . $registry->renderAll(RenderHookLocation::FooterAfter) . '</body></html>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html'],
    );

    expect(fn () => AssertPublicRenderContractAction::run($response))
        ->toThrow(RuntimeException::class, 'Public HTML contains an authoring marker.');
});

it('allows render hook output using only documented data-capell runtime attributes', function (): void {
    $registry = new RenderHookRegistry;
    $registry->register(
        RenderHookLocation::FooterAfter,
        fn (): string => '<div data-capell-widget-runtime="carousel" data-capell-widget-assets="[]">Promo</div>',
    );

    $response = new Response(
        '<html><body>Public' . $registry->renderAll(RenderHookLocation::FooterAfter) . '</body></html>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html'],
    );

    expect(fn () => AssertPublicRenderContractAction::run($response))->not->toThrow(RuntimeException::class);
});

it('rejects unsafe public html for authenticated non admin visitors', function (): void {
    $user = User::factory()->createOne();

    $request = Request::create('https://example.test/news');
    $request->setUserResolver(fn (): User => $user);

    app()->instance('request', $request);

    $response = new Response(
        '<main><a href="/admin/pages/1/edit?signature=plain-signature">Edit</a></main>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html'],
    );

    expect(fn () => AssertPublicRenderContractAction::run($response))
        ->toThrow(RuntimeException::class, 'Public HTML contains a signed admin URL.');
});

it('does not reject a baked csrf token, since that is a cache-eligibility signal, not a render-contract violation', function (): void {
    // A baked CSRF token must never enter the *shared*
    // HTML cache through the paired html-cache package, but a
    // live, single-visitor render — full page or fragment — legitimately
    // contains a real token for that visitor. This contract must not reject
    // it, or the fragment sub-request fix would 500 for
    // every visitor instead of the page merely staying uncached.
    $response = new Response(
        '<form method="post"><input type="hidden" name="_token" value="abc123"></form>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html'],
    );

    expect(fn () => AssertPublicRenderContractAction::run($response))->not->toThrow(RuntimeException::class);
});

it('allows public composer package names and repository urls', function (): void {
    $response = new Response(
        '<a href="https://github.com/capell-app/capell">GitHub</a><pre><code>composer require capell-app/installer</code></pre>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html'],
    );

    expect(fn () => AssertPublicRenderContractAction::run($response))->not->toThrow(RuntimeException::class);
});

it('remembers safe public html inspection details on the current request and frontend context', function (): void {
    $content = '<article><h1>News</h1></article>';
    $request = Request::create('https://example.test/news');
    $context = new FrontendContext(null, null, null, null, null, [], null);

    app()->instance('request', $request);
    app()->instance(FrontendContextReader::class, $context);

    AssertPublicRenderContractAction::run(new Response($content, Response::HTTP_OK, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]));

    expect($request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE))->toBeTrue()
        ->and($request->attributes->get(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE))->toBe(hash('xxh128', $content))
        ->and($context->getFrontendData('publicHtmlSafetyInspected'))->toBeTrue()
        ->and($context->getFrontendData('publicHtmlSafetyInspectedHash'))->toBe(hash('xxh128', $content));
});

it('does not record passed public render contract events by default', function (): void {
    $request = Request::create('https://example.test/news?token=plain-token');

    app()->instance('request', $request);

    AssertPublicRenderContractAction::run(new Response('<article><h1>News</h1></article>', Response::HTTP_OK, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]));

    expect(PublicRenderContractEvent::query()->count())->toBe(0);
});

it('records passed public render contract events only when enabled', function (): void {
    config(['capell-frontend.public_render_contract_events.record_passed' => true]);

    $content = '<article><h1>News</h1></article>';
    $request = Request::create('https://example.test/news?token=plain-token');

    app()->instance('request', $request);

    AssertPublicRenderContractAction::run(new Response($content, Response::HTTP_OK, [
        'Content-Type' => 'text/html; charset=UTF-8',
    ]));

    $event = PublicRenderContractEvent::query()->sole();

    expect($event->result)->toBe('passed')
        ->and($event->url_hash)->toBe(hash('xxh128', 'https://example.test/news?token=plain-token'))
        ->and($event->path_hash)->toBe(hash('xxh128', '/news'))
        ->and($event->response_hash)->toBe(hash('xxh128', $content))
        ->and($event->reason)->toBeNull()
        ->and($event->matched_marker)->toBeNull();
});

it('records failed public render contract events', function (): void {
    $request = Request::create('https://example.test/news?signature=plain-signature');
    $response = new Response(
        '<main data-source="/vendor/capell-app/frontend-authoring/resources/views/edit.blade.php?token=plain-token">Edit</main>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html; charset=UTF-8'],
    );

    app()->instance('request', $request);

    expect(fn () => AssertPublicRenderContractAction::run($response))
        ->toThrow(RuntimeException::class, 'Capell internal marker');

    $event = PublicRenderContractEvent::query()->sole();

    expect($event->result)->toBe('failed')
        ->and($event->reason)->toContain('Capell internal marker')
        ->and($event->matched_marker)->toBe('vendor/capell-app/')
        ->and($event->url_hash)->toBe(hash('xxh128', 'https://example.test/news?signature=plain-signature'))
        ->and($event->source)->toBe('public_render_contract');
});

it('surfaces schema probe failures instead of silently dropping public render contract events', function (): void {
    config(['capell-frontend.public_render_contract_events.record_passed' => true]);

    Schema::shouldReceive('hasTable')
        ->once()
        ->with('capell_public_render_contract_events')
        ->andThrow(new RuntimeException('database unavailable'));

    resolve(RuntimeSchemaState::class)->forgetTable('capell_public_render_contract_events');

    expect(fn () => RecordPublicRenderContractEventAction::run(
        result: 'passed',
        response: new Response('<main>News</main>', Response::HTTP_OK),
    ))->toThrow(SchemaProbeFailedException::class);
});

it('preserves a public render violation when recording it hits a schema probe failure', function (): void {
    Exceptions::fake();

    Schema::shouldReceive('hasTable')
        ->once()
        ->with('capell_public_render_contract_events')
        ->andThrow(new RuntimeException('database unavailable'));

    resolve(RuntimeSchemaState::class)->forgetTable('capell_public_render_contract_events');

    expect(fn () => AssertPublicRenderContractAction::run(
        new Response('<main data-source="vendor/capell-app/">Edit</main>', Response::HTTP_OK),
    ))->toThrow(PublicRenderContractViolationException::class, 'Capell internal marker');

    Exceptions::assertReported(
        fn (SchemaProbeFailedException $exception): bool => $exception->getPrevious()?->getMessage() === 'database unavailable',
    );
});

it('redacts sensitive public render contract event diagnostics before persistence', function (): void {
    $request = Request::create('https://example.test/news?signature=plain-signature');

    app()->instance('request', $request);

    RecordPublicRenderContractEventAction::run(
        result: 'failed',
        response: new Response('<main>News</main>', Response::HTTP_OK, ['Content-Type' => 'text/html']),
        reason: 'Public HTML contains /admin/pages/1?signature=plain-signature',
        matchedMarker: 'vendor/acme/forms?token=plain-token&signature=plain-signature',
        category: 'authoring_marker',
    );

    $event = PublicRenderContractEvent::query()->sole();

    expect($event->reason)->toContain('signature=[redacted]')
        ->and($event->reason)->not->toContain('plain-signature')
        ->and($event->matched_marker)->toContain('token=[redacted]')
        ->and($event->matched_marker)->toContain('signature=[redacted]')
        ->and($event->matched_marker)->not->toContain('plain-token')
        ->and($event->package_name)->toBe('acme/forms')
        ->and($event->source)->toBe('authoring_marker');
});

it('rejects livewire state without package page or page type permission', function (): void {
    $type = Blueprint::factory()->page()->create([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeOnly->value],
        'is_livewire' => false,
    ]);
    $page = Page::factory()->type($type)->create([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeOnly->value],
    ]);
    $page->load('blueprint');

    $context = new FrontendContext(null, null, $page, null, null, [], null);
    $response = new Response('<div wire:id="abc" wire:snapshot="{}"></div>', Response::HTTP_OK, ['Content-Type' => 'text/html']);

    app()->instance(FrontendContextReader::class, $context);

    expect(fn () => AssertPublicRenderContractAction::run($response))
        ->toThrow(RuntimeException::class, 'Livewire runtime state');
});

it('allows livewire state when a package declares the livewire capability', function (): void {
    $response = new Response('<div wire:id="abc" wire:snapshot="{}"></div>', Response::HTTP_OK, ['Content-Type' => 'text/html']);

    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/forms',
        surfaces: ['frontend'],
        overrides: [
            'capabilities' => ['requires-livewire'],
            'performance' => [
                'cacheSafety' => [
                    'cacheable' => false,
                ],
            ],
        ],
    ));

    $registry = new CapellPackageRegistry;
    $registry->fill(['vendor/forms' => $manifest]);

    app()->instance(CapellPackageRegistry::class, $registry);

    expect(fn () => AssertPublicRenderContractAction::run($response))->not->toThrow(RuntimeException::class);
});

it('allows livewire state when the current page rendering strategy allows livewire markers', function (RenderingStrategyEnum $renderingStrategy): void {
    $page = Page::factory()->create([
        'meta' => ['rendering_strategy' => $renderingStrategy->value],
    ]);
    $context = new FrontendContext(null, null, $page, null, null, [], null);
    $response = new Response('<div wire:id="abc" wire:snapshot="{}"></div>', Response::HTTP_OK, ['Content-Type' => 'text/html']);

    app()->instance(FrontendContextReader::class, $context);

    expect(fn () => AssertPublicRenderContractAction::run($response))->not->toThrow(RuntimeException::class);
})->with([
    'blade with islands' => [RenderingStrategyEnum::BladeWithIslands],
    'full livewire' => [RenderingStrategyEnum::FullLivewire],
]);

it('allows livewire state when the current page type opts into livewire', function (): void {
    $type = Blueprint::factory()->page()->meta(['livewire' => true])->create();
    $page = Page::factory()->type($type)->create();
    $page->load('blueprint');

    $context = new FrontendContext(null, null, $page, null, null, [], null);
    $response = new Response('<div wire:id="abc" wire:snapshot="{}"></div>', Response::HTTP_OK, ['Content-Type' => 'text/html']);

    expect($page->getRelations())->toHaveKey('blueprint')
        ->and($page->getRelation('blueprint')->is_livewire)->toBeTrue();

    app()->instance(FrontendContextReader::class, $context);

    expect(fn () => AssertPublicRenderContractAction::run($response))->not->toThrow(RuntimeException::class);
});

it('still rejects internal marker names when a loaded livewire page type allows livewire state', function (): void {
    $type = Blueprint::factory()->page()->meta(['livewire' => true])->create();
    $page = Page::factory()->type($type)->create();
    $page->load('blueprint');

    $context = new FrontendContext(null, null, $page, null, null, [], null);
    $response = new Response(
        '<div wire:id="abc" wire:snapshot="{}" data-state="frontendContextToken"></div>',
        Response::HTTP_OK,
        ['Content-Type' => 'text/html'],
    );

    expect($page->getRelations())->toHaveKey('blueprint')
        ->and($page->getRelation('blueprint')->is_livewire)->toBeTrue();

    app()->instance(FrontendContextReader::class, $context);

    expect(fn () => AssertPublicRenderContractAction::run($response))
        ->toThrow(RuntimeException::class, 'Capell internal marker');
});

it('skips public-only checks for preview audience', function (): void {
    $context = new FrontendContext(null, null, null, null, null, [], null);
    $context->setFrontendData('renderAudience', FrontendRenderAudience::Preview);

    app()->instance(FrontendContextReader::class, $context);

    $response = new Response('<div wire:id="abc" data-capell-editor="1"></div>', Response::HTTP_OK, ['Content-Type' => 'text/html']);

    expect(fn () => AssertPublicRenderContractAction::run($response))->not->toThrow(RuntimeException::class);
});
