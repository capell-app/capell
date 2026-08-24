<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;
use Capell\Admin\Filament\Components\Forms\Editor\ContentBuilder;
use Capell\Admin\Support\Widgets\UnavailableContentWidgetState;
use Capell\Admin\Support\Widgets\WidgetDiscovery;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Admin\Tests\Fixtures\Widgets\ContentBlockPickerMetadataProvider;
use Capell\Admin\Tests\Fixtures\Widgets\DuplicateContentBlockPickerMetadataProvider;
use Capell\Admin\Tests\Fixtures\Widgets\StateIntegrityFilamentWidget;
use Capell\Core\Models\BlockTemplate;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('configures accessible block authoring controls', function (): void {
    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([
            ContentBuilder::make('content'),
        ]);

    $builder = $schema->getComponents()[0];

    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    expect($builder->isCloneable())->toBeTrue()
        ->and($builder->isReorderableWithButtons())->toBeTrue()
        ->and($builder->getBlockPickerColumns('md'))->toBe(2)
        ->and($builder->getBlockPickerWidth())->toBe('2xl')
        ->and(collect($builder->getHintActions())->map->getName()->all())->toContain('insertBlockTemplate')
        ->and($builder->getBlockTemplates())->toHaveKeys(['content_section', 'content_stack']);
});

it('moves widget presentation settings into a builder item action', function (): void {
    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([
            ContentBuilder::make('content'),
        ]);

    $builder = $schema->getComponents()[0];

    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    $widgetBlock = collect($builder->getBlocks())
        ->first(fn (Block $block): bool => $block->getName() !== 'content');

    throw_unless($widgetBlock instanceof Block, RuntimeException::class, 'Expected a non-content widget block.');

    $headings = collect($widgetBlock->getChildComponents())
        ->map(fn (object $component): ?string => method_exists($component, 'getHeading')
            ? componentText($component->getHeading())
            : null)
        ->filter()
        ->values()
        ->all();

    expect($builder->getExtraItemActions())->toHaveKey('settings')
        ->and($headings)->not->toContain(
            __('capell-admin::form.interactions'),
            __('capell-admin::form.presentation_delivery'),
            __('capell-admin::form.frontend_resources'),
        );
});

it('allows block templates to be customised for registered blocks', function (): void {
    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([
            ContentBuilder::make('content')
                ->blockTemplates([
                    'valid_stack' => [
                        'label' => 'Valid stack',
                        'blocks' => [
                            ['type' => 'content', 'data' => ['content' => '<p>Intro</p>']],
                            ['type' => 'content', 'data' => ['content' => '']],
                        ],
                    ],
                    'unknown_widget' => [
                        'label' => 'Unknown widget',
                        'blocks' => [
                            ['type' => 'missing-widget', 'data' => []],
                        ],
                    ],
                ]),
        ]);

    $builder = $schema->getComponents()[0];

    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    expect($builder->getBlockTemplates())->toHaveKey('valid_stack')
        ->and($builder->getBlockTemplates())->not->toHaveKey('unknown_widget')
        ->and($builder->getBlockTemplates()['valid_stack']['blocks'])->toHaveCount(2);
});

it('normalizes malformed persisted block templates without losing valid blocks', function (): void {
    BlockTemplate::factory()->createOne([
        'key' => 'malformed',
        'name' => 'Malformed',
        'blocks' => [
            'invalid',
            ['type' => 'content', 'data' => 'invalid'],
        ],
    ]);

    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([
            ContentBuilder::make('content')->blockTemplates([
                'unknown_block' => [
                    'label' => 'Unknown block',
                    'blocks' => [['type' => 'vendor.unknown', 'data' => []]],
                ],
                'empty_blocks' => [
                    'label' => 'Empty blocks',
                    'blocks' => [],
                ],
                'valid' => [
                    'label' => 'Valid',
                    'blocks' => [['type' => 'content']],
                ],
            ]),
        ]);

    $builder = $schema->getComponents()[0];

    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    expect($builder->getBlockTemplates())->toBe([
        'valid' => [
            'label' => 'Valid',
            'blocks' => [['type' => 'content', 'data' => []]],
        ],
        'malformed' => [
            'label' => 'Malformed',
            'blocks' => [['type' => 'content', 'data' => []]],
        ],
    ]);
});

