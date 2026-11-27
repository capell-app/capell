<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Makers\MakerRegistry;
use Capell\Core\Tests\Support\EncryptedProbeSettings;
use Capell\Core\Tests\Support\UnmarkedSecretProbeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelSettings\Events\SavingSettings;
use Spatie\LaravelSettings\SettingsRepositories\SettingsRepository;

it('lists the page type and layout makers with the other makers', function (): void {
    $keys = resolve(MakerRegistry::class)->all()->map(fn ($maker): string => $maker->definition()->key)->all();

    expect($keys)->toContain('core.page-type', 'core.layout');
});

it('creates a page type editors can choose', function (): void {
    artisanCommand('capell:make:page-type', ['key' => 'service-page', '--name' => 'Service page'])
        ->expectsOutputToContain('created Blueprint')
        ->assertExitCode(Command::SUCCESS);

    $blueprint = Blueprint::query()->where('key', 'service-page')->sole();

    expect($blueprint->name)->toBe('Service page')
        ->and($blueprint->getRawOriginal('type'))->toBe(BlueprintSubjectEnum::Page->value)
        ->and($blueprint->status)->toBeTrue();
});

it('refuses a page type key that already exists or is not a key', function (string $key, string $message): void {
    Blueprint::query()->create(['type' => BlueprintSubjectEnum::Page, 'key' => 'service-page', 'name' => 'Service page']);

    artisanCommand('capell:make:page-type', ['key' => $key])
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::FAILURE);

    expect(Blueprint::query()->where('key', 'service-page')->count())->toBe(1);
})->with([
    'duplicate' => ['service-page', 'already exists'],
    'path' => ['../service', 'lowercase letters, numbers and single dashes'],
]);

it('writes nothing on a dry run', function (): void {
    artisanCommand('capell:make:page-type', ['key' => 'case-study', '--dry-run' => true])
        ->expectsOutputToContain('Dry run: nothing was written.')
        ->assertExitCode(Command::SUCCESS);

    expect(Blueprint::query()->where('key', 'case-study')->exists())->toBeFalse();
});

it('creates a layout with the containers of an existing layout', function (): void {
    $containers = [['key' => 'header', 'widgets' => []], ['key' => 'main', 'widgets' => []]];
    Layout::factory()->create(['key' => 'standard', 'containers' => $containers]);

    artisanCommand('capell:make:layout', ['key' => 'two-column', '--name' => 'Two column', '--from' => 'standard'])
        ->expectsOutputToContain('created Layout')
        ->assertExitCode(Command::SUCCESS);

    $layout = Layout::query()->where('key', 'two-column')->sole();

    expect($layout->name)->toBe('Two column')
        ->and($layout->containers)->toBe($containers);
});

it('copies the areas but not the widget placements of a populated layout', function (): void {
    $containers = [['key' => 'header', 'widgets' => [['id' => 7, 'key' => 'hero']]], ['key' => 'main', 'widgets' => [['id' => 8, 'key' => 'text']]]];
    $source = Layout::factory()->create(['key' => 'standard', 'containers' => $containers]);

    artisanCommand('capell:make:layout', ['key' => 'two-column', '--from' => 'standard'])
        ->assertExitCode(Command::SUCCESS);

    expect(Layout::query()->where('key', 'two-column')->sole()->containers)
        ->toBe([['key' => 'header', 'widgets' => []], ['key' => 'main', 'widgets' => []]])
        ->and($source->refresh()->containers)->toBe($containers);
});

it('refuses a layout copied from a layout that does not exist', function (): void {
    artisanCommand('capell:make:layout', ['key' => 'two-column', '--from' => 'missing'])
        ->expectsOutputToContain('No layout has the key [missing]')
        ->assertExitCode(Command::FAILURE);

    expect(Layout::query()->where('key', 'two-column')->exists())->toBeFalse();
});

it('activates an installed theme for the only site', function (): void {
    $site = Site::factory()->default()->create();
    $theme = Theme::factory()->create(['key' => 'aurora', 'name' => 'Aurora', 'status' => false]);

    artisanCommand('capell:theme:activate', ['theme' => 'aurora'])
        ->expectsOutputToContain('Aurora is now the theme for site ' . $site->getKey())
        ->assertExitCode(Command::SUCCESS);

    expect($site->refresh()->theme_id)->toBe($theme->getKey())
        ->and($theme->refresh()->status)->toBeTrue();
});

