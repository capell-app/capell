<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\SecondCapellPanelProvider;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Override;

/**
 * Filament's global export download is served by the panel the export was started in, under that
 * panel's whole stack and package security, rather than by one fallback panel for every panel.
 */
final class InstalledPanelRuntimeTransferOriginTest extends AdminTestCase
{
    public function test_an_export_started_in_a_panel_downloads_under_that_panel(): void
    {
        [$user, $url] = $this->exportFrom('admin');

        $this->actingAs($user)->followingRedirects()->get($url)
            ->assertOk()
            ->assertDownload('runtime-export.csv');
    }

    public function test_an_export_started_in_another_panel_is_held_to_that_panels_security(): void
    {
        [$user, $url] = $this->exportFrom('studio');

        // The default panel would admit this user; the originating panel's package security refuses them.
        $this->actingAs($user)->followingRedirects()->get($url)
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY);
    }

    public function test_a_guest_is_sent_to_sign_in_to_the_originating_panel(): void
    {
        [, $url] = $this->exportFrom('studio');

        $download = $this->get($url)->assertRedirectContains('/studio/')->headers->get('Location');

        $this->get((string) $download)->assertRedirect('/studio/login');
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = parent::getPackageProviders($app);
        $position = array_search(AdminPanelProvider::class, $providers, true);
        array_splice($providers, $position === false ? count($providers) : $position + 1, 0, [SecondCapellPanelProvider::class]);

        return $providers;
    }

    /**
     * The owner of an export started while the given panel was serving, and the signed download URL Filament sends them.
     *
     * @return array{0: User, 1: string}
     */
    private function exportFrom(string $panel): array
    {
        Schema::create('exports', static function (Blueprint $table): void {
            $table->id();
            $table->timestamp('completed_at')->nullable();
            $table->string('file_disk');
            $table->string('file_name')->nullable();
            $table->string('exporter');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('successful_rows')->default(0);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        $user = $this->createUserWithRole('super_admin');
        $serving = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel($panel));
        $export = new Export;
        $export->forceFill(['file_disk' => 'local', 'file_name' => 'runtime-export', 'exporter' => 'exporter', 'total_rows' => 1, 'user_id' => $user->getKey()])->save();
        Filament::setCurrentPanel($serving);

        Storage::fake('local');
        Storage::disk('local')->put($export->getFileDirectory() . '/headers.csv', "name\n");
        Storage::disk('local')->put($export->getFileDirectory() . '/0000000000000001.csv', "runtime\n");

        return [$user, URL::signedRoute('filament.exports.download', ['authGuard' => 'web', 'export' => $export, 'format' => 'csv'], absolute: false)];
    }
}
