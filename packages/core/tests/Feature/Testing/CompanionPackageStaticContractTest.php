<?php

declare(strict_types=1);

use Capell\Core\Testing\Assertions\AssertsGuardedOutboundHttp;
use Capell\Core\Testing\Assertions\AssertsPublicViewSafety;
use Capell\Core\Testing\Contracts\CompanionPackageContractSuite;
use Capell\Core\Testing\Data\CompanionPackageContractData;
use Illuminate\Support\Facades\Http;

/**
 * A throwaway package on disk: the given files under its root, plus a capell.json holding the given security section.
 *
 * @param  array<string, string>  $files  path relative to the package root => contents
 * @param  array<string, mixed>  $security
 */
function staticContractPackage(array $files = [], array $security = []): string
{
    $root = sys_get_temp_dir() . '/capell-static-contract-' . bin2hex(random_bytes(6));

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($root . '/' . $path))) {
            mkdir(dirname($root . '/' . $path), 0777, true);
        }

        file_put_contents($root . '/' . $path, $contents);
    }

    if (! is_dir($root)) {
        mkdir($root, 0777, true);
    }

    file_put_contents($root . '/capell.json', json_encode(['security' => $security], JSON_THROW_ON_ERROR));

    return $root;
}

function staticContractClass(string $class, string $body, string $imports = ''): string
{
    return "<?php\n\nnamespace Fixture\\Package;\n\n{$imports}\n\nfinal class {$class}\n{\n    public function handle(string \$url): mixed\n    {\n{$body}\n    }\n}\n";
}

/** @param  list<string>  $clients */
function declaredClients(array $clients, array $manualClients = []): array
{
    return ['externalHttpClients' => ['clients' => $clients, 'manualClients' => $manualClients]];
}

function runOutboundHttp(string $root): void
{
    AssertsGuardedOutboundHttp::run($root, $root . '/capell.json');
}

it('fails when a class opens outbound connections without being declared', function (): void {
    $root = staticContractPackage([
        'src/Geocoder.php' => staticContractClass('Geocoder', '        return Http::timeout(5)->get($url);', 'use Illuminate\Support\Facades\Http;'),
    ]);

    expect(fn () => runOutboundHttp($root))
        ->toThrow(AssertionError::class, 'Fixture\Package\Geocoder opens outbound connections but is not listed');
});

it('passes when every outbound client is declared and sets a timeout', function (): void {
    $root = staticContractPackage([
        'src/Geocoder.php' => staticContractClass('Geocoder', '        return Http::timeout(5)->get($url);', 'use Illuminate\Support\Facades\Http;'),
        'src/Plain.php' => staticContractClass('Plain', '        return strtoupper($url);'),
    ], declaredClients(['Fixture\Package\Geocoder']));

    runOutboundHttp($root);

    expect(true)->toBeTrue();
});

it('detects grouped, aliased and function imports, curl calls and remote streams', function (string $imports, string $body): void {
    $root = staticContractPackage(['src/Client.php' => staticContractClass('Client', $body, $imports)]);

    expect(fn () => runOutboundHttp($root))->toThrow(AssertionError::class, 'Fixture\Package\Client opens outbound connections');
})->with([
    'grouped alias' => ['use GuzzleHttp\{Client as Transport, RequestOptions};', '        return new Transport;'],
    'curl function' => ['', '        return curl_init($url);'],
    'remote stream literal' => ['', "        return file_get_contents('https://example.test/feed');"],
    'socket' => ['', "        return fsockopen('example.test', 80);"],
    'guarded client' => ['use Capell\Core\Support\Http\OutboundHttp;', '        return OutboundHttp::guarded()->get($url);'],
]);

it('ignores HTTP class names that appear only in comments and strings', function (): void {
    $root = staticContractPackage([
        'src/Docs.php' => staticContractClass('Docs', "        // GuzzleHttp\\Client is not used here.\n        return 'Illuminate\\Http\\Client\\PendingRequest';"),
    ]);

    runOutboundHttp($root);

    expect(true)->toBeTrue();
});

