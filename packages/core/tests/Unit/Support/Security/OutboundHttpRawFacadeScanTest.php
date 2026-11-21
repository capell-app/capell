<?php

declare(strict_types=1);

use Capell\Core\Support\Security\OutboundHttpSourceScanner;
use Illuminate\Support\Facades\File;

/** @param  array<string, string>  $files  path under src => contents */
function rawFacadeFixturePackage(array $files): string
{
    $root = sys_get_temp_dir() . '/capell-raw-http-' . bin2hex(random_bytes(6));

    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($root . '/src/' . $path));
        File::put($root . '/src/' . $path, $contents);
    }

    return $root;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/capell-raw-http-*') ?: [] as $root) {
        File::deleteDirectory($root);
    }
});

it('flags a raw Http facade request to a configured address', function (): void {
    $root = rawFacadeFixturePackage(['Notify.php' => <<<'PHP'
        <?php

        namespace Acme\Notify;

        use Illuminate\Support\Facades\Http;

        final class Notify
        {
            public function send(): void
            {
                Http::timeout(5)->post(config('acme.webhook_url'), []);
            }
        }
        PHP]);

    expect((new OutboundHttpSourceScanner)->rawFacadeRequests($root))->toBe(['src/Notify.php:11']);
});

it('accepts a raw request declared as reviewed and ignores client configuration calls', function (): void {
    $root = rawFacadeFixturePackage(['Purge.php' => <<<'PHP'
        <?php

        namespace Acme\Purge;

        use Illuminate\Support\Facades\Http;

        final class Purge
        {
            public function send(): void
            {
                Http::fake();
                // capell-security-scan: raw-http fixed provider host.
                Http::timeout(5)->post('https://api.provider.example/purge', []);
            }
        }
        PHP]);

    expect((new OutboundHttpSourceScanner)->rawFacadeRequests($root))->toBe([]);
});

it('distinguishes standalone facade management from an unbounded request', function (): void {
    $root = rawFacadeFixturePackage(['Notify.php' => <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Http;

        $original = Http::getFacadeRoot();
        Http::swap($isolated);
        try {
            Http::get('https://api.provider.example/status');
        } finally {
            Http::swap($original);
        }
        PHP]);

    $scanner = new OutboundHttpSourceScanner;

    expect($scanner->requestsWithoutTimeouts($root))->toBe(['src/Notify.php:8'])
        ->and($scanner->rawFacadeRequests($root))->toBe(['src/Notify.php:8']);
});

it('keeps every raw Http facade request in the foundation packages declared', function (string $package): void {
    $root = dirname(__DIR__, 6) . '/packages/' . $package;

    expect((new OutboundHttpSourceScanner)->rawFacadeRequests($root))->toBe([]);
})->with(['core', 'admin', 'frontend', 'installer', 'marketplace']);
