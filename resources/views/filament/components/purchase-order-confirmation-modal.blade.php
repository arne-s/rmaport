@php
    use App\Models\PurchaseOrder;

    $purchaseOrder = PurchaseOrder::find($id);
    $confirmations = $purchaseOrder->confirmations()
        ->orderBy('created_at', 'desc')
        ->get();
@endphp

<x-filament-panels::layout.base>
    <div x-data="{ selectedDocument: {{ $confirmations->first()->id ?? 'null' }} }">
        <select
            x-model="selectedDocument"
            style="margin: 0 10px 20px 0; {!! count($confirmations) > 1 ? '' : 'visibility:hidden' !!}"
        >
            @foreach ($confirmations as $confirmation)
                <option value="{{ $confirmation->id }}">{{ $confirmation->created_at->translatedFormat('j M Y H:i') }}</option>
            @endforeach
        </select>


        @foreach ($confirmations as $confirmation)
            <span  x-show="selectedDocument == {{ $confirmation->id }}" x-cloak>
                Verwacht levermoment: {{ $confirmation->expected_delivery_date?->translatedFormat('\W\e\e\k W, Y') }}
            </span>

            <div x-show="selectedDocument == {{ $confirmation->id }}" x-cloak class="fi-ta-actions">
                <iframe x-bind:src="'/purchase-order-confirmations/' + selectedDocument"></iframe>

                <a
                    href="/purchase-order-confirmations/{{ $confirmation->id }}/download"
                    class="button-primary font-medium gap-0.5 inline-flex items-center justify-center outline-hidden text-primary-600 text-sm"
                >
                    @svg('heroicon-o-arrow-down-tray', ['class' => 'filament-link-icon w-4 h-4 mr-1 rtl:ml-1'])
                    Opslaan als PDF
                </a>
            </div>
        @endforeach
    </div>
</x-filament-panels::layout.base>

<style>
    body {
        background-color: transparent !important;
    }

    iframe {
        width: 100%;
        height: 78vh;
        border: none;
    }

    div.fi-ta-actions {
        flex-direction: column;
        align-items: start;
    }

    html.filament div.fi-ta-actions a.button-primary {
        width: fit-content !important;
        margin-top: 20px !important;
    }
</style>
