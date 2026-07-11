<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use Capell\Admin\Support\Extensions\ExtensionPageNavigationItemBuilder;
use Capell\Admin\Support\Extensions\ExtensionPageRegistry;
use Capell\Core\Facades\CapellCore;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Override;

abstract class AbstractExtensionPage extends Page
{
    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    #[Override]
    public function getBreadcrumbs(): array
    {
        return [
            ExtensionsPage::getUrl() => ExtensionsPage::getNavigationLabel(),
            static::getNavigationLabel(),
        ];
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        $currentUrl = static::getNavigationUrl();
        $actions = collect(resolve(ExtensionPageNavigationItemBuilder::class)->siblingItemsForPage(static::class))
            ->reject(fn (NavigationItem $item): bool => $item->getUrl() === $currentUrl)
            ->map(fn (NavigationItem $item): Action => Action::make('extensionPage' . Str::studly($item->getLabel()))
                ->label($item->getLabel())
                ->icon($item->getIcon())
                ->color('gray')
                ->url($item->getUrl()))
            ->values()
            ->all();

        $documentationAction = $this->documentationHeaderAction();

        if ($actions === []) {
            return $documentationAction instanceof Action ? [$documentationAction] : [];
        }

        $group = ActionGroup::make($actions)
            ->label(__('capell-admin::generic.extensions'))
            ->icon(Heroicon::OutlinedPuzzlePiece)
            ->color('gray');

        return $documentationAction instanceof Action
            ? [$documentationAction, $group]
            : [$group];
    }

    private function documentationHeaderAction(): ?Action
    {
        $packageName = resolve(ExtensionPageRegistry::class)->packageNameForPage(static::class);

        if ($packageName === null || ! CapellCore::hasPackage($packageName)) {
            return null;
        }

        $documentationUrl = CapellCore::getPackage($packageName)->getDocumentationUrl();

        if (! is_string($documentationUrl) || $documentationUrl === '') {
            return null;
        }

        return Action::make('extensionDocumentation')
            ->label(__('capell-admin::generic.documentation'))
            ->icon(Heroicon::OutlinedBookOpen)
            ->color('gray')
            ->url($documentationUrl, shouldOpenInNewTab: true);
    }
}
