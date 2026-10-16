<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

/** Read prepared section HTML without mutating the graph or loading models. */
final readonly class PreparedLayoutGraph
{
    public function __construct(private ?object $graph) {}

    public function widgetHtml(string $containerKey, string $widgetKey, int $occurrence = 1): ?string
    {
        foreach (collect(data_get($this->graph, 'containers', [])) as $key => $container) {
            if ((string) data_get($container, 'key', $key) !== $containerKey) {
                continue;
            }

            foreach (collect(data_get($container, 'widgets', [])) as $widget) {
                if (data_get($widget, 'key') === $widgetKey
                    && (int) data_get($widget, 'occurrence', 1) === $occurrence) {
                    return $this->sectionsHtml($widget);
                }
            }
        }

        return null;
    }

    public function html(): ?string
    {
        $widgets = [];

        foreach (collect(data_get($this->graph, 'containers', [])) as $container) {
            foreach (collect(data_get($container, 'widgets', [])) as $widget) {
                $html = $this->sectionsHtml($widget);

                if ($html === null) {
                    return null;
                }

                $widgets[] = $html;
            }
        }

        return $widgets === [] ? null : implode("\n", $widgets);
    }

    private function sectionsHtml(mixed $widget): ?string
    {
        $sections = collect(data_get($widget, 'data.sections', []))
            ->filter(static fn (mixed $section): bool => is_array($section) && is_string($section['html'] ?? null) && trim($section['html']) !== '')
            ->sortBy(static fn (array $section): int => is_numeric($section['order'] ?? null) ? (int) $section['order'] : PHP_INT_MAX)
            ->pluck('html');

        return $sections->isEmpty() ? null : $sections->implode("\n");
    }
}
