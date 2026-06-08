@php
    /* @var int $id product id */
    /* @var int $supplier_id supplier id */
    $link = null;
    $userSelectable = null;

    if ($supplier_id) {
        $supplier = \App\Models\Supplier::find($supplier_id);
        $userSelectable = $supplier->isUserSelectable();

        if (!$userSelectable && $id) {
            $link = route('product.supplier', ['product' => $id]);
        }
    }
    if ($id) {
        $product = \App\Models\Product::find($id);
        $count = $product->productAttributeOptions->count();
    }
@endphp

@if (!$id && $userSelectable === false)
    <div style="margin-top: 5px; font-size: 12px;">
        Sla het product op om de koppeling te configureren.
    </div>
@endif

@if ($link)
    <div style="font-size: 12px;">
        @if (!$count)
            <div class="rounded-sm border border-red-600 bg-red-100 p-2 mb-4" style="margin-top: 5px;">
                ⚠️ <strong>Let op:</strong> de koppeling moet nog geconfigureerd worden (<a href="{{ $link }}" style="color: blue; text-decoration: underline;" target="_blank">Leverancier configureren</a>)
            </div>
        @else
            <a href="{{ $link }}" target=blank" style="display: block; color: blue; text-decoration: underline">Leverancier configureren</a>
        @endif
    </div>
@endif
