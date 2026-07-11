<?php

declare(strict_types=1);

use Capell\Admin\Filament\Actions\Site\CreateSiteAction;
use Capell\Core\Models\Language;
use Illuminate\Http\Request;

it('pre-populates create-site domains for the primary and additional languages', function (): void {
    $english = Language::factory()->createOne([
        'code' => 'en',
        'default' => true,
        'order' => 1,
    ]);
    $french = Language::factory()->createOne([
        'code' => 'fr',
        'default' => false,
        'order' => 2,
    ]);

    app()->instance('request', Request::create('https://admin.example.test/admin/sites'));

    $data = createSiteActionMutateFormData([
        'name' => 'Client Site',
        'language_id' => $english->getKey(),
        'languages' => [$french->getKey()],
    ]);

    expect($data['site_domains'])->toBe([
        [
            'url' => 'https://admin.example.test',
            'language_id' => $english->getKey(),
            'default' => true,
            'use_host_domain' => true,
        ],
        [
            'url' => 'https://admin.example.test/fr',
            'language_id' => $french->getKey(),
            'default' => false,
            'use_host_domain' => true,
        ],
    ]);
});

it('handles create-site domain defaults when no language ids are usable', function (): void {
    $data = createSiteActionMutateFormData([
        'name' => 'Client Site',
        'language_id' => null,
        'languages' => [],
    ]);

    expect($data['site_domains'])->toBe([]);
});

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function createSiteActionMutateFormData(array $data): array
{
    $action = CreateSiteAction::make('createSite');
    $reflectionMethod = new ReflectionMethod($action, 'mutateFormData');

    return $reflectionMethod->invoke($action, $data);
}
