<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Settings\AdminSettings;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

use Sinnbeck\DomAssertions\Asserts\AssertElement;
use Sinnbeck\DomAssertions\Asserts\BaseAssert;
use Sinnbeck\DomAssertions\Support\DomParser;

uses(CreatesAdminUser::class)->group('admin');

it('keeps mobile header controls in navigation and desktop controls in the topbar', function (bool $editPage): void {
    test()->actingAsAdmin();

    $settings = AdminSettings::instance();
    $settings->enable_header_navigation_tree = true;
    $settings->save();

    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->withTranslations()->createOne(['name' => 'Editorial Site With A Long Name']);
    $page = $editPage ? Page::factory()->site($site)->withTranslations()->createOne() : null;
    $url = $page instanceof Page ? EditPage::getUrl(['record' => $page->getRouteKey()]) : '/admin';

    $response = get($url . '?site=' . $site->getKey())
        ->assertOk()
        ->assertElementExists(
            '.fi-topbar-end',
            fn (AssertElement $topbar): BaseAssert => $topbar
                ->find('[data-capell-site-switcher]', fn (AssertElement $switcher): BaseAssert => $switcher->has('class', 'hidden lg:block'))
                ->find('[data-capell-header-actions]', fn (AssertElement $actions): BaseAssert => $actions->has('class', 'hidden lg:flex'))
                ->find('.fi-global-search-ctn input[type="search"]')
                ->find('.fi-user-menu-trigger'),
        )
        ->assertElementExists(
            '.fi-sidebar-nav [data-capell-mobile-header]',
            fn (AssertElement $mobile): BaseAssert => $mobile
                ->has('class', 'flex flex-wrap lg:hidden')
                ->find('[data-capell-site-switcher]', fn (AssertElement $switcher): BaseAssert => $switcher
                    ->doesntHave('class', 'hidden')
                    ->containsText($site->name)
                    ->find('a[href*="site=' . $site->getKey() . '"]'))
                ->find('[data-capell-header-actions]', fn (AssertElement $actions): BaseAssert => $actions
                    ->has('class', 'flex')
                    ->doesntHave('class', 'hidden')
                    ->find('button[aria-label="' . __('capell-admin::workspace.switcher_tools.open') . '"]')
                    ->find('button[title="' . __('capell-admin::button.site_tools') . '"]')),
        );

    $html = $response->getContent();
    assert(is_string($html));
    $xpath = new DOMXPath(DomParser::new($html)->getDocument());
    $panels = $xpath->query('//*[@data-capell-mobile-header]//*[@x-ref="panel"]');
    assert($panels instanceof DOMNodeList);

    expect($panels->length)->toBe(4);

    foreach ($panels as $panel) {
        assert($panel instanceof DOMElement);
        expect($panel->hasAttribute('x-float.placement.bottom-end.size.flip.shift.teleport.offset')
            || $panel->hasAttribute('x-float.placement.left-start.size.flip.shift.teleport.offset'))->toBeTrue();
    }

    $searches = $xpath->query('//input[starts-with(@id, "admin-workspace-search-") or starts-with(@id, "capell-header-navigation-search-")]');
    assert($searches instanceof DOMNodeList);
    $searchIds = [];

    foreach ($searches as $search) {
        assert($search instanceof DOMElement);
        $searchIds[] = $search->getAttribute('id');
    }

    expect($searchIds)->toHaveCount(4)
        ->and(array_unique($searchIds))->toHaveCount(4);

    foreach ($searchIds as $id) {
        $labels = $xpath->query('//label[@for="' . $id . '"]');
        assert($labels instanceof DOMNodeList);
        expect($labels->length)->toBe(1);
    }
})->with([
    'dashboard' => false,
    'page edit' => true,
]);
