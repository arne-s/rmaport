@props(['page', 'field' => 'contentBlocks', 'startImageAlign' => 'right', 'itemClass' => ''])

@foreach ($page->content[$field] as $block)
    <x-website.content-block
        :page="$page"
        :block="$block"
        :image-align="
            $loop->odd
                ? (
                    $startImageAlign === 'right'
                        ? 'right'
                        : 'left'
                ) : (
                    $startImageAlign === 'right'
                        ? 'left'
                        : 'right'
                )
        "
        :class="$itemClass"
    />
@endforeach