it('fails for a declared client that no longer opens a connection unless it is a manual SDK wrapper', function (): void {
    $stale = staticContractPackage(['src/Plain.php' => staticContractClass('Plain', '        return $url;')], declaredClients(['Fixture\Package\Plain']));
    $manual = staticContractPackage(['src/Plain.php' => staticContractClass('Plain', '        return $url;')], declaredClients(['Fixture\Package\Plain'], ['Fixture\Package\Plain']));

    expect(fn () => runOutboundHttp($stale))->toThrow(AssertionError::class, 'Fixture\Package\Plain is listed in security.externalHttpClients.clients but opens no outbound connection');

    runOutboundHttp($manual);
});

it('requires a timeout on facade requests but not on the guarded client', function (): void {
    $facade = staticContractPackage([
        'src/Fetcher.php' => staticContractClass('Fetcher', '        return Http::get($url);', 'use Illuminate\Support\Facades\Http;'),
    ], declaredClients(['Fixture\Package\Fetcher']));
    $guarded = staticContractPackage([
        'src/Fetcher.php' => staticContractClass('Fetcher', '        return OutboundHttp::guarded()->get($url);', 'use Capell\Core\Support\Http\OutboundHttp;'),
    ], declaredClients(['Fixture\Package\Fetcher']));

    expect(fn () => runOutboundHttp($facade))->toThrow(AssertionError::class, 'src/Fetcher.php:11 sends a request without a timeout');

    runOutboundHttp($guarded);
});

it('requires a reviewed declaration for a stream whose target cannot be classified', function (): void {
    $unreviewed = staticContractPackage(['src/Reader.php' => staticContractClass('Reader', '        return file_get_contents($url);')]);
    $local = staticContractPackage(['src/Reader.php' => staticContractClass('Reader', "        // capell-security-scan: local-stream reads the exported archive\n        return file_get_contents(\$url);")]);
    $guardedByIsFile = staticContractPackage(['src/Reader.php' => staticContractClass('Reader', '        return is_file($url) ? file_get_contents($url) : null;')]);

    expect(fn () => runOutboundHttp($unreviewed))->toThrow(AssertionError::class, 'src/Reader.php:11: file_get_contents() opens a target that is neither a URL nor evidently local');

    runOutboundHttp($local);
    runOutboundHttp($guardedByIsFile);
});

it('fails public views that hold authoring state, secrets, queries or cache access', function (string $blade, string $pattern): void {
    $root = staticContractPackage(['resources/views/components/card.blade.php' => $blade]);

    expect(fn () => AssertsPublicViewSafety::run($root))
        ->toThrow(AssertionError::class, 'resources/views/components/card.blade.php contains ' . $pattern);
})->with([
    'query' => ['@foreach (\App\Models\Post::query()->get() as $post) @endforeach', '::query('],
    'database' => ['{{ DB::table("posts")->count() }}', 'DB::'],
    'cache helper' => ['{{ cache("menu") }}', 'cache()'],
    'signed editor URL' => ['<a href="{{ $signed_editor }}">Edit</a>', 'signed_editor'],
    'authoring marker' => ['<div data-capell-authoring="region"></div>', 'data-capell-authoring'],
    'secret' => ['<script>const key = "{{ $api_key }}";</script>', 'api_key'],
]);

it('skips admin views by directory and has no per-view exemption', function (): void {
    $root = staticContractPackage([
        'resources/views/filament/pages/settings.blade.php' => '{{ DB::table("settings")->count() }}',
        'resources/views/components/card.blade.php' => '<article>{{ $title }}</article>',
    ]);

    AssertsPublicViewSafety::run($root);

    $declared = staticContractPackage([
        'resources/views/livewire/tools/sitemap.blade.php' => "{{-- capell-security-scan: admin-view rendered in the admin tool --}}\n{{ DB::table('pages')->count() }}",
    ]);

    expect(fn () => AssertsPublicViewSafety::run($declared))->toThrow(AssertionError::class, 'resources/views/livewire/tools/sitemap.blade.php contains DB::');
});

