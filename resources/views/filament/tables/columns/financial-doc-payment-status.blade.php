@php
    $record = $getRecord();
    $isArray = is_array($record);
    $paidAt = $isArray ? ($record['payment_status_raw'] ?? null) : ($record->paid_at ?? null);
    $paymentMethodLabel = $isArray ? ($record['payment_method_label'] ?? null) : (
        $record->payment_method instanceof \App\Enums\PaymentMethodType
            ? $record->payment_method->getLabel()
            : (\App\Enums\PaymentMethodType::tryFrom($record->payment_method)?->getLabel())
    );
    $isUpload = $isArray && ($record['_type'] ?? '') === 'upload';
@endphp
<div class="filament-tables-text-column px-4 py-3 padding-8 flex items-center gap-1.5">
    @if ($isUpload || $paidAt === null)
        <span class="text-gray-500">-</span>
    @else
        <x-heroicon-o-check class="text-success-500 h-4 w-4 shrink-0" />
        <span>{{ $paidAt instanceof \Carbon\Carbon ? $paidAt->format('Y-m-d') : \Carbon\Carbon::parse($paidAt)->format('Y-m-d') }}</span>
        @if (filled($paymentMethodLabel))
            <span>{{ $paymentMethodLabel }}</span>
        @endif
    @endif
</div>
