@php
    /** @var array<string, float> $grand */
    /** @var array<string, int>|null $grandDiff */
    /** @var callable $formatQty */
    /** @var callable(int): string $formatDiff */
@endphp

<div class="unit-orders-table-footer border-t border-gray-200 bg-gray-50 py-2 pe-3 ps-0 dark:border-white/10 dark:bg-gray-800/50">
    <table class="w-full text-sm">
        <tfoot>
            <tr class="font-semibold text-gray-950 dark:text-white">
                <td class="py-2 pe-3 text-start" style="padding-left: 15px;">Eindtotaal</td>
                <td class="py-2 pe-3 text-start" style="width: 80px; max-width: 80px;"></td>
                @for ($m = 1; $m <= 12; $m++)
                    <td class="py-2 pe-3 text-start tabular-nums">{{ $formatQty($grand['m' . $m] ?? 0) }}</td>
                @endfor
                <td class="py-2 text-start tabular-nums" style="width: 34px; max-width: 34px;">{{ $formatQty($grand['total_all'] ?? 0) }}</td>
            </tr>
            @if ($grandDiff !== null)
                <tr class="border-t border-gray-200 text-gray-800 dark:border-white/10 dark:text-gray-200">
                    <td class="py-2 pe-3 text-start font-medium" style="padding-left: 15px; color: #a7a7a7; font-size: 12px;">Verschil met vorig jaar</td>
                    <td class="py-2 pe-3 text-start" style="width: 80px; max-width: 80px;"></td>
                    @for ($m = 1; $m <= 12; $m++)
                        <td class="py-2 pe-3 text-start tabular-nums">{{ $formatDiff($grandDiff['m' . $m] ?? 0) }}</td>
                    @endfor
                    <td class="py-2 text-start tabular-nums" style="width: 34px; max-width: 34px;">{{ $formatDiff($grandDiff['total_all'] ?? 0) }}</td>
                </tr>
            @endif
        </tfoot>
    </table>
</div>
