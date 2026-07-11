<div
    class="capell-component capell-no-results rounded-lg border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center text-sm font-medium text-slate-500"
>
    @svg('heroicon-o-magnifying-glass', 'mx-auto mb-3 h-5 w-5 text-slate-400')

    <div>
        {{ $slot->isNotEmpty() ? $slot : __('capell-frontend::generic.no_results') }}
    </div>
</div>
