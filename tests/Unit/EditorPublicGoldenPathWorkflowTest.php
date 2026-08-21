<?php

declare(strict_types=1);

it('runs the editor to anonymous journey from exact checked-out sources', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = file_get_contents($root . '/.github/workflows/editor-public-golden-path.yml');
    $runner = file_get_contents($root . '/scripts/run-editor-public-golden-path.sh');

    expect($workflow)
        ->toContain('workflow_dispatch:')
        ->toContain("- 'packages/admin/**'")
        ->toContain("- 'packages/core/**'")
        ->toContain("- 'packages/frontend/**'")
        ->toContain("- 'packages/installer/**'")
        ->toContain("- 'packages/marketplace/**'")
        ->toContain('repository: capell-app/capell-packages')
        ->toContain('repository: capell-app/capell-screenshot-tools')
        ->toContain("github.event_name == 'pull_request'")
        ->toContain('github.event.pull_request.head.repo.full_name != github.repository')
        ->toContain('ACCESS_TOKEN')
        ->toContain('working-directory: repositories/capell')
        ->toContain('ref: main')
        ->toContain("php-version: '8.4'")
        ->toContain('CAPELL_GOLDEN_PATH_REQUIRE_CLEAN')
        ->toContain('npx playwright install --with-deps chromium')
        ->toContain('npm run test:editor-public-golden-path:contracts')
        ->toContain('if: failure()')
        ->toContain('retention-days: 7')
        ->not->toContain('playwright-report')
        ->not->toContain('storage/logs');

    expect(substr_count($workflow, 'persist-credentials: false'))->toBe(3);

    expect($runner)
        ->toContain('CAPELL_GOLDEN_PATH_REQUIRE_CLEAN')
        ->toContain('git -C "${repository_root}" rev-parse HEAD')
        ->toContain('CAPELL_PACKAGES_HEAD')
        ->toContain('git -C "${packages_root}" rev-parse HEAD')
        ->toContain('must match the checked-out companion source')
        ->toContain('capellTrackedCheckoutDirty:')
        ->toContain('capell-app/core:1.x-dev')
        ->toContain('capell-app/admin:1.x-dev')
        ->toContain('capell-app/frontend:1.x-dev')
        ->toContain('capell-app/installer:1.x-dev')
        ->toContain('capell-app/marketplace:1.x-dev')
        ->toContain('capell-app/discovery-foundation:1.x-dev')
        ->toContain('capell-app/html-cache:1.x-dev')
        ->toContain('capell-app/layout-builder:1.x-dev')
        ->toContain('capell-app/navigation:1.x-dev')
        ->toContain('capell-app/content-sections:1.x-dev')
        ->toContain('"1.x-dev"')
        ->toContain('laravel_skeleton_version')
        ->toContain('13.0.0')
        ->toContain('consumer-framework-version.txt')
        ->toContain('symlink: true')
        ->toContain('php artisan migrate --force --ansi')
        ->toContain('php artisan capell:install')
        ->toContain('--all-packages')
        ->toContain('--theme=default')
        ->toContain('php artisan filament:assets --ansi')
        ->toContain('CAPELL_HTML_CACHE=true')
        ->toContain('CAPELL_HTML_CACHE_ORIGIN_SWR=false')
        ->toContain('CAPELL_HTML_CACHE_INVALIDATION_MODE=instant')
        ->toContain('cd "${consumer_root}/public"')
        ->toContain('php -S "127.0.0.1:${server_port}"')
        ->toContain('Illuminate/Foundation/resources/server.php')
        ->toContain('curl --max-time 30')
        ->not->toContain('php artisan serve')
        ->toContain('--output="${consumer_root}/playwright-output"')
        ->toContain('--reporter=line');
});

it('pins the complete authoring lifecycle and every public safety checkpoint', function (): void {
    $root = dirname(__DIR__, 2);
    $spec = file_get_contents($root . '/tests/Browser/editor-public-golden-path.spec.js');

    foreach ([
        'sign in',
        'create draft',
        'draft stays private',
        'preview draft privately',
        'publish',
        'cached anonymous delivery',
        'republish changed content',
        'republish invalidates cached delivery',
        'restore original revision',
        'restore invalidates cached delivery',
        'sign out',
        'fresh anonymous recheck',
    ] as $step) {
        expect($spec)->toMatch('/diagnostics\\.step\\(\\s*\'' . preg_quote($step, '/') . '\'/');
    }

    expect(substr_count($spec, 'expectPublicPage({'))->toBe(6)
        ->and(substr_count($spec, 'expectDraftIsPrivate({'))->toBe(1)
        ->and(substr_count($spec, 'expectSafePublicHtml('))->toBe(1)
        ->and(substr_count($spec, "cache: 'MISS'"))->toBe(3)
        ->and(substr_count($spec, "cache: 'HIT'"))->toBe(3)
        ->and($spec)->toContain("'x-robots-tag'")
        ->toContain("toContain('private')")
        ->toContain("toContain('no-store')")
        ->toContain('forbiddenValues.push(editPath)')
        ->toContain("name: 'Roll back to here'")
        ->toContain("name: 'Restore this version'")
        ->toContain("name: 'Sign out'")
        ->toContain('const finalContext = await browser.newContext()');
});

it('retains only redacted browser and backend failure evidence', function (): void {
    $root = dirname(__DIR__, 2);
    $diagnostics = file_get_contents($root . '/tests/Browser/support/failure-evidence.js');
    $redactor = file_get_contents($root . '/tests/Browser/support/redact-log.js');
    $runner = file_get_contents($root . '/scripts/run-editor-public-golden-path.sh');

    expect($diagnostics)
        ->toContain("page.on('console'")
        ->toContain("page.on('pageerror'")
        ->toContain("page.on('requestfailed'")
        ->toContain("page.on('response'")
        ->toContain("'browser-diagnostics.json'")
        ->toContain("'journey-trace.json'")
        ->toContain('-redacted.html')
        ->toContain('-redacted.png')
        ->toContain('mask: [')
        ->toContain('safeUrl(url)')
        ->and($redactor)->toContain('CAPELL_DIAGNOSTIC_SECRETS')
        ->toContain('redactText(contents, secretValues)')
        ->and($runner)->toContain('backend-redacted.log')
        ->not->toContain('cat "${server_log}"')
        ->not->toContain('tail -');
});
