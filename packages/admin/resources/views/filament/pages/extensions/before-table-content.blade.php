@if ($content !== [])
    <div class="space-y-4">
        @foreach ($content as $extensionPageContent)
            {{ $extensionPageContent }}
        @endforeach
    </div>
@endif
