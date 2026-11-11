<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Installer\Filament\Pages\InstallCapellPage;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('installer');

beforeEach(function (): void {
    Site::query()->delete();
    test()->actingAsAdmin();
});

/** @return array<int, string> */
function installPageMatch(string $pattern, string $subject, string $description): array
{
    throw_unless(preg_match($pattern, $subject, $matches) === 1, RuntimeException::class, 'Install page has no ' . $description . '.');

    return $matches;
}

it('gives the setup fields visible labels and hints tied to their inputs', function (string $field, string $labelKey, ?string $hintKey): void {
    $html = Livewire::test(InstallCapellPage::class)->html();

    $control = installPageMatch('/<(?:input|select)\b[^>]*\bname="' . preg_quote($field, '/') . '"[^>]*>/s', $html, $field . ' control');
    $id = installPageMatch('/\bid="([^"]+)"/', $control[0], $field . ' id');
    $label = installPageMatch('/<label\b[^>]*\bfor="' . preg_quote($id[1], '/') . '"[^>]*>(.*?)<\/label>/s', $html, $field . ' label');

    expect(trim(strip_tags($label[1])))->toBe(__($labelKey));

    if ($hintKey !== null) {
        $describedBy = installPageMatch('/\baria-describedby="([^"]+)"/', $control[0], $field . ' description');
        $hint = installPageMatch('/<(\w+)\b[^>]*\bid="' . preg_quote($describedBy[1], '/') . '"[^>]*>(.*?)<\/\1>/s', $html, $field . ' hint');

        expect(trim(strip_tags($hint[2])))->toBe(__($hintKey));
    }
})->with([
    'site name' => ['site_name', 'capell-installer::installer.field_site_name', 'capell-core::install.site.name_hint'],
    'site url' => ['site_url', 'capell-installer::installer.field_site_url', 'capell-core::install.site.url_hint'],
    'language' => ['language', 'capell-installer::installer.field_language', null],
]);
