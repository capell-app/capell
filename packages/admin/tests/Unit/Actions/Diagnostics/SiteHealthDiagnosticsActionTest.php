<?php

declare(strict_types=1);

use Capell\Admin\Actions\Diagnostics\BuildOptimizerReadinessDiagnosticsAction;
use Capell\Admin\Actions\Diagnostics\BuildProductionEnvironmentDiagnosticsAction;
use Capell\Admin\Actions\Diagnostics\BuildSiteHealthReportAction;
use Capell\Admin\Contracts\Diagnostics\SiteAwareSiteHealthReportExtender;
use Capell\Admin\Contracts\Diagnostics\SiteHealthReportExtender;
use Capell\Admin\Data\Diagnostics\DiagnosticCheckData;
use Capell\Admin\Data\Diagnostics\DiagnosticSectionData;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

it('builds environment readiness checks', function (): void {
    config()->set('app.url', 'https://example.test');
    config()->set('cache.default', 'file');
    config()->set('queue.default', 'database');
    config()->set('trustedproxy.proxies', ['10.0.0.1']);

    $checks = new Collection(BuildProductionEnvironmentDiagnosticsAction::run());

    expect($checks->pluck('label')->all())->toContain(
        'App URL',
        'Application key',
        'Application environment',
        'Debug mode',
        'Cache store',
        'Database cache table',
        'Queue connection',
        'Queue jobs table',
        'Scheduler',
        'Failed jobs table',
        'Session driver',
        'Trusted proxies',
        'Storage path',
        'Bootstrap cache path',
    );
});

it('flags unsafe Laravel environment settings for production hosting', function (): void {
    config()->set('app.key', '');
    config()->set('app.env', 'local');
    config()->set('app.debug', true);
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.table', 'missing_cache_table');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.table', 'missing_jobs_table');
    config()->set('session.driver', 'array');

    Schema::dropIfExists('missing_cache_table');
    Schema::dropIfExists('missing_jobs_table');
    resolve(RuntimeSchemaState::class)->flush();

    $checks = new Collection(BuildProductionEnvironmentDiagnosticsAction::run());

    expect($checks->firstWhere('label', 'Application key'))->status->toBe('red')
        ->and($checks->firstWhere('label', 'Application environment'))->status->toBe('amber')
        ->and($checks->firstWhere('label', 'Debug mode'))->status->toBe('red')
        ->and($checks->firstWhere('label', 'Database cache table'))->status->toBe('red')
        ->and($checks->firstWhere('label', 'Queue jobs table'))->status->toBe('red')
        ->and($checks->firstWhere('label', 'Session driver'))->status->toBe('amber');
});

it('passes database backed cache and queue table checks when tables exist', function (): void {
    config()->set('cache.default', 'database');
    config()->set('cache.stores.database.table', 'diagnostics_cache');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.table', 'diagnostics_jobs');

    Schema::dropIfExists('diagnostics_cache');
    Schema::dropIfExists('diagnostics_jobs');
    Schema::create('diagnostics_cache', function (Blueprint $table): void {
        $table->string('key')->primary();
    });
    Schema::create('diagnostics_jobs', function (Blueprint $table): void {
        $table->id();
    });
    resolve(RuntimeSchemaState::class)->flush();

    $checks = new Collection(BuildProductionEnvironmentDiagnosticsAction::run());

    expect($checks->firstWhere('label', 'Database cache table'))->status->toBe('green')
        ->and($checks->firstWhere('label', 'Queue jobs table'))->status->toBe('green');
});

it('checks optimizer runtime availability', function (): void {
    $nodeProcess = Mockery::mock(Process::class);
    $nodeProcess->shouldReceive('run')->once();
    $nodeProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $nodeProcess->shouldReceive('getOutput')->once()->andReturn("v22.0.0\n");

    $playwrightProcess = Mockery::mock(Process::class);
    $playwrightProcess->shouldReceive('run')->once();
    $playwrightProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $playwrightProcess->shouldReceive('getOutput')->once()->andReturn("Version 1.52.0\n");

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->with(['node', '--version'], base_path())->once()->andReturn($nodeProcess);
    $factory->shouldReceive('make')->with(['npx', 'playwright', '--version'], base_path())->once()->andReturn($playwrightProcess);
    app()->instance(ProcessFactoryInterface::class, $factory);

    $checks = new Collection(BuildOptimizerReadinessDiagnosticsAction::run());

    expect($checks->firstWhere('label', 'Node runtime'))
        ->status->toBe('green')
        ->detail->toBe('v22.0.0')
        ->and($checks->firstWhere('label', 'Playwright runtime'))
        ->status->toBe('green')
        ->detail->toBe('Version 1.52.0');
});

