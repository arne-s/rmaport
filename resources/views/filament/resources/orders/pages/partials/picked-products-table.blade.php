@php
    use App\Models\OrderProduct;
    use Illuminate\Support\Collection;

    /** @var Collection<int, OrderProduct> $pickedProducts */
@endphp

<section class="card inkoopTab__table mt-6" id="card-picked-products">
    <h3 class="card__title">Gepickte producten (uit voorraad)</h3>
    @if ($pickedProducts->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="fi-ta-table w-full">
                <thead>
                    <tr class="fi-ta-table-head-groups-row">
                        <th class="fi-ta-header-cell px-3 py-3 text-start text-sm font-semibold">Artikelnaam</th>
                        <th class="fi-ta-header-cell px-3 py-3 text-start text-sm font-semibold">Artikelcode</th>
                        <th class="fi-ta-header-cell px-3 py-3 text-start text-sm font-semibold">Aantal</th>
                        <th class="fi-ta-header-cell px-3 py-3 text-start text-sm font-semibold">Status</th>
                        <th class="fi-ta-header-cell px-3 py-3 text-start text-sm font-semibold">Voorraad</th>
                        <th class="fi-ta-header-cell px-3 py-3 text-start text-sm font-semibold">Leverancier</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pickedProducts as $orderProduct)
                        <tr class="fi-ta-table-row">
                            <td class="fi-ta-cell px-3 py-2">{{ $orderProduct->product?->name ?? $orderProduct->value }}</td>
                            <td class="fi-ta-cell px-3 py-2">{{ $orderProduct->product?->uid ?? '-' }}</td>
                            <td class="fi-ta-cell px-3 py-2">{{ $orderProduct->qty }}</td>
                            <td class="fi-ta-cell px-3 py-2">{{ $orderProduct->status?->getLabel() ?? '-' }}</td>
                            <td class="fi-ta-cell px-3 py-2">{{ $orderProduct->product?->stock?->getPhysicalStock() ?? 0 }}</td>
                            <td class="fi-ta-cell px-3 py-2">{{ $orderProduct->supplier?->name ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400 py-4">Geen gepickte producten.</p>
    @endif
</section>
