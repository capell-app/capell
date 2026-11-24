<?php

declare(strict_types=1);

use Capell\Core\Actions\Ai\RecordAiProvenanceAction;
use Capell\Core\Data\Ai\AiProvenanceRecordData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

const AI_NOTICE_TEXT = 'Parts of this page were written with help from AI.';

/**
 * Serves a real homepage through the frontend route. When $disclosure is given, the page
 * is marked AI-written by the same action the AI writers use.
 *
 * @param  array<string, mixed>  $siteMeta
 * @param  Closure(): TestResponse  $request
 */
function renderAiNoticePage(array $siteMeta, ?AiDisclosure $disclosure, Closure $request): string
{
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    Cache::flush();

    $site = Site::factory()
        ->withTranslations(siteDomainData: ['domain' => 'localhost', 'scheme' => 'http', 'path' => null, 'default' => true])
        ->create(['meta' => $siteMeta]);

    $page = Page::factory()->site($site)->home()->withTranslations(slug: '/')->create(['meta' => null]);

    if ($disclosure instanceof AiDisclosure) {
        RecordAiProvenanceAction::run(new AiProvenanceRecordData($page, 'content', $disclosure));
    }

    DB::enableQueryLog();
    $response = $request();
    $provenanceQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains((string) $query['query'], 'capell_ai_provenance'))->count();
    DB::disableQueryLog();

    $response->assertOk();
    expect($provenanceQueries)->toBe(0);

    return (string) $response->getContent();
}

it('shows the AI notice exactly once on an AI-written page when the site turns it on', function (AiDisclosure $disclosure): void {
    $html = renderAiNoticePage(['ai_notice' => true], $disclosure, fn () => $this->followingRedirects()->get('/', ['HTTP_HOST' => 'localhost']));

    expect(substr_count($html, AI_NOTICE_TEXT))->toBe(1)
        ->and($html)->not->toContain('capell_ai_provenance')
        ->and($html)->not->toContain('generation_history')
        ->and($html)->not->toContain('confirmed_at')
        ->and($html)->not->toContain('name="ai-disclosure"');
})->with([
    'assisted' => AiDisclosure::Assisted,
    'generated' => AiDisclosure::Generated,
]);

it('leaves the notice out when the site setting is off or unset', function (array $siteMeta): void {
    expect(renderAiNoticePage($siteMeta, AiDisclosure::Generated, fn () => $this->followingRedirects()->get('/', ['HTTP_HOST' => 'localhost'])))->not->toContain(AI_NOTICE_TEXT);
})->with([
    'off' => [['ai_notice' => false]],
    'unset' => [[]],
]);

it('leaves the notice out of a page no AI wrote even when the site turns it on', function (?AiDisclosure $disclosure): void {
    expect(renderAiNoticePage(['ai_notice' => true], $disclosure, fn () => $this->followingRedirects()->get('/', ['HTTP_HOST' => 'localhost'])))->not->toContain(AI_NOTICE_TEXT);
})->with([
    'no provenance' => [null],
    'person rewrote it' => [AiDisclosure::None],
]);

it('keeps the machine-readable marker independent of the notice', function (): void {
    $html = renderAiNoticePage(['ai_disclosure' => 'marker'], AiDisclosure::Generated, fn () => $this->followingRedirects()->get('/', ['HTTP_HOST' => 'localhost']));

    expect($html)->toContain('name="ai-disclosure"')
        ->and($html)->toContain('content="generated"')
        ->and($html)->not->toContain(AI_NOTICE_TEXT);
});

it('declares the digital source type in the served page JSON-LD only when the site marks AI-written pages', function (array $siteMeta, ?AiDisclosure $disclosure, bool $expected): void {
    $html = renderAiNoticePage($siteMeta, $disclosure, fn () => $this->followingRedirects()->get('/', ['HTTP_HOST' => 'localhost']));

    expect(str_contains($html, 'digitalSourceType'))->toBe($expected)
        ->and($html)->not->toContain('capell_ai_provenance');

    if ($expected) {
        expect($html)->toContain('"digitalSourceType":"https:\\/\\/schema.org\\/TrainedAlgorithmicMediaDigitalSource"');
    }
})->with([
    'marker on, generated' => [['ai_disclosure' => 'marker'], AiDisclosure::Generated, true],
    'marker off, generated' => [['ai_disclosure' => 'off'], AiDisclosure::Generated, false],
    'marker on, no provenance' => [['ai_disclosure' => 'marker'], null, false],
    'notice only, generated' => [['ai_notice' => true], AiDisclosure::Generated, false],
]);
