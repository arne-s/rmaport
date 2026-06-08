@props([
    'companyView',
    'type',
    'qty',
    'name',
    'companyPurchasePrice',
    'companySalesPrice',
])
<tr class="type-{{ $type }}">
    <td class="qty">
        @if ($type !== 'included' && $type !== 'selected')
            {{ $qty }}
        @endif
    </td>
    <td class="name product">
        @if ($type === 'selected')
            <strong>Extra onderdeel </strong>
            <div class="indent">{{ strip_tags($name) }}</div>
        @else
            {{ strip_tags($name) }}
        @endif
    </td>
    @if (!$companyView)
        <td class="price">
            @money($companyPurchasePrice)
        </td>
        <td class="price">
            @money($companySalesPrice)
        </td>
        <td class="price margin">
            @money($companySalesPrice-$companyPurchasePrice)
            {{ formatMarginPercentage($companySalesPrice, $companyPurchasePrice) }}
        </td>
    @else
        <td> @money($companySalesPrice)</td>
    @endif

</tr>
