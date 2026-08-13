<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\ResolveInstallRecommendationAction;
use Capell\Core\Data\Install\InstallRecommendationData;
use Capell\Core\Enums\InstallRecommendationAction;
use Capell\Core\Support\Install\InstallRecommendationRepository;

it('normalises configured recommendations and ignores unknown package identities', function (): void {
    config([
        'capell.install.recommendations' => [
            'blog' => [
                'label' => 'Blog',
                'description' => 'A blog bundle.',
                'packages' => ['capell-app/core', 'missing/package'],
                'order' => 2,
            ],
            'invalid' => ['packages' => ['capell-app/core']],
        ],
    ]);

    $recommendation = resolve(InstallRecommendationRepository::class)->find('blog');

    expect($recommendation)->toBeInstanceOf(InstallRecommendationData::class)
        ->and($recommendation?->label)->toBe('Blog')
        ->and($recommendation?->packages)->not->toContain('missing/package');
});

it('resolves explicit select, confirm, custom, and skip actions', function (): void {
    config([
        'capell.install.recommendations' => [
            'blog' => [
                'label' => 'Blog',
                'description' => 'A blog bundle.',
                'packages' => ['capell-app/core'],
            ],
        ],
    ]);

    $repository = resolve(InstallRecommendationRepository::class);

    $action = new ResolveInstallRecommendationAction($repository);

    expect($action->handle(InstallRecommendationAction::Select, 'blog'))->toBe(['capell-app/core'])
        ->and($action->handle(InstallRecommendationAction::Confirm, 'blog'))->toBe(['capell-app/core'])
        ->and($action->handle(InstallRecommendationAction::Custom, customPackages: ['b', 'a', 'b']))->toBe(['b', 'a'])
        ->and($action->handle(InstallRecommendationAction::Skip))->toBe([]);
});
