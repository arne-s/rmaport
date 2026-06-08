<div class="breadcrumbs hidden-on-minimal-layout">
    <div>
        <a href="{{ route('home') }}" class="home">Home</a>
    </div>

    @if (isset($category->parentCategory))
        <div>
            <a href="{{ route('product.overview',['category' => $category->parentCategory->getSlug()]) }}"
            >{{ $category->parentCategory->getName() }}</a>
        </div>
    @endif
    @if (isset($category))
        <div>
            <a href="{{ route('product.overview',['category' => $category->getSlug()]) }}"
            >{{ $category->getName() }}</a>
        </div>
    @endif
    @if (isset($product))
        <div>
            <a href="{{ route('product.index',['category' => $category ?? $product->category, 'product' => $product]) }}"
            >{{ $product->getName() }}</a>
        </div>
    @endif
</div>