it('resolves insert block template modal options from translated content builders', function (): void {
    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([
            ContentBuilder::make('translations.record-125.content')
                ->blockTemplates([
                    'translated_stack' => [
                        'label' => 'Translated stack',
                        'blocks' => [
                            ['type' => 'content', 'data' => ['content' => '<p>Translated</p>']],
                        ],
                    ],
                ]),
        ]);

    $builder = $schema->getComponents()[0];

    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    $action = collect($builder->getHintActions())
        ->first(fn (object $action): bool => method_exists($action, 'getName') && $action->getName() === 'insertBlockTemplate');

    throw_unless($action instanceof Action, RuntimeException::class, 'Expected insert block template action.');

    $actionSchema = $action
        ->schemaComponent($builder)
        ->getSchema(Schema::make(Livewire::make()));

    throw_unless($actionSchema instanceof Schema, RuntimeException::class, 'Expected insert block template action schema.');

    $templateSelect = $actionSchema->getComponents()[0] ?? null;

    throw_unless($templateSelect instanceof Select, RuntimeException::class, 'Expected template select.');

    expect($templateSelect->getOptions())->toBe([
        'translated_stack' => 'Translated stack',
    ]);
});

it('includes enabled persisted block templates', function (): void {
    BlockTemplate::factory()->createOne([
        'key' => 'persisted_cta',
        'name' => 'Persisted CTA',
        'blocks' => [
            ['type' => 'content', 'data' => ['content' => '<p>Call to action</p>']],
        ],
    ]);

    BlockTemplate::factory()->disabled()->createOne([
        'key' => 'disabled_template',
        'name' => 'Disabled template',
        'blocks' => [
            ['type' => 'content', 'data' => ['content' => '<p>Disabled</p>']],
        ],
    ]);

    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([
            ContentBuilder::make('content'),
        ]);

    $builder = $schema->getComponents()[0];

    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    expect($builder->getBlockTemplates())->toHaveKey('persisted_cta')
        ->and($builder->getBlockTemplates())->not->toHaveKey('disabled_template')
        ->and($builder->getBlockTemplates()['persisted_cta']['label'])->toBe('Persisted CTA');
});

it('hydrates unavailable widgets as opaque placeholders and restores them exactly on dehydration', function (): void {
    $unknownWidget = [
        'type' => 'vendor.missing-widget',
        'data' => [
            '__capell' => ['instance_id' => 'extension-owned-value'],
            'payload' => ['nested' => true],
        ],
        'extension-owned-key' => 'preserved',
    ];
    $livewire = Livewire::make();
    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([ContentBuilder::make('content')]);

    $schema->fill(['content' => [$unknownWidget]]);

    $builder = $schema->getComponents()[0];
    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    $hydratedWidget = array_values($builder->getRawState())[0];

    expect($hydratedWidget['type'])->toBe(UnavailableContentWidgetState::PLACEHOLDER_TYPE)
        ->and(collect($builder->getBlocks())->map->getName()->all())
        ->toContain(UnavailableContentWidgetState::PLACEHOLDER_TYPE)
        ->and($schema->getState()['content'])->toBe([$unknownWidget]);
});

it('normalizes registered widget identities during hydration and dehydration', function (): void {
    resolve(WidgetDiscovery::class)->register(StateIntegrityFilamentWidget::class);

    $duplicateIdentity = (string) Str::uuid();
    $livewire = Livewire::make();
    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([ContentBuilder::make('content')]);

    $schema->fill(['content' => [
        ['type' => 'capell-app.state-integrity', 'data' => ['__capell' => ['instance_id' => $duplicateIdentity]]],
        ['type' => 'capell-app.state-integrity', 'data' => ['__capell' => ['instance_id' => $duplicateIdentity]]],
    ]]);

    $dehydratedWidgets = $schema->getState()['content'];
    $identities = array_column(array_column(array_column($dehydratedWidgets, 'data'), '__capell'), 'instance_id');

    expect($identities[0])->toBe($duplicateIdentity)
        ->and($identities[1])->not->toBe($duplicateIdentity)
        ->and(Str::isUuid($identities[1]))->toBeTrue();
});

it('regenerates root and nested identities immediately when a widget is cloned', function (): void {
    resolve(WidgetDiscovery::class)->register(StateIntegrityFilamentWidget::class);

    $rootIdentity = (string) Str::uuid();
    $nestedIdentity = (string) Str::uuid();
    $livewire = Livewire::make();
    $schema = Schema::make($livewire)
        ->statePath('data')
        ->components([ContentBuilder::make('content')]);

    $schema->fill(['content' => [[
        'type' => 'capell-app.state-integrity',
        'data' => [
            '__capell' => ['instance_id' => $rootIdentity],
            'interaction' => [
                'target_widget' => [
                    'type' => 'capell-app.state-integrity',
                    'data' => ['__capell' => ['instance_id' => $nestedIdentity]],
                ],
            ],
        ],
    ]]]);

    $builder = $schema->getComponents()[0];
    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    $originalItemKey = array_key_first($builder->getRawState());
    $builder->getCloneAction()->call([
        'arguments' => ['item' => $originalItemKey],
        'component' => $builder,
    ]);

    $builder->getDeleteAction()->call([
        'arguments' => ['item' => $originalItemKey],
        'component' => $builder,
    ]);
    $survivingClone = array_values($builder->getRawState())[0];

    expect($survivingClone['data']['__capell']['instance_id'])->not->toBe($rootIdentity)
        ->and(Str::isUuid($survivingClone['data']['__capell']['instance_id']))->toBeTrue()
        ->and($survivingClone['data']['interaction']['target_widget']['data']['__capell']['instance_id'])
        ->not->toBe($nestedIdentity)
        ->and(Str::isUuid(
            $survivingClone['data']['interaction']['target_widget']['data']['__capell']['instance_id'],
        ))->toBeTrue();
});

