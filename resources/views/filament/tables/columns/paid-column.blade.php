@php
    $model = $getModel();
    $paymentMethodLabel = null;
    if ($model && !empty($model->payment_method)) {
        $paymentMethodLabel = $model->payment_method?->getLabel()
            ?? \App\Enums\PaymentMethodType::tryFrom((string) $model->payment_method)?->getLabel()
            ?? (string) $model->payment_method;
    }
    if ($shouldLeaveEmpty()) return;
@endphp
<div
    class="numberPlusDate"
    style="gap: 0"
>
    @if ($model && !empty($model->paid_at))
        <span class="flex items-center gap-1">
            <x-heroicon-o-check class="text-success-500 h-6 w-6" />
            @if (!empty($paymentMethodLabel))
                <span>{{ $paymentMethodLabel }}</span>
            @endif
        </span>
        <span class="text-sm text-gray-500 date">
            {{ $model->paid_at->translatedFormat('j M Y') }}
        </span>
    @elseif ($shouldShowNotApplicable())
        <span class="text-sm text-gray-500">N.v.t.</span>
    @else
        <x-heroicon-o-x-mark
            @class([
                'text-danger-500',
                'h-6 w-6' => $getName() !== 'paid_at',
                'h-5 w-5' => $getName() === 'paid_at',
            ])
        />
    @endif
</div>
