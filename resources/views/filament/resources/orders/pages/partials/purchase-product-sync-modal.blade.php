@if ($showPurchaseProductSyncModal ?? false)
    @teleport('body')
        <div
            class="status-confirm-modal status-confirm-modal--no-close fi-fo-field-wrp"
            role="dialog"
            aria-modal="true"
            aria-labelledby="purchase-product-sync-modal-title"
            wire:key="purchase-product-sync-modal"
        >
            <div
                class="fi-modal-window fi-modal-window-has-footer fi-modal-window-has-icon fi-align-center fi-width-md rounded-xl bg-white shadow-xl dark:bg-white/5 dark:shadow-none"
            >
                <div class="fi-modal-header">
                    <div class="fi-modal-icon-ctn">
                        <div class="fi-modal-icon-bg fi-color fi-color-primary">
                            <svg class="fi-icon fi-size-lg" xmlns="http://www.w3.org/2000/svg" fill="none"
                                 viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                            </svg>
                        </div>
                    </div>
                    <div>
                        @if (! empty($purchaseProductSyncModal['title']))
                            <h2 id="purchase-product-sync-modal-title" class="fi-modal-heading">
                                {{ $purchaseProductSyncModal['title'] }}
                            </h2>
                        @endif
                        @if (! empty($purchaseProductSyncModal['description']))
                            <p class="fi-modal-description">
                                {{ $purchaseProductSyncModal['description'] }}
                            </p>
                        @endif
                        @if (! empty($purchaseProductSyncModal['lines']))
                            <div @class([
                                'space-y-1 text-sm text-gray-600 dark:text-gray-400',
                                'mt-3' => ! empty($purchaseProductSyncModal['title']) || ! empty($purchaseProductSyncModal['description']),
                            ])>
                                @foreach ($purchaseProductSyncModal['lines'] as $line)
                                    <p class="m-0">{!! $line !!}</p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
                <div class="fi-modal-footer fi-align-center">
                    <div class="fi-modal-footer-actions">
                        <x-filament::button
                            wire:click="dismissPurchaseProductSyncModal"
                            class="modal-akkoord-submit"
                        >
                            Akkoord
                        </x-filament::button>
                    </div>
                </div>
            </div>
        </div>
    @endteleport
@endif
