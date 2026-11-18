@props(['image', 'loop'])
<figure {{ $attributes }}>
    <x-capell::image-source
        :image="$image"
        alt="Custom & preserved"
    />
    <figcaption>Item {{ $loop->iteration }}</figcaption>
</figure>
