<?php

declare(strict_types=1);

use Capell\Frontend\Support\Http\CrawlerDetector;

uses()->group('frontend');

beforeEach(function (): void {
    $this->detector = new CrawlerDetector;
});

it('identifies crawlers that never spell "bot"', function (string $userAgent): void {
    expect($this->detector->isCrawler($userAgent))->toBeTrue($userAgent);
})->with([
    'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'WhatsApp/2.23.20.0 A',
    'Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome-Lighthouse',
    'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)',
    'Mozilla/5.0 (compatible; Baiduspider/2.0)',
    'curl/8.4.0',
    'python-requests/2.31.0',
    'Mozilla/5.0 (Macintosh) HeadlessChrome/120.0.0.0',
]);

it('identifies crawlers that do spell "bot"', function (): void {
    expect($this->detector->isCrawler('Mozilla/5.0 (compatible; Googlebot/2.1)'))->toBeTrue();
    expect($this->detector->isCrawler('GPTBot/1.2'))->toBeTrue();
});

it('treats an absent User-Agent as automated', function (): void {
    expect($this->detector->isCrawler(null))->toBeTrue();
    expect($this->detector->isCrawler('   '))->toBeTrue();
});

it('identifies the real Symfony HTTP client without matching synthesised requests', function (): void {
    expect($this->detector->isCrawler('Symfony HttpClient (Curl)'))->toBeTrue();
    // `Request::create()` synthesises this User-Agent for in-process requests.
    expect($this->detector->isCrawler('Symfony'))->toBeFalse();
});

it('leaves real browsers alone', function (string $userAgent): void {
    expect($this->detector->isCrawler($userAgent))->toBeFalse($userAgent);
})->with([
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
]);
