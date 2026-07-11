<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\Blade;

it('adds layout and configured body classes to the public body element', function (): void {
    $layout = new Layout;
    $layout->key = 'home';
    $layout->meta = ['body_class' => 'layout-extra'];

    $theme = new Theme;
    $theme->key = 'corporate-store';
    $theme->meta = ['body_class' => 'theme-extra'];

    $html = Blade::render(
        <<<'BLADE'
        <x-capell::app.body
            :language="$language"
            :layout="$layout"
            :page-record="$pageRecord"
            :site="$site"
            :theme="$theme"
        >
            Rendered body.
        </x-capell::app.body>
        BLADE,
        [
            'language' => new Language,
            'layout' => $layout,
            'pageRecord' => new Page,
            'site' => new Site,
            'theme' => $theme,
        ],
    );

    expect($html)
        ->toContain('layout-home')
        ->toContain('layout-extra')
        ->toContain('theme-extra')
        ->not->toContain('showLightbox')
        ->not->toContain('theme-corporate-store');
});