it('keeps the colours and header settings of a theme that already exists', function (): void {
    Site::factory()->default()->create();
    $theme = Theme::factory()->create(['key' => 'aurora', 'name' => 'Aurora']);
    $meta = $theme->meta ?? [];
    $meta['colors'] = ['primary' => '#112233'];
    $theme->forceFill(['meta' => $meta])->save();
    $before = $theme->refresh()->meta;

    artisanCommand('capell:theme:activate', ['theme' => 'aurora'])->assertExitCode(Command::SUCCESS);

    expect($theme->refresh()->meta)->toBe($before);
});

it('asks which site to switch when there is more than one', function (): void {
    $first = Site::factory()->default()->create();
    $second = Site::factory()->create();
    Theme::factory()->create(['key' => 'aurora']);
    $before = $second->theme_id;

    artisanCommand('capell:theme:activate', ['theme' => 'aurora'])
        ->expectsOutputToContain('Pass --site=<id>')
        ->assertExitCode(Command::FAILURE);

    expect($second->refresh()->theme_id)->toBe($before);

    artisanCommand('capell:theme:activate', ['theme' => 'aurora', '--site' => [(string) $second->getKey()]])
        ->assertExitCode(Command::SUCCESS);

    expect($second->refresh()->theme_id)->toBe(Theme::query()->where('key', 'aurora')->value('id'))
        ->and($first->refresh()->theme_id)->not->toBe($second->theme_id);
});

it('refuses a site ID that is not a number instead of switching site 0', function (): void {
    $site = Site::factory()->default()->create();
    Theme::factory()->create(['key' => 'aurora']);
    $before = $site->theme_id;

    artisanCommand('capell:theme:activate', ['theme' => 'aurora', '--site' => ['main']])
        ->expectsOutputToContain('A site ID is a whole number, not [main]')
        ->assertExitCode(Command::FAILURE);

    expect($site->refresh()->theme_id)->toBe($before);
});

it('names the installed themes when the key is unknown', function (): void {
    Site::factory()->default()->create();

    artisanCommand('capell:theme:activate', ['theme' => 'missing'])
        ->expectsOutputToContain('No theme has the key [missing]')
        ->assertExitCode(Command::FAILURE);
});

it('never prints a setting that is encrypted by attribute, on read or write', function (): void {
    config()->set('settings.settings', [...(array) config('settings.settings', []), EncryptedProbeSettings::class]);
    resolve(SettingsRepository::class)->createProperty('encrypted-probe', 'api_token', Crypt::encrypt('seed-value'));

    artisanCommand('capell:settings:set', ['setting' => 'encrypted-probe.api_token', 'value' => 'sentinel-secret-9137'])
        ->doesntExpectOutputToContain('sentinel-secret-9137')
        ->expectsOutputToContain('(hidden)')
        ->assertExitCode(Command::SUCCESS);

    Artisan::call('capell:settings:get');
    $everything = Artisan::output();
    Artisan::call('capell:settings:get', ['setting' => 'encrypted-probe.api_token']);

    expect($everything)->not->toContain('sentinel-secret-9137')
        ->and(Artisan::output())->not->toContain('sentinel-secret-9137')->toContain('(hidden)')
        ->and(resolve(EncryptedProbeSettings::class)->refresh()->api_token)->toBe('sentinel-secret-9137');
});

it('hides a secret by its name even when nobody marked it encrypted', function (): void {
    config()->set('settings.settings', [...(array) config('settings.settings', []), UnmarkedSecretProbeSettings::class]);
    $repository = resolve(SettingsRepository::class);
    $repository->createProperty('unmarked-probe', 'stripe_secret_key', 'sk_live_seed');
    $repository->createProperty('unmarked-probe', 'display_name', 'Shop');

    artisanCommand('capell:settings:set', ['setting' => 'unmarked-probe.stripe_secret_key', 'value' => 'sk_live_sentinel_4471'])
        ->doesntExpectOutputToContain('sk_live_sentinel_4471')
        ->assertExitCode(Command::SUCCESS);

    Artisan::call('capell:settings:get', ['setting' => 'unmarked-probe', '--json' => true]);
    $json = Artisan::output();
    Artisan::call('capell:settings:get', ['setting' => 'unmarked-probe.stripe_secret_key']);
    $single = Artisan::output();

    expect($json)->not->toContain('sk_live_sentinel_4471')->toContain('Shop')
        ->and($single)->not->toContain('sk_live_sentinel_4471')
        ->and(resolve(UnmarkedSecretProbeSettings::class)->refresh()->stripe_secret_key)->toBe('sk_live_sentinel_4471');
});

