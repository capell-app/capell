<?php

declare(strict_types=1);

use Capell\Admin\Data\MessageData;
use Capell\Admin\Enums\AlertTypeEnum;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\AdminZoneRegistry;
use Capell\Admin\Support\Agent\AgentPageReadinessWidget;
use Capell\Admin\Tests\Unit\Support\Pages\Fixtures\NonPageablePageForResolverTest;
use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Data\Properties\PropertyCompletenessData;
use Capell\Core\Models\Page;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Database\Eloquent\Model;

beforeEach(function (): void {
    resolve(AdminZoneRegistry::class)->clear();
    app()->forgetInstance(AdminRuntimeActivator::class);
    resolve(AdminRuntimeActivator::class)->prepare();
});

/** @return array<class-string<Widget>|WidgetConfiguration> */
function agentReadinessHeaderWidgets(Model $record): array
{
    $editor = new class($record) extends EditPage
    {
        public function __construct(private readonly Model $testRecord) {}

        #[Override]
        public function getHeaderWidgets(): array
        {
            return parent::getHeaderWidgets();
        }

        #[Override]
        public function getRecord(): Model
        {
            return $this->testRecord;
        }
    };

    return $editor->getHeaderWidgets();
}

it('contributes the readiness widget exactly once for a Core Page editor', function (): void {
    $widgets = agentReadinessHeaderWidgets(new Page);

    expect(array_keys($widgets, AgentPageReadinessWidget::class, true))->toHaveCount(1);
});

it('does not contribute the Page-only widget to a non-Page pageable editor', function (): void {
    $widgets = agentReadinessHeaderWidgets(new NonPageablePageForResolverTest);

    expect($widgets)->not->toContain(AgentPageReadinessWidget::class);
});

it('retains readiness warnings for incomplete Core Pages', function (): void {
    $page = new Page;
    EvaluatePropertyCompletenessAction::shouldRun()->once()->with($page)->andReturn(
        new PropertyCompletenessData(['product.price'], ['product.price', 'product.sku']),
    );
    $widget = new AgentPageReadinessWidget;
    $widget->record = $page;

    $alert = $widget->alerts()->get('agentReadiness');

    expect($alert)->toBeInstanceOf(MessageData::class);
    assert($alert instanceof MessageData);
    expect($alert->type)->toBe(AlertTypeEnum::Warning)
        ->and($alert->message)->toBe(__('capell-admin::agent.readiness_incomplete_message', [
            'properties' => 'product.price, product.sku',
        ]));
});

it('retains empty readiness alerts for complete Core Pages', function (): void {
    $page = new Page;
    EvaluatePropertyCompletenessAction::shouldRun()->once()->with($page)->andReturn(
        new PropertyCompletenessData([], []),
    );
    $widget = new AgentPageReadinessWidget;
    $widget->record = $page;

    expect($widget->alerts())->toBeEmpty();
});