it('requires a timeout however the facade is named and wherever the request is built', function (string $imports, string $body): void {
    $root = staticContractPackage(['src/Fetcher.php' => staticContractClass('Fetcher', $body, $imports)], declaredClients(['Fixture\Package\Fetcher']));

    expect(fn () => runOutboundHttp($root))->toThrow(AssertionError::class, 'src/Fetcher.php:11 sends a request without a timeout');
})->with([
    'fully qualified' => ['', '        return \\' . Http::class . '::get($url);'],
    'aliased import' => ['use Illuminate\Support\Facades\Http as Client;', '        return Client::post($url);'],
    'builder kept in a variable' => ['use Illuminate\Support\Facades\Http;', "        \$client = Http::withHeaders(['Accept' => 'application/json']);\n        return \$client->get(\$url);"],
]);

it('accepts a timeout set through the timeout method or a request option', function (string $body): void {
    $root = staticContractPackage([
        'src/Fetcher.php' => staticContractClass('Fetcher', $body, 'use Illuminate\Support\Facades\Http;'),
    ], declaredClients(['Fixture\Package\Fetcher']));

    runOutboundHttp($root);

    expect(true)->toBeTrue();
})->with([
    'timeout method' => ['        return Http::acceptJson()->timeout(5)->get($url);'],
    'static timeout' => ['        return Http::timeout(5)->get($url);'],
    'request option' => ["        return Http::withOptions(['timeout' => 5])->get(\$url);"],
]);

it('requires a timeout on a Playwright process', function (): void {
    $body = "        return new Process(['node', \$this->scriptPath('playwright/capture.mjs'), \$url]);";
    $untimed = staticContractPackage(['src/Capture.php' => staticContractClass('Capture', $body)], declaredClients(['Fixture\Package\Capture']));
    $timed = staticContractPackage(['src/Capture.php' => staticContractClass('Capture', $body . "\n        // ->setTimeout(60) is applied by the caller's ->setTimeout(60)")], declaredClients(['Fixture\Package\Capture']));

    expect(fn () => runOutboundHttp($untimed))->toThrow(AssertionError::class, 'src/Capture.php:11 sends a request without a timeout');

    runOutboundHttp($timed);
});

it('treats only an un-negated is_file or file_exists check as proof of a local stream', function (): void {
    $negated = staticContractPackage(['src/Reader.php' => staticContractClass('Reader', '        return ! is_file($url) ? null : file_get_contents($url);')]);

    expect(fn () => runOutboundHttp($negated))->toThrow(AssertionError::class, 'file_get_contents() opens a target that is neither a URL nor evidently local');
});

it('classifies strings built from variables by their fixed start', function (string $argument, bool $local): void {
    $root = staticContractPackage(['src/Reader.php' => staticContractClass('Reader', sprintf('        return file_get_contents(%s);', $argument))]);
    $check = fn () => runOutboundHttp($root);

    $local ? $check() : expect($check)->toThrow(AssertionError::class, 'neither a URL nor evidently local');

    expect(true)->toBeTrue();
})->with([
    'interpolated variable' => ['"$url"', false],
    'interpolated with suffix' => ['"{$url}/feed"', false],
    'empty literal concatenation' => ["'' . \$url", false],
    'absolute path prefix' => ["'/var/data/' . \$url", true],
    'interpolated absolute path' => ['"/var/data/{$url}"', true],
]);

it('lists traits and enums that open connections, and ignores class names in prose', function (): void {
    $root = staticContractPackage([
        'src/Concerns/Fetches.php' => "<?php\n\nnamespace Fixture\\Package\\Concerns;\n\nuse GuzzleHttp\\Client;\n\ntrait Fetches\n{\n    public function client(): Client { return new Client; }\n}\n",
        'src/Documented.php' => "<?php\n\n/**\n * This class opens nothing.\n */\n\nnamespace Fixture\\Package;\n\nuse GuzzleHttp\\Client;\n\nfinal class Documented\n{\n    public function client(): Client { return new Client; }\n}\n",
    ]);

    expect(fn () => runOutboundHttp($root))
        ->toThrow(AssertionError::class, 'Fixture\Package\Concerns\Fetches opens outbound connections')
        ->and(fn () => runOutboundHttp($root))
        ->toThrow(AssertionError::class, 'Fixture\Package\Documented opens outbound connections');
});

