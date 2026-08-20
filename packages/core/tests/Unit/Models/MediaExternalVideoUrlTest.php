<?php

declare(strict_types=1);

use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Models\Media;
use Capell\Core\Support\Media\YouTubeVideoUrl;

it('exposes a remote thumbnail instead of a fictitious storage file for an external video', function (): void {
    $video = YouTubeVideoUrl::parse('https://youtu.be/FgalLC99jzY');

    throw_unless($video instanceof ExternalVideoData, RuntimeException::class, 'Expected a valid YouTube video URL.');

    $media = new Media;
    $media->id = 24;
    $media->disk = 'public';
    $media->setExternalVideo($video);

    expect($media->original_url)->toBe($video->thumbnailUrl)
        ->and($media->original_url)->not->toContain('/storage/')
        ->and($media->original_url)->not->toEndWith('.youtube');
});

it('does not trust a persisted external-video thumbnail URL', function (): void {
    $media = new Media([
        'id' => 24,
        'disk' => 'public',
        'file_name' => 'FgalLC99jzY.youtube',
        'custom_properties' => [
            'capell' => [
                'video' => [
                    'provider' => 'youtube',
                    'video_id' => 'FgalLC99jzY',
                    'url' => 'https://invalid.example.test/video',
                    'embed_url' => 'https://invalid.example.test/embed',
                    'thumbnail_url' => 'https://invalid.example.test/tracker.png',
                ],
            ],
        ],
    ]);

    expect($media->original_url)
        ->toEndWith('/storage/24/FgalLC99jzY.youtube')
        ->not->toContain('invalid.example.test');
});
