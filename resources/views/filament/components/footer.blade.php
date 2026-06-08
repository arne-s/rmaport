<x-filament::modal class="openDocumentModal" id="open-document">
    <div
        class="contentContainer"
        x-bind:class="{
            'contentContainer--quote-preview': quotePreview || orderHtmlPreview,
            'contentContainer--invoice-preview': invoicePreview,
        }"
        x-data="{ orderId: null, mediaId: null, exactDocumentId: null, quotePreview: false, invoicePreview: false, orderHtmlPreview: false, isOpen: false }"
        x-on:open-modal.window="if ($event.detail.id === 'open-document') { orderId = $event.detail.orderId || null; mediaId = $event.detail.mediaId || null; exactDocumentId = $event.detail.exactDocumentId || null; quotePreview = !!$event.detail.quotePreview; invoicePreview = !!$event.detail.invoicePreview; orderHtmlPreview = !!$event.detail.orderHtmlPreview; isOpen = true }"
        x-on:close="orderId = null; mediaId = null; exactDocumentId = null; quotePreview = false; invoicePreview = false; orderHtmlPreview = false; isOpen = false"
        x-show="isOpen"
        x-cloak
    >
        <template x-if="orderId || mediaId || exactDocumentId">
            <iframe
                x-bind:src="exactDocumentId ? '/exact-documents/' + exactDocumentId + '/preview' : (mediaId ? '/media-preview/' + mediaId : (orderId ? (quotePreview ? '{{ url('/documents/quotes') }}/' + orderId + '/admin-preview' : '/documents/' + orderId) : ''))"
                x-bind:key="(exactDocumentId || mediaId || orderId) + '-' + (quotePreview ? 'q' : (orderHtmlPreview ? 'o' : (invoicePreview ? 'i' : 'd')))"
                class="w-full min-h-[85vh] h-[85vh] border-0"
            ></iframe>
        </template>
    </div>
</x-filament::modal>

@include('filament.components.financial-documents-preview-modal')

<x-filament::modal class="openDocumentModal" id="open-packing-slip">
    <div 
        class="contentContainer"
        x-data="{ orderId: null, isOpen: false }"
        x-on:open-modal.window="if ($event.detail.id === 'open-packing-slip') { orderId = $event.detail.orderId; isOpen = true }"
        x-on:close="orderId = null; isOpen = false"
        x-show="isOpen"
        x-cloak
    >
        <template x-if="orderId">
            <iframe 
                x-bind:src="'/packing-slips/' + orderId" 
                x-bind:key="orderId"
                class="w-full h-96"
            ></iframe>
        </template>
    </div>
</x-filament::modal>

<x-filament::modal class="openDocumentModal" id="open-delivery-note">
    <div
        class="contentContainer"
        x-data="{ orderId: null, isOpen: false }"
        x-on:open-modal.window="if ($event.detail.id === 'open-delivery-note') { orderId = $event.detail.orderId; isOpen = true }"
        x-on:close="orderId = null; isOpen = false"
        x-show="isOpen"
        x-cloak
    >
        <template x-if="orderId">
            <iframe
                x-bind:src="'/delivery-notes/' + orderId"
                x-bind:key="orderId"
                class="w-full min-h-[85vh] h-[85vh] border-0"
            ></iframe>
        </template>
    </div>
</x-filament::modal>