function suiteContractFor(string $root): CompanionPackageContractData
{
    return new CompanionPackageContractData(
        packageRoot: $root,
        manifestPath: dirname(__DIR__, 5) . '/packages/core/capell.json',
        providerClass: null,
    );
}

it('runs the static checks in the suite unless a complete decision record opts the package out', function (): void {
    $files = ['src/Fetcher.php' => staticContractClass('Fetcher', '        return OutboundHttp::guarded()->get($url);', 'use Capell\Core\Support\Http\OutboundHttp;')];
    $enforced = staticContractPackage($files);
    $optedOut = staticContractPackage([
        ...$files,
        'docs/decisions/contract-opt-out-security.outbound-http.md' => "# Outbound HTTP opt-out\n\n## Decision\n\nThe fetcher is a development-only tool.\n\n## Reason\n\nIt never ships in a production bundle.\n",
    ]);

    expect(fn () => (new CompanionPackageContractSuite)->run(suiteContractFor($enforced)))
        ->toThrow(AssertionError::class, '[security.outbound-http]');

    (new CompanionPackageContractSuite)->run(suiteContractFor($optedOut));
});

it('requires privacy contributors in the suite for personal data unless a decision record opts the package out', function (): void {
    $files = ['database/migrations/2026_01_01_000000_create_contacts_table.php' => "<?php\n\nSchema::create('contacts', function (Blueprint \$table): void {\n    \$table->string('email');\n});\n"];
    $enforced = staticContractPackage($files);
    $optedOut = staticContractPackage([
        ...$files,
        'docs/decisions/contract-opt-out-privacy.personal-data.md' => "# Privacy opt-out\n\n## Decision\n\nNo exporter or eraser.\n\n## Reason\n\nThe addresses are staff-entered system senders, not people.\n",
    ]);

    expect(fn () => (new CompanionPackageContractSuite)->run(suiteContractFor($enforced)))
        ->toThrow(AssertionError::class, '[privacy.personal-data]');

    (new CompanionPackageContractSuite)->run(suiteContractFor($optedOut));
});

it('rejects incomplete decision records and records for checks that cannot be opted out', function (string $file, string $contents, string $message): void {
    $root = staticContractPackage(['docs/decisions/' . $file => $contents]);

    expect(fn () => (new CompanionPackageContractSuite)->run(suiteContractFor($root)))
        ->toThrow(AssertionError::class, $message);
})->with([
    'missing reason' => ['contract-opt-out-public-output.views.md', "## Decision\n\nSkip it.\n\n## Reason\n\n", 'needs a "## Reason" section with text'],
    'missing decision' => ['contract-opt-out-public-output.views.md', "## Reason\n\nLegacy views.\n", 'needs a "## Decision" section with text'],
    'mandatory check' => ['contract-opt-out-manifest.valid.md', "## Decision\n\nSkip.\n\n## Reason\n\nNone.\n", '[manifest.valid] cannot be opted out of'],
]);

it('derives a package contract from its manifest and migrations', function (): void {
    $root = staticContractPackage([
        'database/migrations/2026_01_01_000000_create_cards.php' => '<?php return null;',
        'database/migrations/2026_01_02_000000_add_title.php' => '<?php return null;',
    ]);
    file_put_contents($root . '/capell.json', json_encode([
        'providers' => [
            'metadata' => ['Fixture\Package\MetadataProvider'],
            'runtime' => ['Fixture\Package\RuntimeProvider', 'Fixture\Package\Other'],
        ],
    ], JSON_THROW_ON_ERROR));

    $contract = CompanionPackageContractData::forPackage($root . '/');

    expect($contract->packageRoot)->toBe($root)
        ->and($contract->manifestPath)->toBe($root . '/capell.json')
        ->and($contract->providerClass)->toBe('Fixture\Package\RuntimeProvider')
        ->and($contract->migrations)->toBe([
            'database/migrations/2026_01_01_000000_create_cards.php',
            'database/migrations/2026_01_02_000000_add_title.php',
        ]);
});
