<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Enums\VisitorLanguageDetection;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

it('renders one banner variant per active hydrated language without queries', function (): void {
    $english = new Language(['name' => 'English', 'code' => 'en', 'locale' => 'en_GB']);
    $english->id = 1;

    $arabic = new Language(['name' => 'Arabic', 'code' => 'ar']);
    $arabic->id = 2;

    $domains = new Collection([
        new SiteDomain(['status' => true])->setRelation('language', $english),
        new SiteDomain(['status' => true])->setRelation('language', $arabic),
        new SiteDomain(['status' => true])->setRelation('language', $english),
        new SiteDomain(['status' => false])->setRelation('language', new Language(['code' => 'de'])),
        new SiteDomain(['status' => true]),
    ]);
    $site = new Site;
    $site->setRelation('siteDomains', $domains);
    resolve(FrontendState::class)->withSite($site)->withLanguage($english);
    $this->mock(FrontendSettingsReaderInterface::class)
        ->shouldReceive('visitorLanguageDetection')->andReturn(VisitorLanguageDetection::Banner);

    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $html = Blade::render('<x-capell::language-suggestion-banner />');
        $dom = frontendRenderedDom($html);
        expect(domCount($dom, '//*[@data-capell-language-variant]'))->toBe(2)
            ->and(domCount($dom, '//*[@data-capell-language-variant="ar" and @dir="rtl"]'))->toBe(1)
            ->and(domCount($dom, '//*[@data-capell-language-variant="en-gb"]//*[@data-capell-language-suggest="ar"]'))->toBe(1)
            ->and(domCount($dom, '//*[@data-capell-language-reverted]'))->toBe(4);
        $site->unsetRelation('siteDomains');
        expect(trim(Blade::render('<x-capell::language-suggestion-banner />')))->toBe('')
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }
});
