@php
    $totalCredited = $this->credited;
    $vatPercentage = $this->vatPercentage;
    $vatFactor = 1 + ($vatPercentage / 100);

    $rawDiscount = $this->data['discount_amount_calc'] ?? 0;
    $discount = (float) str_replace(',', '.', (string) $rawDiscount);
    $discount = $discount * -1;

    $totalExVat = $totalCredited - $discount;
    $totalIncVat = $totalExVat * $vatFactor;
    $totalVat = $totalIncVat - $totalExVat;
@endphp
<div wire:key="totals-{{ $this->record->id }}" style="min-width: 400px;">
    <table class="table-auto w-full text-sm totalsAll">
        <tbody>
            <tr>
                <td class="py-1 pRightLarge">Subtotaal (excl. BTW)</td>
                <td></td>
                <td class="py-1 text-left">€ {{ number_format($totalCredited, 2, ',', '.') }}</td>
            </tr>

            <tr>
                <td colspan="3" class="py-2"><hr></td>
            </tr>

            @if ($this->record->getCompanySalesPriceDiscount() < 0)
                <tr>
                    <td class="py-1 align-middle pRightLarge">
                        <label for="korting-bedrag" class="block">Korting (excl. BTW)</label>
                    </td>
                    <td>
                        <span class="whitespace-nowrap" style="color: #333; margin-right: 5px;">€ +</span>
                    </td>
                    <td class="py-1">
                        <input
                            id="korting-bedrag"
                            type="number"
                            wire:model.blur="data.discount_amount_calc"
                            class="border-gray-300 rounded-sm shadow-xs text-sm"
                            placeholder="0,00"
                            style="width: 75px;"
                        />
                    </td>
                </tr>

                <tr>
                    <td colspan="3" class="py-2"><hr></td>
                </tr>
            @endif

            <tr>
                <td class="py-1 font-semibold pRightLarge">Totaalbedrag (excl. BTW)</td>
                <td></td>
                <td class="py-1 text-left font-semibold">€ {{ number_format($totalExVat, 2, ',', '.') }}</td>
            </tr>

            <tr>
                <td class="py-1 pRightLarge">{{ rtrim(rtrim(number_format($vatPercentage, 2, ',', ''), '0'), ',') }}% BTW</td>
                <td></td>
                <td class="py-1 text-left">€ {{ number_format($totalVat, 2, ',', '.') }}</td>
            </tr>

            <tr>
                <td colspan="3" class="py-2"><hr></td>
            </tr>

            <tr>
                <td class="py-1 font-semibold pRightLarge">Totaalbedrag (incl. BTW)</td>
                <td></td>
                <td class="py-1 text-left font-semibold">€ {{ number_format($totalIncVat, 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
</div>
