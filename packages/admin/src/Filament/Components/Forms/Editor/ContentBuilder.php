<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Editor;

use Capell\Admin\Actions\Widgets\MergeContentWidgetSettingsAction;
use Capell\Admin\Actions\Widgets\NormalizeContentWidgetStateAction;
use Capell\Admin\Actions\Widgets\PruneBlankWidgetSettingsAction;
use Capell\Admin\Actions\Widgets\RegenerateContentWidgetIdentitiesAction;
use Capell\Admin\Exceptions\ContentWidgetStateTraversalLimitExceeded;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Components\Forms\Interactions\InteractionSettingsSchema;
use Capell\Admin\Filament\Components\Forms\Presentation\PresentationSettingsSchema;
use Capell\Admin\Filament\Components\Forms\Presentation\ResourceSettingsSchema;
use Capell\Admin\Support\Widgets\BoundedContentWidgetStateTraversal;
use Capell\Admin\Support\Widgets\UnavailableContentWidgetState;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Core\Models\BlockTemplate;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Throwable;

class ContentBuilder extends Builder
{
    /**
     * @var array<string, array{label: string, blocks: list<array{type: string, data?: array<string, mixed>}|array<string, mixed>>}>|Closure|null
     */
    private array|Closure|null $blockTemplates = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.content'))
            ->collapsible()
            ->cloneable()
            ->cloneAction(fn (Action $action): Action => $action->action($this->cloneItem(...)))
            ->columnSpanFull()
            ->reorderableWithButtons()
            ->blockPickerColumns(['md' => 2])
            ->blockPickerWidth('2xl')
            ->addAction(fn (Action $action): Action => $action->outlined()->size('sm'))
            ->addActionLabel(__('capell-admin::button.add_content_block'))
            ->hintAction($this->insertBlockTemplateAction())
            ->default([['type' => 'content', 'data' => ['content' => '']]])
            ->afterStateHydrated(function (ContentBuilder $component): void {
                $component->hydrateItems();

                $state = $component->getRawState();
                $component->rawState($this->prepareStateForAuthoring(is_array($state) ? $state : []));
            })
            ->mutateDehydratedStateUsing(function (?array $state): array {
                $restoredState = UnavailableContentWidgetState::restore(
                    $state ?? [],
                    $this->registeredWidgetKeys(),
                );
                $normalizedState = NormalizeContentWidgetStateAction::run($restoredState);

                return $this->stripEmptyPresentationState($normalizedState);
            })
            ->extraItemActions([
                $this->settingsItemAction(),
            ])
            ->blocks($this->filamentWidgetsWithPresentationSettings());
    }

    public function withDefault(): static
    {
        $this->default([['type' => 'content', 'data' => ['content' => '']]]);

        return $this;
    }

    /**
     * @param  array<string, array{label: string, blocks: list<array{type: string, data?: array<string, mixed>}|array<string, mixed>>}>|Closure|null  $templates
     */
    public function blockTemplates(array|Closure|null $templates): static
    {
        $this->blockTemplates = $templates;

        return $this;
    }

    /**
     * @return array<string, array{label: string, blocks: list<array{type: string, data: array<string, mixed>}>}>
     */
    public function getBlockTemplates(?Builder $component = null): array
    {
        $configuredTemplates = $this->evaluate($this->blockTemplates) ?? $this->defaultBlockTemplates();

        if (! is_array($configuredTemplates)) {
            return [];
        }

        $templates = array_merge($configuredTemplates, $this->persistedBlockTemplates());

        $availableBlocks = collect(($component ?? $this)->getBlocks())
            ->map(fn (Block $block): string => $block->getName())
            ->flip();

        $resolved = [];

        foreach ($templates as $key => $template) {
            if (! is_string($key)) {
                continue;
            }

            if (! is_array($template)) {
                continue;
            }

            $label = $template['label'] ?? null;
            $blocks = $template['blocks'] ?? null;
            if (! is_string($label)) {
                continue;
            }

            if (! is_array($blocks)) {
                continue;
            }

            $templateBlocks = [];

            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue 2;
                }

                $type = $block['type'] ?? null;
                if (! is_string($type)) {
                    continue 2;
                }

                if (! $availableBlocks->has($type)) {
                    continue 2;
                }

                $data = $block['data'] ?? [];

                if (! is_array($data)) {
                    continue 2;
                }

                $templateBlocks[] = [
                    'type' => $type,
                    'data' => $data,
                ];
            }

            if ($templateBlocks === []) {
                continue;
            }

            $resolved[$key] = [
                'label' => $label,
                'blocks' => $templateBlocks,
            ];
        }

        return $resolved;
    }

    /**
     * @return array<int, Block>
     */
    private function filamentWidgetsWithPresentationSettings(): array
    {
        $blocks = collect(CapellAdmin::getFilamentWidgets())
            ->map(function (Block $block): Block {
                $childComponents = $this->rawDefaultChildComponents($block);

                if ($block->getName() === 'content') {
                    return $block->schema(fn (): array => $this->resolveChildComponents($block, $childComponents));
                }

                return $block->schema(
                    fn (): array => [
                        ...$this->resolveChildComponents($block, $childComponents),
                    ],
                );
            })
            ->values()
            ->all();

        $blocks[] = Block::make(UnavailableContentWidgetState::PLACEHOLDER_TYPE)
            ->label(__('capell-admin::widget.unavailable'))
            ->icon('heroicon-o-exclamation-triangle')
            ->schema([
                Text::make(__('capell-admin::widget.unavailable_help')),
            ]);

        return $blocks;
    }

    private function insertBlockTemplateAction(): Action
    {
        return Action::make('insertBlockTemplate')
            ->label(__('capell-admin::button.insert_block_template'))
            ->icon('heroicon-o-sparkles')
            ->link()
            ->modalHeading(__('capell-admin::heading.insert_block_template'))
            ->modalSubmitActionLabel(__('capell-admin::button.insert_template'))
            ->visible(fn (Builder $component): bool => $this->getBlockTemplateOptions($component) !== [])
            ->schema([
                Select::make('template')
                    ->label(__('capell-admin::form.block_template'))
                    ->options(fn (): array => $this->getBlockTemplateOptions($this))
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data, Builder $component): void {
                $templateKey = $data['template'] ?? null;

                if (! is_string($templateKey)) {
                    return;
                }

                $template = $this->getBlockTemplates($component)[$templateKey] ?? null;

                if (! is_array($template)) {
                    return;
                }

                $state = $component->getState();

                if (! is_array($state)) {
                    $state = [];
                }

                foreach ($template['blocks'] as $block) {
                    $state[(string) Str::uuid()] = $block;
                }

                $component->state($this->prepareStateForAuthoring($state));
            });
    }

    private function settingsItemAction(): Action
    {
        return Action::make('settings')
            ->label(__('capell-admin::form.settings'))
            ->icon('heroicon-o-cog-6-tooth')
            ->modalHeading(__('capell-admin::heading.container_widget_settings'))
            ->modalSubmitActionLabel(__('capell-admin::button.done'))
            ->visible(fn (array $arguments, Builder $component): bool => $this->hasSettingsForItem($arguments, $component))
            ->schema([
                ...InteractionSettingsSchema::make('interactions'),
                ...PresentationSettingsSchema::make('presentation'),
                ...ResourceSettingsSchema::make('resources'),
            ])
            ->fillForm(fn (array $arguments, Builder $component): array => $this->settingsFormState($arguments, $component))
            ->action(function (array $arguments, array $data, Builder $component): void {
                $state = $component->getState();
                $itemKey = $arguments['item'] ?? null;

                if (! is_string($itemKey) || ! isset($state[$itemKey]) || ! is_array($state[$itemKey])) {
                    return;
                }

                $widgetData = is_array($state[$itemKey]['data'] ?? null)
                    ? $state[$itemKey]['data']
                    : [];
                $state[$itemKey]['data'] = MergeContentWidgetSettingsAction::run($widgetData, $data);

                $component->state($state);
            });
    }

    /** @param array<string, mixed> $arguments */
    private function cloneItem(array $arguments, Builder $component): void
    {
        $itemKey = $arguments['item'] ?? null;
        $items = $component->getRawState();

        if ((! is_string($itemKey) && ! is_int($itemKey))
            || ! is_array($items)
            || ! is_array($items[$itemKey] ?? null)) {
            return;
        }

        $clonedItem = RegenerateContentWidgetIdentitiesAction::run($items[$itemKey]);
        $newItemKey = $component->generateUuid();

        if ($newItemKey) {
            $items[$newItemKey] = $clonedItem;
        } else {
            $items[] = $clonedItem;
        }

        $component->rawState($items);
        $component->collapsed(false, shouldMakeComponentCollapsible: false);
        $component->callAfterStateUpdated();
        if ($component->shouldPartiallyRenderAfterActionsCalled()) {
            $component->partiallyRender();
        }
    }

    /**
     * @return array<string, string>
     */
    private function getBlockTemplateOptions(Builder $component): array
    {
        return collect($this->getBlockTemplates($component))
            ->mapWithKeys(fn (array $template, string $key): array => [$key => $template['label']])
            ->all();
    }

    /**
     * @return array<string, array{label: string, blocks: list<array{type: string, data: array<string, mixed>}>}>
     */
    private function defaultBlockTemplates(): array
    {
        return [
            'content_section' => [
                'label' => __('capell-admin::form.block_template_content_section'),
                'blocks' => [
                    ['type' => 'content', 'data' => ['content' => '']],
                ],
            ],
            'content_stack' => [
                'label' => __('capell-admin::form.block_template_content_stack'),
                'blocks' => [
                    ['type' => 'content', 'data' => ['content' => '']],
                    ['type' => 'content', 'data' => ['content' => '']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, blocks: list<array{type: string, data: array<string, mixed>}>}>
     */
    private function persistedBlockTemplates(): array
    {
        try {
            if (! resolve(RuntimeSchemaState::class)->hasTable((new BlockTemplate)->getTable())) {
                return [];
            }

            return BlockTemplate::query()
                ->enabled()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(function (BlockTemplate $template): array {
                    $rawBlocks = is_array($template->blocks) ? $template->blocks : [];

                    $blocks = [];

                    foreach ($rawBlocks as $rawBlock) {
                        if (! is_array($rawBlock)) {
                            continue;
                        }

                        $blocks[] = [
                            'type' => is_string($rawBlock['type'] ?? null) ? $rawBlock['type'] : '',
                            'data' => is_array($rawBlock['data'] ?? null) ? $rawBlock['data'] : [],
                        ];
                    }

                    return [
                        $template->key => [
                            'label' => $template->name,
                            'blocks' => $blocks,
                        ],
                    ];
                })
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function hasSettingsForItem(array $arguments, Builder $component): bool
    {
        $itemKey = $arguments['item'] ?? null;

        if (! is_string($itemKey)) {
            return false;
        }

        $item = $component->getRawItemState($itemKey);

        return ! in_array(
            $item['type'] ?? null,
            ['content', UnavailableContentWidgetState::PLACEHOLDER_TYPE],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function settingsFormState(array $arguments, Builder $component): array
    {
        $itemKey = $arguments['item'] ?? null;

        if (! is_string($itemKey)) {
            return [];
        }

        $item = $component->getRawItemState($itemKey);
        $state = $item['data']['__capell'] ?? null;

        return is_array($state) ? $state : [];
    }

    private function rawDefaultChildComponents(Block $block): mixed
    {
        return Closure::bind(
            fn (): mixed => $this->childComponents['default'] ?? [],
            $block,
            $block,
        )();
    }

    /**
     * @return array<int, mixed>
     */
    private function resolveChildComponents(Block $block, mixed $childComponents): array
    {
        $components = $childComponents instanceof Closure
            ? $block->evaluate($childComponents)
            : $childComponents;

        if ($components instanceof Schema) {
            return $components->getComponents();
        }

        return is_array($components) ? $components : [];
    }

    /**
     * @param  array<int|string, mixed>  $state
     * @return array<int|string, mixed>
     */
    private function stripEmptyPresentationState(array $state): array
    {
        $registeredWidgetKeys = array_fill_keys($this->registeredWidgetKeys(), true);

        try {
            $state = BoundedContentWidgetStateTraversal::transform(
                $state,
                fn (array $node): array => $this->stripWidgetPresentationNode(
                    $node,
                    $registeredWidgetKeys,
                ),
                static fn (array $node): bool => ! is_string($node['type'] ?? null)
                    || isset($registeredWidgetKeys[$node['type']]),
            );
        } catch (ContentWidgetStateTraversalLimitExceeded) {
            return array_values($state);
        }

        return array_values($state);
    }

    /**
     * @param  array<int|string, mixed>  $state
     * @param  array<string, true>  $registeredWidgetKeys
     * @return array<int|string, mixed>
     */
    private function stripWidgetPresentationNode(array $state, array $registeredWidgetKeys): array
    {
        $widgetKey = $state['type'] ?? null;

        if (is_string($widgetKey) && isset($registeredWidgetKeys[$widgetKey])) {
            $capellState = $state['data']['__capell'] ?? null;

            if (is_array($capellState)) {
                $capellState = $this->stripEmptyOptionalSettings($capellState);

                if ($capellState === []) {
                    unset($state['data']['__capell']);
                } else {
                    $state['data']['__capell'] = $capellState;
                }
            }
        }

        return $state;
    }

    /**
     * @param  array<int|string, mixed>  $state
     * @return array<int|string, mixed>
     */
    private function prepareStateForAuthoring(array $state): array
    {
        $normalizedState = NormalizeContentWidgetStateAction::run($state);

        return UnavailableContentWidgetState::prepare($normalizedState, $this->registeredWidgetKeys());
    }

    /** @return list<string> */
    private function registeredWidgetKeys(): array
    {
        return array_keys(resolve(WidgetDiscovery::class)->registeredWidgets());
    }

    /**
     * @param  array<int|string, mixed>  $capellState
     * @return array<int|string, mixed>
     */
    private function stripEmptyOptionalSettings(array $capellState): array
    {
        foreach (['presentation', 'interactions', 'resources'] as $optionalSettingsKey) {
            $value = $capellState[$optionalSettingsKey] ?? null;

            if (! is_array($value)) {
                continue;
            }

            $value = PruneBlankWidgetSettingsAction::run($value);

            if ($value === []) {
                unset($capellState[$optionalSettingsKey]);

                continue;
            }

            $capellState[$optionalSettingsKey] = $value;
        }

        return $capellState;
    }
}
