<?php

declare(strict_types=1);

use Capell\Core\Actions\ConfigureMailMarkdownComponentsAction;
use Capell\Core\Actions\ConfigureMailMarkdownLogoAction;
use Capell\Core\Database\Factories\MediaFactory;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Site;

beforeEach(function (): void {
    config(['mail.markdown.logo' => null]);
});

it('configures the markdown mail logo from the default site logo when enabled', function (): void {
    $site = Site::factory()->default()->create([
        'meta' => [
            'mail' => [
                'use_site_logo' => true,
            ],
        ],
    ]);

    $media = MediaFactory::new([
        'model_type' => resolve(Site::class)->getMorphClass(),
        'model_id' => $site->id,
        'collection_name' => MediaCollectionEnum::Logo,
    ])->createOne();

    ConfigureMailMarkdownLogoAction::run();

    expect(config('mail.markdown.logo'))->toBe($media->getFullUrl());
});

it('does not configure the markdown mail logo when the site setting is disabled', function (): void {
    $site = Site::factory()->default()->create([
        'meta' => [
            'mail' => [
                'use_site_logo' => false,
            ],
        ],
    ]);

    MediaFactory::new([
        'model_type' => resolve(Site::class)->getMorphClass(),
        'model_id' => $site->id,
        'collection_name' => MediaCollectionEnum::Logo,
    ])->createOne();

    ConfigureMailMarkdownLogoAction::run();

    expect(config('mail.markdown.logo'))->toBeNull();
});

it('does not override an explicitly configured markdown mail logo', function (): void {
    config(['mail.markdown.logo' => 'https://cdn.example.test/logo.png']);

    $site = Site::factory()->default()->create([
        'meta' => [
            'mail' => [
                'use_site_logo' => true,
            ],
        ],
    ]);

    MediaFactory::new([
        'model_type' => resolve(Site::class)->getMorphClass(),
        'model_id' => $site->id,
        'collection_name' => MediaCollectionEnum::Logo,
    ])->createOne();

    ConfigureMailMarkdownLogoAction::run();

    expect(config('mail.markdown.logo'))->toBe('https://cdn.example.test/logo.png');
});

it('registers capell mail components when the host has no markdown component paths', function (): void {
    config(['mail.markdown.paths' => []]);

    ConfigureMailMarkdownComponentsAction::run();

    expect(config('mail.markdown.paths'))->toContain(dirname(__DIR__, 2) . '/resources/views/mail');
});

it('preserves explicitly configured markdown component paths after Capell components', function (): void {
    config(['mail.markdown.paths' => ['/app/mail/components']]);

    ConfigureMailMarkdownComponentsAction::run();

    expect(config('mail.markdown.paths'))->toBe([
        dirname(__DIR__, 2) . '/resources/views/mail',
        '/app/mail/components',
    ]);
});
