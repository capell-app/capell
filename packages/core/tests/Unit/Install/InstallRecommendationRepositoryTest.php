<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\ResolveInstallRecommendationAction;
use Capell\Core\Data\Install\InstallRecommendationData;
use Capell\Core\Enums\InstallRecommendationAction;
use Capell\Core\Support\Install\InstallRecommendationRepository;
use Illuminate\Support\Facades\File;

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
        ->and($action->handle(InstallRecommendationAction::Custom, customPackages: [' b ', ' ', 42, 'a', 'b']))->toBe(['b', 'a'])
        ->and($action->handle(InstallRecommendationAction::Skip))->toBe([]);

    expect(fn (): array => $action->handle(InstallRecommendationAction::Select, 'missing'))
        ->toThrow(InvalidArgumentException::class);
});

it('normalises recommendation values, ordering, and empty lookups', function (): void {
    config([
        'capell.install.recommendations' => [
            'zulu' => [
                'label' => ' Zulu ',
                'description' => ' Description ',
                'packages' => 'capell-app/core, capell-app/core, , missing/package',
                'theme' => '  ',
                'demo' => 'yes',
                'order' => '10',
            ],
            'alpha' => [
                'label' => 'Alpha',
                'description' => 'Alpha description',
                'packages' => ['capell-app/core'],
                'order' => -1,
            ],
            'invalid-label' => [
                'label' => ' ',
                'description' => 'Ignored',
            ],
            7 => ['label' => 'Ignored', 'description' => 'Ignored'],
            'not-an-array' => 'ignored',
        ],
    ]);

    $repository = resolve(InstallRecommendationRepository::class);

    expect($repository->find(null))->toBeNull()
        ->and($repository->find(' '))->toBeNull()
        ->and($repository->all())->toHaveCount(2)
        ->and($repository->all()[0]->key)->toBe('alpha')
        ->and($repository->all()[1]->key)->toBe('zulu')
        ->and($repository->find('zulu'))->toMatchObject([
            'label' => 'Zulu',
            'description' => 'Description',
            'packages' => ['capell-app/core'],
            'theme' => null,
            'demo' => null,
            'order' => 0,
        ]);
});

it('falls back to host recommendation files and ignores malformed JSON', function (): void {
    config(['capell.install.recommendations' => null]);

    File::shouldReceive('exists')
        ->once()
        ->with(base_path('config/capell-install-recommendations.php'))
        ->andReturnFalse();
    File::shouldReceive('exists')
        ->once()
        ->with(base_path('capell-install-recommendations.json'))
        ->andReturnTrue();
    File::shouldReceive('get')
        ->once()
        ->with(base_path('capell-install-recommendations.json'))
        ->andReturn('{invalid-json');

    expect(resolve(InstallRecommendationRepository::class)->all())->toBe([]);
});

it('loads valid JSON recommendations when config is not provided', function (): void {
    config(['capell.install.recommendations' => null]);

    File::shouldReceive('exists')
        ->once()
        ->with(base_path('config/capell-install-recommendations.php'))
        ->andReturnFalse();
    File::shouldReceive('exists')
        ->once()
        ->with(base_path('capell-install-recommendations.json'))
        ->andReturnTrue();
    File::shouldReceive('get')
        ->once()
        ->with(base_path('capell-install-recommendations.json'))
        ->andReturn(json_encode([
            'headless' => [
                'label' => 'Headless',
                'description' => 'A headless site.',
                'packages' => [],
            ],
        ], JSON_THROW_ON_ERROR));

    expect(resolve(InstallRecommendationRepository::class)->find('headless')?->label)->toBe('Headless');
});
