<nav
    class="fixture-theme-navigation"
    aria-label="Menu"
>
    <span>{{ $name }}</span>
    @foreach ($items as $item)
        <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
    @endforeach
</nav>
