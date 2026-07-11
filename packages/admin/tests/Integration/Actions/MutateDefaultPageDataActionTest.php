<?php

declare(strict_types=1);

use Capell\Admin\Actions\MutateDefaultPageDataAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Illuminate\Support\Str;

describe('MutateDefaultPageDataAction feature', function (): void {
    it('returns correct default page data structure and values', function (): void {
        $layout = Layout::factory()->default()->create();
        $type = Blueprint::factory()->page()->default()->create();
        $site = Site::factory()->createOne();

        $data = MutateDefaultPageDataAction::run();

        expect($data)
            ->toHaveKey('layout_id')
            ->and($data['layout_id'])->toBe($layout->id)
            ->and($data)->toHaveKey('blueprint_id')
            ->and($data['blueprint_id'])->toBe($type->id)
            ->and($data)->toHaveKey('site_id')
            ->and($data['site_id'])->toBe($site->id)
            ->and($data)->toHaveKey('translations');

        $translations = $data['translations'];
        expect($translations)->toBeArray()->not()->toBeEmpty();
        foreach ($translations as $uuid => $translation) {
            expect(Str::isUuid($uuid))->toBeTrue();
            expect($translation)->toBeArray()->toHaveKey('language_id');
            expect($translation['language_id'])->toBe($site->language_id);
        }

        $allowedKeys = ['layout_id', 'blueprint_id', 'site_id', 'translations'];
        expect(array_diff(array_keys($data), $allowedKeys))->toBeEmpty();
    });
});
