<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;

final class InstalledPanelRuntimeFinalRouteTest extends AdminTestCase
{
    private const string BODY = 'final route served';

    #[TestWith(['{tenant}.example.test', 'shop.example.test', 'shop.example.test', true])]
    #[TestWith(['shop.example.test', '{tenant}.example.test', 'shop.example.test', true])]
    #[TestWith(['{tenant}.example.test', '{store}.example.test', 'shop.example.test', true])]
    #[TestWith(['{tenant}.example.test', null, 'shop.example.test', true])]
    #[TestWith(['{tenant}.example.test', '{store}.other.test', 'shop.other.test', false])]
    #[TestWith(['{tenant}.example.test', 'shop.other.test', 'shop.other.test', false])]
    #[TestWith(['admin.{zone}.test', 'shop.{region}.test', 'shop.example.test', false])]
    #[TestWith(['foo{tenant}.test', '{store}bar.test', 'foobar.test', true])]
    #[TestWith(['SHOP.example.test', 'shop.example.test', 'shop.example.test', true])]
    public function test_final_host_constraints_determine_panel_path_overlap(string $panelDomain, ?string $routeDomain, string $host, bool $refused): void
    {
        $runtime = resolve(InstalledPanelRuntime::class);
        $route = Route::get('/tenant-admin/help', static fn (): string => self::BODY);
        if ($routeDomain !== null) {
            $route->domain($routeDomain);
        }

        $runtime->extend(Panel::make()->id('tenant-panel')->path('tenant-admin')->domains([$panelDomain]));

        $response = $this->get('http://' . $host . '/tenant-admin/help');
        if ($refused) {
            $response->assertStatus(503)->assertDontSee(self::BODY);
        } else {
            $response->assertOk()->assertSee(self::BODY);
        }

        $this->assertSame($refused, $runtime->isRouteRefused($route));
    }

    public function test_a_concrete_host_route_owned_by_a_parameterised_panel_runs_its_security(): void
    {
        $runtime = resolve(InstalledPanelRuntime::class);
        $runtime->extend(Panel::make()->id('tenant-panel')->path('tenant-admin')->domains(['{tenant}.example.test'])->authMiddleware([PackageSecurityDenyMiddleware::class]));
        AdminRouteProvenance::panel('tenant-panel', static fn () => Route::domain('shop.example.test')->get('/tenant-admin/owned', static fn (): string => self::BODY));

        $this->get('http://shop.example.test/tenant-admin/owned')->assertForbidden()->assertSee(PackageSecurityDenyMiddleware::BODY)->assertDontSee(self::BODY);
    }

    public function test_an_owned_host_pattern_cannot_answer_hosts_outside_its_panel(): void
    {
        resolve(InstalledPanelRuntime::class)->extend(Panel::make()->id('tenant-panel')->path('tenant-admin')->domains(['shop.example.test']));
        AdminRouteProvenance::panel('tenant-panel', static fn () => Route::domain('{tenant}.example.test')->get('/tenant-admin/broad', static fn (): string => self::BODY));

        $this->get('http://other.example.test/tenant-admin/broad')->assertStatus(503)->assertDontSee(self::BODY);
    }

    public function test_host_parameter_requirements_can_prove_a_concrete_panel_disjoint(): void
    {
        $route = Route::domain('{tenant}.example.test')->get('/tenant-admin/constrained', static fn (): string => self::BODY)->where('tenant', 'shop');
        $runtime = resolve(InstalledPanelRuntime::class);
        $runtime->extend(Panel::make()->id('tenant-panel')->path('tenant-admin')->domains(['admin.example.test']));

        $this->get('http://shop.example.test/tenant-admin/constrained')->assertOk()->assertSee(self::BODY);
        $this->assertFalse($runtime->isRouteRefused($route));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_fluent_domain_configuration_is_evaluated_from_the_finished_route(bool $synchronise): void
    {
        config()->set('capell-admin.domain', 'admin.example.test');
        Filament::getPanel('admin')->domains(['admin.example.test']);
        $runtime = resolve(InstalledPanelRuntime::class);
        $panel = Panel::make()->id('domain-panel')->path('admin')->domains(['admin.example.test']);
        $runtime->extend($panel);
        $route = Route::get('/admin/fluent-help', static fn (): string => self::BODY);
        $this->assertFalse($runtime->isRouteRefused($route));
        $route->domain('shop.example.test');
        if ($synchronise) {
            $runtime->extend($panel);
        }

        $this->get('http://shop.example.test/admin/fluent-help')->assertOk()->assertSee(self::BODY);
        $this->assertFalse($runtime->isRouteRefused($route));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_an_earlier_refusal_is_dropped_when_the_final_domain_is_disjoint(bool $synchronise): void
    {
        $runtime = resolve(InstalledPanelRuntime::class);
        $panel = Panel::make()->id('domain-panel')->path('tenant-admin')->domains(['admin.example.test']);
        $route = Route::get('/tenant-admin/changed-help', static fn (): string => self::BODY);
        $runtime->extend($panel);
        $this->assertTrue($runtime->isRouteRefused($route));
        $route->domain('shop.example.test');
        if ($synchronise) {
            $runtime->extend($panel);
            $this->assertFalse($runtime->isRouteRefused($route));
        }

        $this->get('http://shop.example.test/tenant-admin/changed-help')->assertOk()->assertSee(self::BODY);
        $this->assertFalse($runtime->isRouteRefused($route));
    }

    #[TestWith([null, false])]
    #[TestWith(['admin.example.test', false])]
    #[TestWith([null, true])]
    #[TestWith(['admin.example.test', true])]
    public function test_removing_or_matching_a_domain_refuses_the_finished_route(?string $domain, bool $synchronise): void
    {
        $runtime = resolve(InstalledPanelRuntime::class);
        $panel = Panel::make()->id('domain-panel')->path('tenant-admin')->domains(['admin.example.test']);
        $route = Route::domain('shop.example.test')->get('/tenant-admin/changed-domain', static fn (): string => self::BODY);
        $runtime->extend($panel);
        $this->assertFalse($runtime->isRouteRefused($route));
        $route->domain($domain ?? '');
        if ($synchronise) {
            $runtime->extend($panel);
            $this->assertTrue($runtime->isRouteRefused($route));
        }

        $this->get('http://admin.example.test/tenant-admin/changed-domain')->assertStatus(503)->assertDontSee(self::BODY);
        $this->assertTrue($runtime->isRouteRefused($route));
    }
}
