<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class FrontendResourceDebugOverlayScriptController extends Controller
{
    public function __invoke(): Response
    {
        $endpoint = route('capell-admin.api.frontend-resource-debug-overlay', [], false);
        $labels = [
            'title' => __('capell-admin::generic.frontend_resource_diagnostics'),
            'css' => __('capell-admin::generic.css'),
            'javascript' => __('capell-admin::generic.javascript'),
            'conflicts' => __('capell-admin::generic.resource_conflicts'),
            'budget' => __('capell-admin::generic.budget'),
            'passing' => __('capell-admin::generic.passing'),
            'needsAttention' => __('capell-admin::generic.needs_attention'),
            'close' => __('capell-admin::button.close'),
        ];

        $script = <<<'JS'
(() => {
    if (window.__capellFrontendResourceDebugOverlayLoaded) {
        return
    }

    window.__capellFrontendResourceDebugOverlayLoaded = true

    const endpoint = __ENDPOINT__
    const labels = __LABELS__

    const formatBytes = (bytes) => `${Number(bytes || 0).toLocaleString()} B`
    const text = (value) => {
        const node = document.createElement('span')
        node.textContent = value

        return node
    }

    const row = (label, value) => {
        const element = document.createElement('div')
        element.style.display = 'flex'
        element.style.justifyContent = 'space-between'
        element.style.gap = '16px'
        element.append(text(label), text(value))

        return element
    }

    const render = (payload) => {
        if (!payload || !payload.summary) {
            return
        }

        document.getElementById('capell-frontend-resource-debug-overlay')?.remove()

        const panel = document.createElement('aside')
        panel.id = 'capell-frontend-resource-debug-overlay'
        panel.style.position = 'fixed'
        panel.style.right = '16px'
        panel.style.bottom = '16px'
        panel.style.zIndex = '2147483647'
        panel.style.width = '320px'
        panel.style.maxWidth = 'calc(100vw - 32px)'
        panel.style.border = '1px solid rgba(148, 163, 184, 0.35)'
        panel.style.borderRadius = '8px'
        panel.style.background = 'rgba(15, 23, 42, 0.94)'
        panel.style.color = '#f8fafc'
        panel.style.boxShadow = '0 18px 50px rgba(15, 23, 42, 0.35)'
        panel.style.font = '12px/1.45 ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
        panel.style.padding = '12px'

        const heading = document.createElement('div')
        heading.textContent = labels.title
        heading.style.fontWeight = '700'
        heading.style.marginBottom = '8px'

        const close = document.createElement('button')
        close.type = 'button'
        close.textContent = labels.close
        close.style.position = 'absolute'
        close.style.top = '8px'
        close.style.right = '10px'
        close.style.border = '0'
        close.style.background = 'transparent'
        close.style.color = '#cbd5e1'
        close.style.cursor = 'pointer'
        close.addEventListener('click', () => panel.remove())

        const summary = document.createElement('div')
        summary.style.display = 'grid'
        summary.style.gap = '4px'
        summary.append(
            row(labels.css, `${payload.summary.cssAssets || 0} / ${formatBytes(payload.summary.cssGzipBytes)}`),
            row(labels.javascript, `${payload.summary.jsAssets || 0} / ${formatBytes(payload.summary.jsGzipBytes)}`),
            row(labels.conflicts, String((payload.conflicts || []).length)),
            row(labels.budget, payload.summary.budgetPasses ? labels.passing : labels.needsAttention),
        )

        const assets = document.createElement('div')
        assets.style.marginTop = '10px'
        assets.style.paddingTop = '8px'
        assets.style.borderTop = '1px solid rgba(148, 163, 184, 0.25)'
        assets.style.maxHeight = '180px'
        assets.style.overflow = 'auto'

        ;(payload.assets || []).slice(0, 12).forEach((asset) => {
            const item = document.createElement('div')
            item.style.marginTop = '6px'
            item.style.fontFamily = 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace'
            item.textContent = `${asset.kind || 'asset'}: ${asset.source || ''}`
            assets.append(item)
        })

        panel.append(heading, close, summary, assets)
        document.body.appendChild(panel)
    }

    const url = new URL(endpoint, window.location.origin)
    url.searchParams.set('url', window.location.href)

    fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })
        .then((response) => (response.ok ? response.json() : null))
        .then(render)
        .catch(() => {})
})()
JS;

        $script = str_replace(
            ['__ENDPOINT__', '__LABELS__'],
            [json_encode($endpoint, JSON_THROW_ON_ERROR), JsonCodec::encode($labels)],
            $script,
        );

        return response($script, Response::HTTP_OK)
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Cache-Control', 'private, no-store');
    }
}
