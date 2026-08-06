<?php

declare(strict_types=1);

use Capell\Core\Actions\Activity\RecordActivityBucketAction;
use Capell\Core\Contracts\ActivitySettingsReader;
use Capell\Frontend\Http\Controllers\ActivityBeaconController;
use Capell\Frontend\Support\Http\CrawlerDetector;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

function makeActivityBeaconControllerForTest(bool $enabled = true): ActivityBeaconController
{
    $settings = new class($enabled) implements ActivitySettingsReader
    {
        public function __construct(private readonly bool $enabled) {}

        public function collectionEnabled(): bool
        {
            return $this->enabled;
        }

        public function searchCollectionEnabled(): bool
        {
            return false;
        }

        public function retentionDays(): int
        {
            return 1;
        }
    };

    return new ActivityBeaconController($settings, resolve(RecordActivityBucketAction::class), new CrawlerDetector);
}

it('keeps the activity beacon sessionless and rejects non-page subjects silently', function (): void {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn (Route $route): bool => $route->getName() === 'capell-frontend.activity');

    expect($route)->toBeInstanceOf(Route::class)
        ->and($route->gatherMiddleware())->not->toContain('web')
        ->and(makeActivityBeaconControllerForTest()(Request::create('/_capell/activity', 'POST', ['type' => 'search_term']))->getStatusCode())->toBe(204);
});

it('honors browser privacy signals before resolving or recording a page', function (string $header): void {
    $request = Request::create('/_capell/activity', 'POST', [
        'type' => 'page_view',
        'path' => '/about',
    ], server: [$header => '1']);

    expect(makeActivityBeaconControllerForTest()($request)->getStatusCode())->toBe(204);
})->with(['HTTP_DNT', 'HTTP_SEC_GPC']);