it('keeps a Theme Studio brand profile renderable when a token is given the wrong type', function (): void {
    artisanCommand('capell:settings:set', ['setting' => 'theme_studio.brandProfile', 'value' => '{"primaryColor":[]}'])
        ->assertExitCode(Command::SUCCESS);

    $profile = resolve(ThemeStudioSettings::class)->refresh()->brandProfile;

    expect($profile['primaryColor'])->toBeString()
        ->and(BrandProfileData::from($profile)->primaryColor)->toBeString();
});

it('prints no value from a failed save, because the error can carry every secret in the group', function (): void {
    Event::listen(SavingSettings::class, function (): never {
        throw new QueryException('testing', 'update settings set payload = ?', ['sk_live_binding_5523'], new RuntimeException('write denied sk_live_binding_5523'));
    });

    artisanCommand('capell:settings:set', ['setting' => 'core.allow_relative_image_urls', 'value' => 'false'])
        ->doesntExpectOutputToContain('sk_live_binding_5523')
        ->expectsOutputToContain('Could not save core.allow_relative_image_urls')
        ->assertExitCode(Command::FAILURE);
});

it('refuses to change a locked setting and leaves its value alone', function (): void {
    $before = resolve(CoreSettings::class)->default_locale;
    resolve(SettingsRepository::class)->lockProperties('core', ['default_locale']);

    artisanCommand('capell:settings:set', ['setting' => 'core.default_locale', 'value' => 'zz'])
        ->expectsOutputToContain('core.default_locale is locked')
        ->assertExitCode(Command::FAILURE);

    expect(resolve(CoreSettings::class)->refresh()->default_locale)->toBe($before);
});

it('invalidates every site when a Theme Studio setting is changed from the command line', function (): void {
    $site = Site::factory()->default()->create();
    Event::fake([FrontendSurrogateKeysInvalidated::class]);

    artisanCommand('capell:settings:set', ['setting' => 'theme_studio.activePreset', 'value' => 'boardroom'])->assertExitCode(Command::SUCCESS);

    Event::assertDispatched(FrontendSurrogateKeysInvalidated::class, fn (FrontendSurrogateKeysInvalidated $event): bool => in_array('site-' . $site->getKey(), $event->surrogateKeys, true));
});

it('reads and changes a setting with its declared type', function (): void {
    artisanCommand('capell:settings:set', ['setting' => 'core.allow_relative_image_urls', 'value' => 'false'])
        ->expectsOutputToContain('core.allow_relative_image_urls is now false.')
        ->assertExitCode(Command::SUCCESS);

    expect(resolve(CoreSettings::class)->refresh()->allow_relative_image_urls)->toBeFalse();

    artisanCommand('capell:settings:get', ['setting' => 'core.allow_relative_image_urls', '--json' => true])
        ->expectsOutputToContain('false')
        ->assertExitCode(Command::SUCCESS);
});

it('refuses a setting value of the wrong type or an unknown setting', function (string $setting, string $value, string $message): void {
    artisanCommand('capell:settings:set', ['setting' => $setting, 'value' => $value])
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::FAILURE);

    expect(resolve(CoreSettings::class)->refresh()->allow_relative_image_urls)->toBeTrue();
})->with([
    'wrong type' => ['core.allow_relative_image_urls', 'sometimes', 'must be true or false'],
    'unknown setting' => ['core.missing', 'x', 'have no [missing] setting'],
    'unknown group' => ['missing.value', 'x', 'No settings group is called [missing]'],
]);

it('creates a page type through the generic maker command', function (): void {
    artisanCommand('capell:make', ['maker' => 'core.page-type', '--name' => 'Case study', '--database' => true])
        ->assertExitCode(Command::SUCCESS);

    expect(Blueprint::query()->where('key', 'case-study')->value('name'))->toBe('Case study');
});
