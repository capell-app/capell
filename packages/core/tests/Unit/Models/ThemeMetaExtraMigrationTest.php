<?php

declare(strict_types=1);

use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\Schema;

it('has a meta_extra json column on themes', function (): void {
    expect(Schema::hasColumn('themes', 'meta_extra'))->toBeTrue();
});

it('persists meta_extra as associative array', function (): void {
    $theme = Theme::factory()->createOne([
        'meta_extra' => ['plugin_demo' => ['accent' => '#ff00aa']],
    ]);

    expect($theme->fresh()->meta_extra)
        ->toBe(['plugin_demo' => ['accent' => '#ff00aa']]);
});