it('strips empty optional presentation state without stripping reserved widget metadata', function (): void {
    resolve(WidgetDiscovery::class)->register(StateIntegrityFilamentWidget::class);

    $identity = (string) Str::uuid();
    $builder = ContentBuilder::make('content');
    $dehydrated = $builder->mutateDehydratedState([
        [
            'type' => 'capell-app.state-integrity',
            'data' => [
                '__capell' => [
                    'instance_id' => $identity,
                    'state_version' => 2,
                    'presentation' => ['width' => ''],
                    'interactions' => [],
                ],
            ],
        ],
    ]);

    expect($dehydrated[0]['data']['__capell'])->toBe([
        'instance_id' => $identity,
        'state_version' => 2,
    ]);
});

it('never strips reserved widget metadata before an extension processor can assess it', function (): void {
    resolve(WidgetDiscovery::class)->register(StateIntegrityFilamentWidget::class);

    $builder = ContentBuilder::make('content');
    $dehydrated = $builder->mutateDehydratedState([
        [
            'type' => 'capell-app.state-integrity',
            'data' => [
                '__capell' => [
                    'instance_id' => '',
                    'state_version' => 0,
                    'presentation' => [],
                    'future_metadata' => ['empty' => ''],
                ],
            ],
        ],
    ]);

    expect(Str::isUuid($dehydrated[0]['data']['__capell']['instance_id']))->toBeTrue()
        ->and($dehydrated[0]['data']['__capell']['state_version'])->toBe(0)
        ->and($dehydrated[0]['data']['__capell']['future_metadata'])->toBe(['empty' => ''])
        ->and($dehydrated[0]['data']['__capell'])->not->toHaveKey('presentation');
});

it('renders the block picker with a searchable, accessible field and translated empty-state copy', function (): void {
    $html = renderBlockPicker(buildContentBuilder());

    expect($html)->toContain('type="search"')
        ->and($html)->toContain('aria-label="' . __('capell-admin::form.block_picker_search_label') . '"')
        ->and($html)->toContain(__('capell-admin::form.block_picker_search_placeholder'))
        ->and($html)->toContain(__('capell-admin::form.block_picker_empty_results'))
        ->and($html)->toContain(__('capell-admin::form.block_picker_reset_search'));
});

it('keeps every registered block reachable in the picker when no metadata provider is registered', function (): void {
    expect(app()->tagged(BlockPickerMetadataProvider::TAG))->toBeEmpty();

    $html = renderBlockPicker(buildContentBuilder());

    expect($html)->toContain('Content')
        ->and($html)->toContain(__('capell-admin::form.block_picker_uncategorized'))
        ->and($html)->toContain('fi-icon');
});

it('preserves the exact add-block action for every item regardless of contributed metadata', function (): void {
    $html = renderBlockPicker(buildContentBuilder());

    // Filament's own dropdown-list-item template builds this exact
    // `wire:click` string (unchanged from the original picker); CAP-0300 only
    // changes how items are grouped and filtered around it. Js::from() embeds
    // the arguments as a JSON.parse() call with \u-escaped quotes, built here
    // with strtr() so the test source never has to hand-type an ambiguous
    // backslash sequence.
    $expectedAction = strtr(
        "mountAction('add', JSON.parse('{QUOTEblockQUOTE:QUOTEcontentQUOTE}'), { schemaComponent: 'content' })",
        ['QUOTE' => '\\u0022'],
    );

    expect($html)->toContain($expectedAction);
});