<x-filament::modal class="openDocumentModal open-order-margins-modal" id="open-order-margins">
    <div
        class="contentContainer"
        x-data="{
            orderId: null,
            isOpen: false,
            iframeHeight: 280,
            resizeIframe(el) {
                const doc = el?.contentWindow?.document;
                if (! doc?.body) {
                    return;
                }
                const h = doc.documentElement?.scrollHeight ?? doc.body.scrollHeight;
                const maxH = Math.floor(window.innerHeight * 0.85);
                this.iframeHeight = Math.min(Math.max(h + 12, 160), maxH);
            },
        }"
        x-init="$watch('orderId', () => { iframeHeight = 280 })"
        x-on:open-modal.window="if ($event.detail.id === 'open-order-margins') { orderId = $event.detail.orderId; isOpen = true }"
        x-on:close="orderId = null; isOpen = false"
        x-show="isOpen"
        x-cloak
    >
        <template x-if="orderId">
            <iframe
                x-bind:src="'/order-margins/' + orderId"
                x-bind:key="orderId"
                class="order-margins-modal__iframe w-full"
                x-bind:style="'height: ' + iframeHeight + 'px'"
                x-on:load="resizeIframe($event.target)"
            ></iframe>
        </template>
    </div>
</x-filament::modal>

<x-filament::modal class="openDocumentModal" id="open-purchase-order-confirmation">
    <div 
        class="contentContainer"
        x-data="{ confirmationId: null, isOpen: false }"
        x-on:open-modal.window="if ($event.detail.id === 'open-purchase-order-confirmation') { confirmationId = $event.detail.confirmationId; isOpen = true }"
        x-on:close="confirmationId = null; isOpen = false"
        x-show="isOpen"
        x-cloak
    >
        <template x-if="confirmationId">
            <iframe 
                x-bind:src="'/purchase-order-confirmations/' + confirmationId" 
                x-bind:key="confirmationId"
                class="w-full h-96"
            ></iframe>
        </template>
    </div>
</x-filament::modal>

<x-filament::modal class="openDocumentModal" id="open-purchase-order-confirmation-modal">
    <div 
        class="contentContainer"
        x-data="{ purchaseOrderId: null, isOpen: false }"
        x-on:open-modal.window="if ($event.detail.id === 'open-purchase-order-confirmation-modal') { purchaseOrderId = $event.detail.purchaseOrderId; isOpen = true }"
        x-on:close="purchaseOrderId = null; isOpen = false"
        x-show="isOpen"
        x-cloak
    >
        <template x-if="purchaseOrderId">
            <iframe 
                x-bind:src="'/purchase-order-confirmation-modal/' + purchaseOrderId" 
                x-bind:key="purchaseOrderId"
                class="w-full h-96"
            ></iframe>
        </template>
    </div>
</x-filament::modal>

<x-filament::modal class="openDocumentModal" id="open-purchase-order-document">
    <div
        class="contentContainer contentContainer--quote-preview"
        x-data="{ purchaseOrderId: null, isOpen: false }"
        x-on:open-modal.window="if ($event.detail.id === 'open-purchase-order-document') { purchaseOrderId = $event.detail.purchaseOrderId; isOpen = true }"
        x-on:close="purchaseOrderId = null; isOpen = false"
        x-show="isOpen"
        x-cloak
    >
        <template x-if="purchaseOrderId">
            <iframe
                x-bind:src="'{{ url('/documents/purchase-orders') }}/' + purchaseOrderId"
                x-bind:key="purchaseOrderId"
                class="w-full min-h-[85vh] h-[85vh] border-0"
            ></iframe>
        </template>
    </div>
</x-filament::modal>

<x-filament::modal class="openDocumentModal" id="open-purchase-order-invoice">
    <div
        class="contentContainer"
        x-data="{ invoiceId: null, isOpen: false }"
        x-on:open-modal.window="if ($event.detail.id === 'open-purchase-order-invoice') { invoiceId = $event.detail.invoiceId ?? null; isOpen = true; }"
        x-on:close-modal.window="if ($event.detail.id === 'open-purchase-order-invoice') { invoiceId = null; isOpen = false; }"
        x-on:close="invoiceId = null; isOpen = false"
        x-show="isOpen"
        x-cloak
    >
        <template x-if="invoiceId">
            <iframe
                x-bind:src="'/purchase-order-invoices/' + invoiceId"
                x-bind:key="invoiceId"
                class="w-full min-h-[85vh] h-[85vh] border-0"
            ></iframe>
        </template>
    </div>
</x-filament::modal>
