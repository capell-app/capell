<div @if(! $visible) style="display:none" @endif>
    @if ($visible)
        <div
            class="mb-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
        >
            <div class="mb-2 flex items-center gap-2">
                <x-heroicon-o-user-circle class="h-5 w-5 text-gray-500" />
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ $title }}
                </h3>
            </div>

            @if ($approvals->isNotEmpty())
                <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($approvals as $approval)
                        <li class="flex gap-2">
                            <span class="font-medium">
                                {{ $approval->action->getLabel() }}
                            </span>
                            @if ($approval->notes)
                                <span class="text-gray-500">
                                    — {{ $approval->notes }}
                                </span>
                            @endif

                            <span class="ml-auto text-xs text-gray-400">
                                {{ $approval->created_at?->diffForHumans() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
