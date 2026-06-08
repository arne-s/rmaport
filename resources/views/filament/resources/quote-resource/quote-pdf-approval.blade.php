@php
    /** @var \App\Models\Order\Quote $quote */
    $approvalRow = $quote->relationLoaded('quoteApproval') ? $quote->quoteApproval : $quote->quoteApproval()->with('approvedByUser')->first();
    $latestApproval = $approvalRow?->approved_at ? $approvalRow : null;
    $previewSrc = route('documents.show', ['orderId' => $quote->getId()]);
@endphp

<div class="quote-admin-preview-root">
    <div class="quote-admin-preview-inner">
        <div class="quote-admin-preview-iframe-wrap">
            <iframe
                title="Offerte PDF"
                src="{{ $previewSrc }}"
                class="quote-admin-preview-iframe"
            ></iframe>
        </div>

        @if($latestApproval)
            <div class="quote-admin-preview-approval">
                <h4>Goedkeuring</h4>

                @if($latestApproval->approvedByUser)
                    {{-- Internal: customer_name on the row is billing context, not an external sign-off. --}}
                    <div class="quote-approval-internal">
                        <p class="font-medium">Intern</p>
                        <p>Goedgekeurd door: {{ $latestApproval->approvedByUser->getName() }}</p>
                        @if($latestApproval->approved_at)
                            <p>Op: {{ $latestApproval->approved_at->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</p>
                        @endif
                    </div>

                    @if($latestApproval->signature)
                        <div style="margin-top: 8px;">
                            <p class="quote-approval-sig-label">Handtekening</p>
                            <img src="{{ $latestApproval->signature }}" alt="Handtekening" class="quote-approval-sig">
                        </div>
                    @endif
                @else
                    <div>
                        <p><span class="font-medium">Klant (extern):</span> {{ $latestApproval->customer_name }}</p>
                        <p><span class="font-medium">Getekend op:</span> {{ $latestApproval->approved_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') }}</p>
                    </div>

                    @if($latestApproval->signature)
                        <div style="margin-top: 8px;">
                            <p class="quote-approval-sig-label">Handtekening</p>
                            <img src="{{ $latestApproval->signature }}" alt="Handtekening" class="quote-approval-sig">
                        </div>
                    @endif
                @endif
            </div>
        @endif
    </div>
</div>
