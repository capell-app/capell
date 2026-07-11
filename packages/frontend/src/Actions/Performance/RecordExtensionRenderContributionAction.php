<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions\Performance;

use Capell\Frontend\Data\Performance\ExtensionRenderContributionData;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordExtensionRenderContributionAction
{
    use AsAction;

    private const string REQUEST_ATTRIBUTE = 'capell.frontend.extension_render_contributions';

    /**
     * @param  list<string>  $cacheTags
     * @param  list<string>  $variesBy
     */
    public function handle(
        string $packageName,
        string $surface,
        string $contributionType,
        ?string $contributionClass,
        float $elapsedMilliseconds,
        int $frontendRenderBudgetMs,
        array $cacheTags,
        bool $cacheable,
        bool $sensitiveOutput,
        array $variesBy,
    ): ExtensionRenderContributionData {
        $record = new ExtensionRenderContributionData(
            packageName: $packageName,
            surface: $surface,
            contributionType: $contributionType,
            contributionClass: $contributionClass,
            elapsedMilliseconds: round($elapsedMilliseconds, 3),
            frontendRenderBudgetMs: $frontendRenderBudgetMs,
            cacheTags: $cacheTags,
            cacheable: $cacheable,
            sensitiveOutput: $sensitiveOutput,
            variesBy: $variesBy,
            budgetExceeded: $frontendRenderBudgetMs > 0 && $elapsedMilliseconds > $frontendRenderBudgetMs,
        );

        $records = $this->recorded();
        $records[] = $record;

        $this->request()->attributes->set(self::REQUEST_ATTRIBUTE, $records);

        return $record;
    }

    /** @return list<ExtensionRenderContributionData> */
    public function recorded(): array
    {
        $records = $this->request()->attributes->get(self::REQUEST_ATTRIBUTE, []);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(
            $records,
            static fn (mixed $record): bool => $record instanceof ExtensionRenderContributionData,
        ));
    }

    public function clear(): void
    {
        $this->request()->attributes->remove(self::REQUEST_ATTRIBUTE);
    }

    private function request(): Request
    {
        return request();
    }
}