it('reports optimizer runtime failures latest artifacts and failed critical css jobs', function (): void {
    $optimizerPath = storage_path('app/capell/frontend-optimizer');
    File::ensureDirectoryExists($optimizerPath);
    File::put($optimizerPath . '/profile.css', 'body { color: #111; }');
    touch($optimizerPath . '/profile.css', Date::now()->subSeconds(10)->getTimestamp());

    $nodeProcess = Mockery::mock(Process::class);
    $nodeProcess->shouldReceive('run')->once();
    $nodeProcess->shouldReceive('isSuccessful')->once()->andReturnFalse();
    $nodeProcess->shouldReceive('getOutput')->once()->andReturn('');
    $nodeProcess->shouldReceive('getErrorOutput')->once()->andReturn("node missing\n");

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->with(['node', '--version'], base_path())->once()->andReturn($nodeProcess);
    $factory->shouldReceive('make')->with(['npx', 'playwright', '--version'], base_path())->once()->andThrow(new RuntimeException('playwright unavailable'));
    app()->instance(ProcessFactoryInterface::class, $factory);

    config()->set('queue.failed.table', 'failed_jobs_optimizer_test');
    Schema::dropIfExists('failed_jobs_optimizer_test');
    Schema::create('failed_jobs_optimizer_test', function (Blueprint $table): void {
        $table->id();
        $table->longText('payload');
        $table->longText('exception')->nullable();
    });
    DB::table('failed_jobs_optimizer_test')->insert([
        'payload' => '{"displayName":"GenerateCriticalCss"}',
        'exception' => 'CriticalCss generation failed',
    ]);

    $checks = new Collection(BuildOptimizerReadinessDiagnosticsAction::run());

    expect($checks->firstWhere('label', 'Node runtime'))
        ->status->toBe('amber')
        ->detail->toBe('node missing')
        ->and($checks->firstWhere('label', 'Playwright runtime'))
        ->status->toBe('amber')
        ->detail->toBe('playwright unavailable')
        ->and($checks->firstWhere('label', 'Latest optimizer profile'))
        ->status->toBe('green')
        ->path->toEndWith('profile.css')
        ->and($checks->firstWhere('label', 'Failed critical CSS jobs'))
        ->status->toBe('red')
        ->detail->toContain('1');
});

it('reports unavailable failed job tables for optimizer diagnostics', function (): void {
    $nodeProcess = Mockery::mock(Process::class);
    $nodeProcess->shouldReceive('run')->once();
    $nodeProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $nodeProcess->shouldReceive('getOutput')->once()->andReturn("v22.0.0\n");

    $playwrightProcess = Mockery::mock(Process::class);
    $playwrightProcess->shouldReceive('run')->once();
    $playwrightProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $playwrightProcess->shouldReceive('getOutput')->once()->andReturn("Version 1.52.0\n");

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->with(['node', '--version'], base_path())->once()->andReturn($nodeProcess);
    $factory->shouldReceive('make')->with(['npx', 'playwright', '--version'], base_path())->once()->andReturn($playwrightProcess);
    app()->instance(ProcessFactoryInterface::class, $factory);

    config()->set('queue.failed.table', 'missing_optimizer_failed_jobs');

    $checks = new Collection(BuildOptimizerReadinessDiagnosticsAction::run());

    expect($checks->firstWhere('label', 'Failed critical CSS jobs'))
        ->status->toBe('amber')
        ->detail->toContain('missing_optimizer_failed_jobs');
});

it('builds the aggregate site health report', function (): void {
    $nodeProcess = Mockery::mock(Process::class);
    $nodeProcess->shouldReceive('run')->once();
    $nodeProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $nodeProcess->shouldReceive('getOutput')->once()->andReturn("v22.0.0\n");

    $playwrightProcess = Mockery::mock(Process::class);
    $playwrightProcess->shouldReceive('run')->once();
    $playwrightProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $playwrightProcess->shouldReceive('getOutput')->once()->andReturn("Version 1.52.0\n");

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->with(['node', '--version'], base_path())->once()->andReturn($nodeProcess);
    $factory->shouldReceive('make')->with(['npx', 'playwright', '--version'], base_path())->once()->andReturn($playwrightProcess);
    app()->instance(ProcessFactoryInterface::class, $factory);

    $report = BuildSiteHealthReportAction::run();

    expect($report->optimizerReadiness)->not->toBeEmpty()
        ->and($report->environment)->not->toBeEmpty();
});

it('passes selected site ids only to site aware health report extenders', function (): void {
    app()->bind('tests.legacy-site-health-extender', fn (): SiteHealthReportExtender => new class implements SiteHealthReportExtender
    {
        /** @return list<DiagnosticSectionData> */
        public function sections(): array
        {
            return [
                new DiagnosticSectionData(
                    label: 'Legacy diagnostics',
                    checks: [
                        new DiagnosticCheckData(
                            status: 'green',
                            label: 'Legacy extender',
                            detail: 'No selected site required',
                        ),
                    ],
                ),
            ];
        }
    });
    app()->tag('tests.legacy-site-health-extender', SiteHealthReportExtender::TAG);

    app()->bind('tests.site-aware-site-health-extender', fn (): SiteHealthReportExtender => new class implements SiteAwareSiteHealthReportExtender
    {
        /** @return list<DiagnosticSectionData> */
        public function sections(): array
        {
            return $this->sectionsForSite(null);
        }

        /** @return list<DiagnosticSectionData> */
        public function sectionsForSite(?int $siteId): array
        {
            return [
                new DiagnosticSectionData(
                    label: 'Site aware diagnostics',
                    checks: [
                        new DiagnosticCheckData(
                            status: 'green',
                            label: 'Selected site',
                            detail: 'Site ' . $siteId,
                        ),
                    ],
                ),
            ];
        }
    });
    app()->tag('tests.site-aware-site-health-extender', SiteHealthReportExtender::TAG);

    $report = BuildSiteHealthReportAction::run(123);

    $extraSections = new Collection($report->extraSections);

    expect($extraSections->pluck('label')->all())->toContain(
        'Legacy diagnostics',
        'Site aware diagnostics',
    )
        ->and($extraSections->flatMap->checks->pluck('detail')->all())->toContain('Site 123');
});
