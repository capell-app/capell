<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\DomainCapellPanelProvider;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * One process boots the application and writes the route cache; a second, fresh process boots from that
 * cache alone and serves requests. Every route keeps the ownership recorded when it was registered,
 * including admin routes registered before any service provider and a panel served on its own domain.
 */
final class InstalledPanelRuntimeFreshCachedBootTest extends AdminTestCase
{
    public function test_a_fresh_application_booted_from_the_route_cache_serves_each_route_as_registered(): void
    {
        $file = storage_path('framework/testing-fresh-routes-' . bin2hex(random_bytes(6)) . '.php');
        File::ensureDirectoryExists(dirname($file));

        try {
            $this->runFresh('cache', $file);
            $cache = (string) File::get($file);
            $answers = $this->runFresh('serve', $file);
        } finally {
            File::delete($file);
        }

        $this->assertTrue($answers['routes_cached']);
        // Admin code registered before the admin package recorded anything is refused, not served as public.
        $this->assertSame(503, $answers['early_admin']['status']);
        // An admin plugin's endpoint registered that early is still the admin panel's, not a public route.
        $this->assertStringEndsWith('/admin/login', (string) $answers['early_plugin']['location']);
        // The token-keyed internal preview is public: it answers for itself, without a token, rather than being refused.
        $this->assertSame(404, $answers['peek_preview']['status']);
        // Capell's admin routes and the icon picker are the admin panel's and send guests to sign in.
        $this->assertStringEndsWith('/admin/login', (string) $answers['icon_picker']['location']);
        $this->assertStringEndsWith('/admin/login', (string) $answers['admin_asset']['location']);
        // The domain panel serves its routes on its domain only.
        $this->assertSame('http://' . DomainCapellPanelProvider::DOMAIN . '/desk/login', $answers['desk']['location']);
        $this->assertSame(404, $answers['desk_elsewhere']['status']);
        // The ownership travels in the cache itself, so the serving process never re-derives it.
        $this->assertStringContainsString("'capell_ownership'", $cache);
    }

    /** @return array<string, mixed> */
    private function runFresh(string $mode, string $file): array
    {
        $root = dirname(__DIR__, 6);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . '/Fixtures/RouteCache/fresh-application.php', $mode, $file], $root);
        $process->setTimeout(300)->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());

        $answers = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($answers);

        return $answers;
    }
}
