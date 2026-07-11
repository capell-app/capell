<?php

declare(strict_types=1);

use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Support\Media\YouTubeVideoUrl;

it('normalizes supported YouTube URLs', function (string $url): void {
    $video = YouTubeVideoUrl::parse($url);

    expect($video)->not->toBeNull();

    if (! $video instanceof ExternalVideoData) {
        return;
    }

    expect($video->provider)->toBe('youtube')
        ->and($video->videoId)->toBe('FgalLC99jzY')
        ->and($video->embedUrl)->toBe('https://www.youtube-nocookie.com/embed/FgalLC99jzY?enablejsapi=1&rel=0&playsinline=1')
        ->and($video->thumbnailUrl)->toBe('https://img.youtube.com/vi/FgalLC99jzY/hqdefault.jpg');
})->with([
    'short URL' => ['https://youtu.be/FgalLC99jzY'],
    'watch URL' => ['https://www.youtube.com/watch?v=FgalLC99jzY'],
    'embed URL' => ['https://www.youtube.com/embed/FgalLC99jzY'],
]);

it('rejects unsupported external video URLs', function (string $url): void {
    expect(YouTubeVideoUrl::parse($url))->toBeNull();
})->with([
    'empty' => [''],
    'non URL' => ['FgalLC99jzY'],
    'unsupported host' => ['https://vimeo.com/12345678901'],
    'bad id' => ['https://youtu.be/nope'],
]);
