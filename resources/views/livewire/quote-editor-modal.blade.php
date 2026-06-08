<div x-data="{ show: @entangle('showQuoteEditorModal') }" wire:ignore.self>
    <div x-show="show" x-cloak class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center">

        <div class="bg-white p-6 rounded-sm shadow-xl w-full max-w-(--breakpoint-xl) min-w-[1000px] min-h-[400px] relative"
             style="max-height: 90vh; max-width: 1400px; height: 100%;">
            <button
                @click="show = false"
                class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl font-bold"
                aria-label="Sluiten"
                type="button"
                style="font-size: 50px;right: 1rem;top: 1rem;"
            >
                &times;
            </button>


            @if ($product)
                <iframe
                    src="{{ route('product_admin_edit', ['orderProduct' => $product['id'], 't' => time()]) }}"
                    class="w-full h-full mb-4 rounded" frameborder="0"></iframe>
            @endif
        </div>
    </div>
</div>

<script>
    window.addEventListener('message', (event) => {
        if (event.data.type === 'close-product-modal') {
            Livewire.dispatch('closeModalFromIframe', {
                orderProductId: event.data?.orderProductId ?? null,
                productId: event.data?.productId ?? null,
            });
        }
    });
</script>
