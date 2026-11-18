<?php

declare(strict_types=1);

namespace Capell\Frontend\View\Components;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Layout as LayoutModel;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\AssertStoredPublicViewSelectorsAction;
use Capell\Frontend\Actions\BuildPublicLayoutShellDataAction;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicLayoutShellData;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
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

    /** Renders the overridable main layout component with the frontend records, outside the shell view's data. */
    public function mainLayout(mixed $pageSlot): HtmlString
    {
        return new HtmlString(Blade::render(
            '<x-capell::layout.main :$layout :$page :$theme :page-slot="$pageSlot" :container-class="$containerClass" :main-class="$mainClass" :main-container-class="$mainContainerClass" />',
            [
                'layout' => $this->layout,
                'page' => $this->page,
                'theme' => $this->theme,
                'pageSlot' => $pageSlot,
                'containerClass' => $this->containerClass,
                'mainClass' => $this->mainClass,
                'mainContainerClass' => $this->mainContainerClass,
            ],
        ));
    }

    #[Override]
    public function render(): View
    {
        return $this->view->make('capell::components.layout.index');
    }
}