it('renders label, description, category, and icon contributed by a tagged metadata provider', function (): void {
    app()->tag([ContentBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);

    $html = renderBlockPicker(buildContentBuilder());

    expect($html)->toContain('Rich content')
        ->and($html)->toContain('Freeform prose and inline media.')
        ->and($html)->toContain('Foundation blocks');
});

it('never renders a duplicate contribution for a block that already has metadata', function (): void {
    app()->tag([ContentBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);
    app()->tag([DuplicateContentBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);

    $html = renderBlockPicker(buildContentBuilder());

    expect($html)->toContain('Rich content')
        ->and($html)->not->toContain('Rich content (duplicate)')
        ->and($html)->not->toContain('This duplicate description must never render.')
        ->and($html)->not->toContain('Duplicate blocks');
});

it('keeps legacy widgets with no contributed metadata usable in the picker', function (): void {
    resolve(WidgetDiscovery::class)->register(StateIntegrityFilamentWidget::class);
    app()->tag([ContentBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);

    $legacyLabel = StateIntegrityFilamentWidget::make()->getLabel();
    $legacyLabelText = $legacyLabel instanceof Htmlable ? strip_tags($legacyLabel->toHtml()) : $legacyLabel;

    $html = renderBlockPicker(buildContentBuilder());

    expect($html)->toContain($legacyLabelText)
        ->and($html)->toContain(__('capell-admin::form.block_picker_uncategorized'))
        ->and($html)->toContain('Rich content');
});

it('exposes every picker item as a focusable, keyboard-activatable button', function (): void {
    $html = renderBlockPicker(buildContentBuilder());

    expect($html)->toContain('type="button"')
        ->and($html)->not->toContain('tabindex="-1"');
});

it('renders syntactically valid Alpine expressions throughout the block picker', function (): void {
    $html = renderBlockPicker(buildContentBuilder(), decodeAttributes: false);
    $expressions = alpineExpressions($html);

    expect($expressions)->not->toBeEmpty();

    $process = new Process([
        'node',
        '-e',
        <<<'JS'
const encodedExpressions = process.argv[1];
const expressions = JSON.parse(Buffer.from(encodedExpressions, 'base64').toString('utf8'));

for (const [attribute, expression] of expressions) {
    const isStatement = attribute.startsWith('x-on:') || ['x-effect', 'x-init'].includes(attribute);

    try {
        new Function(isStatement ? expression : `return (${expression})`);
    } catch (error) {
        throw new SyntaxError(`${attribute}: ${expression}\n${error.message}`, { cause: error });
    }
}
JS,
        base64_encode(json_encode($expressions, JSON_THROW_ON_ERROR)),
    ]);

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

/**
 * Builds a `ContentBuilder` inside a real Schema/Livewire container, matching
 * every other test in this file. Filament components resolve translations,
 * labels, and blocks via their container, so a standalone `::make()` call
 * fails with "Component::$container must not be accessed before
 * initialization" the moment the block picker reads a block's label or icon.
 */
function buildContentBuilder(): ContentBuilder
{
    $schema = Schema::make(Livewire::make())
        ->statePath('data')
        ->components([ContentBuilder::make('content')]);

    $builder = $schema->getComponents()[0];

    throw_unless($builder instanceof ContentBuilder, RuntimeException::class, 'Expected content builder.');

    return $builder;
}

/**
 * Calls the protected `generateBlockPickerHtml()` override directly with the
 * builder's own registered blocks, so assertions target exactly the method
 * CAP-0300 changed without depending on a full Livewire round trip.
 */
function renderBlockPicker(ContentBuilder $builder, bool $decodeAttributes = true): string
{
    $reflection = new ReflectionMethod($builder, 'generateBlockPickerHtml');

    $html = $reflection->invoke(
        $builder,
        Action::make('add'),
        $builder->getBlocks(),
        'content',
        '<button type="button">Add content block</button>',
        null,
        null,
        null,
        null,
    );

    throw_unless(is_string($html), RuntimeException::class, 'Expected block picker HTML.');

    if ($decodeAttributes) {
        // Filament's attribute bag HTML-escapes `wire:click` values; decode so
        // assertions can compare against the literal action string it embeds.
        return html_entity_decode($html, ENT_QUOTES);
    }

    return $html;
}

/**
 * @return list<array{string, string}>
 */
function alpineExpressions(string $html): array
{
    if ($html === '') {
        throw new RuntimeException('Expected non-empty block picker HTML.');
    }

    $document = new DOMDocument;
    $previousUseInternalErrors = libxml_use_internal_errors(true);
    $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previousUseInternalErrors);

    $expressions = [];

    foreach ($document->getElementsByTagName('*') as $element) {
        foreach ($element->attributes as $attribute) {
            if (! str_starts_with($attribute->name, 'x-') || blank($attribute->value)) {
                continue;
            }

            $expressions[] = [$attribute->name, $attribute->value];
        }
    }

    return $expressions;
}

function componentText(mixed $value): string
{
    if ($value instanceof Htmlable) {
        return $value->toHtml();
    }

    if ($value instanceof Stringable) {
        return (string) $value;
    }

    return is_scalar($value) ? (string) $value : '';
}
