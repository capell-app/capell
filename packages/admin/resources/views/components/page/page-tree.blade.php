@php
    use Capell\Admin\Actions\Pages\BuildPageTreeViewDataAction;
    use Capell\Admin\Support\PageUrlPresenter;
    use Capell\Core\Models\Page;

    /** @var Page $record */
    $record = $getRecord();

    $viewData = BuildPageTreeViewDataAction::run($record);
    $home = $viewData['home'];

    if (! $home || $home->id === $record->id) {
        return '';
    }

    $siblings = $viewData['siblings'];
    $children = $viewData['children'];
    $ancestors = $viewData['ancestors'];
    $resourceClass = $viewData['resourceClass'];
    $resourceIcon = $viewData['resourceIcon'];
@endphp

<x-filament::section compact>
    <div class="flex flex-col divide-y divide-gray-100 dark:divide-gray-700">
        @if ($home)
            <x-capell-admin::page.page-tree-item
                :page="$home"
                :resourceClass="$resourceClass"
                :resourceIcon="$resourceIcon"
                :url="PageUrlPresenter::fullUrl($home->pageUrl)"
            />
        @endif

        @foreach ($ancestors as $ancestor)
            <x-capell-admin::page.page-tree-item
                :page="$ancestor"
                :resourceClass="$resourceClass"
                :resourceIcon="$resourceIcon"
                :url="PageUrlPresenter::fullUrl($ancestor->pageUrl)"
            />
        @endforeach

        <div
            class="flex flex-col divide-y divide-gray-100 pl-4 dark:divide-gray-700"
        >
            <x-capell-admin::page.page-tree-item
                :page="$record"
                :resourceClass="$resourceClass"
                :resourceIcon="$resourceIcon"
                :url="PageUrlPresenter::fullUrl($record->pageUrl)"
                color="primary"
                size="lg"
            />

            @if ($children->isNotEmpty())
                <div
                    class="flex flex-col divide-y divide-gray-100 pl-4 dark:divide-gray-700"
                >
                    @svg('heroicon-c-arrow-uturn-left', 'rotate-45')
                    @foreach ($children as $child)
                        <x-capell-admin::page.page-tree-item
                            :page="$child"
                            :resourceClass="$resourceClass"
                            :resourceIcon="$resourceIcon"
                            :url="PageUrlPresenter::fullUrl($child->pageUrl)"
                        />
                    @endforeach
                </div>
            @endif
        </div>

        @if ($siblings->isNotEmpty())
            <div
                class="flex flex-col divide-y divide-gray-100 pl-4 dark:divide-gray-700"
            >
                @foreach ($siblings as $sibling)
                    <x-capell-admin::page.page-tree-item
                        :page="$sibling"
                        :resourceClass="$resourceClass"
                        :resourceIcon="$resourceIcon"
                        :url="PageUrlPresenter::fullUrl($sibling->pageUrl)"
                    />
                @endforeach
            </div>
        @endif
    </div>
</x-filament::section>
