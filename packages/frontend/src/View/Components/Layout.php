<?php

declare(strict_types=1);

namespace Capell\Frontend\View\Components;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Layout as LayoutModel;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\AssertStoredPublicViewSelectorsAction;
use Capell\Frontend\Actions\BuildPublicLayoutShellDataAction;
use Capell\Frontend\Actions\BuildPublicMainLayoutDataAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicLayoutShellData;
use Capell\Frontend\Data\PublicMainLayoutData;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Override;

/** The shared public layout shell. Its view receives public render data only; the frontend records stay private. */
final class Layout extends Component
{
    public readonly PublicLayoutShellData $renderData;

    private readonly ?Theme $theme;

    private readonly ?Pageable $page;

    private readonly ?LayoutModel $layout;

    public function __construct(
        FrontendContextReader $frontend,
        private readonly Factory $view,
        public readonly ?string $containerClass = null,
        public readonly mixed $footer = null,
        public readonly mixed $header = null,
        public readonly ?string $mainClass = null,
        public readonly ?string $mainContainerClass = null,
        public readonly mixed $pageSlot = null,
    ) {
        $this->theme = $frontend->theme();
        $this->page = $frontend->page();
        $this->layout = $frontend->layout();

        foreach ([$this->theme, $this->layout] as $record) {
            if ($record !== null) {
                AssertStoredPublicViewSelectorsAction::run($record);
            }
        }

        $this->renderData = BuildPublicLayoutShellDataAction::run($this->theme, $this->page, $this->layout, $frontend->site());
    }

    public function mainLayout(mixed $pageSlot): PublicMainLayoutData
    {
        return BuildPublicMainLayoutDataAction::run(
            $this->layout,
            $this->page,
            $pageSlot,
            $this->theme,
            $this->containerClass,
            $this->mainClass,
            $this->mainContainerClass,
        );
    }

    #[Override]
    public function render(): View
    {
        return $this->view->make('capell::components.layout.index');
    }
}
