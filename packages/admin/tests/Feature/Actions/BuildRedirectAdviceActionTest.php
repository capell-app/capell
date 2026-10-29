<?php

declare(strict_types=1);

use Capell\Admin\Actions\Redirects\BuildRedirectAdviceAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Gate;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Gate::before(fn (): bool => true);
    test()->actingAsAdmin();
});

/** @return array{0: Site, 1: Language} */
function redirectAdviceScope(): array
{
    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->state(['language_id' => $language->id])
        ->has(SiteDomain::factory()->state(['language_id' => $language->id]))
        ->create();

    return [$site, $language];
}

function redirectAdviceRule(Site $site, Language $language, string $url, string $target): PageUrl
{
    return PageUrl::query()->create([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'url' => $url,
        'target_url' => $target,
        'status_code' => 301,
        'status' => true,
        'type' => UrlTypeEnum::Redirect->value,
        'is_manual' => true,
    ]);
}

it('advises when the target is itself an active redirect and names only the next hop', function (): void {
    [$site, $language] = redirectAdviceScope();
    redirectAdviceRule($site, $language, '/middle', '/destination');

    $advice = BuildRedirectAdviceAction::run('/old', '/middle', $site->id, $language->id);

    expect($advice)->toHaveCount(1)
        ->and($advice[0])->toContain('/destination')
        ->and(mb_strtolower($advice[0]))->not->toContain('final');
});

it('gives no advice for a direct redirect', function (): void {
    [$site, $language] = redirectAdviceScope();

    expect(BuildRedirectAdviceAction::run('/old', '/new', $site->id, $language->id))->toBe([]);
});

it('does not turn a blocking loop into advice', function (): void {
    [$site, $language] = redirectAdviceScope();
    redirectAdviceRule($site, $language, '/middle', '/old');

    expect(BuildRedirectAdviceAction::run('/old', '/middle', $site->id, $language->id))->toBe([]);
});

it('gives no advice for unsafe targets or incomplete input', function (): void {
    [$site, $language] = redirectAdviceScope();
    redirectAdviceRule($site, $language, '//evil.test', '/destination');

    expect(BuildRedirectAdviceAction::run('/old', '//evil.test', $site->id, $language->id))->toBe([])
        ->and(BuildRedirectAdviceAction::run('/old', '', $site->id, $language->id))->toBe([])
        ->and(BuildRedirectAdviceAction::run('/old', '/middle', null, $language->id))->toBe([])
        ->and(BuildRedirectAdviceAction::run('/old', '/middle', $site->id, null))->toBe([]);
});

it('keeps advice inside the chosen site and language', function (): void {
    [$site, $language] = redirectAdviceScope();
    [$otherSite, $otherLanguage] = redirectAdviceScope();
    redirectAdviceRule($otherSite, $otherLanguage, '/middle', '/destination');

    expect(BuildRedirectAdviceAction::run('/old', '/middle', $site->id, $language->id))->toBe([])
        ->and(BuildRedirectAdviceAction::run('/old', '/middle', $otherSite->id, $language->id))->toBe([]);
});
