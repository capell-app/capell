@php
    use Capell\Frontend\Actions\PreparePageResultsSlotAction;

    $pageSlot = PreparePageResultsSlotAction::run($this, $renderData);
@endphp

<div class="capell-component capell-page-results capell-livewire-page-results">
    <x-capell::layout
        class="layout-results"
        :page-slot="$pageSlot"
    >
        {{ $pageSlot }}
    </x-capell::layout>
</div>
